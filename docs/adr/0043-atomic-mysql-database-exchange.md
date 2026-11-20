# 0043 · Exchange reviewed WordPress database generations atomically

Accepted 2026-11-20.

## Context

A WordPress restore can span hundreds of requests. A disconnected request may have
already committed its last SQL statement. A user may also edit posts, change credentials
or install an extension between a rehearsal and the final confirmation. A database undo
must identify both the old and current physical tables and preserve newer writes.

## Compatibility

The native exchange requires a single-site WordPress installation, its standard mysqli
database adapter and local users and user metadata tables. External persistent object
caches require a separate recovery procedure. The target must allow the WordPress
maintenance marker on the same filesystem as the private job directory.

The destination must be a writable MySQL server with atomic InnoDB DDL, support for
renaming tables held with WRITE locks (MySQL 8.0.13 or later), a server UUID,
case-sensitive table names and enabled InnoDB table locks. Source and live tables must
have names the plugin can match exactly to the InnoDB dictionary. Up to 128 tables can
participate. The source prefix must equal the local WordPress prefix. Both generations
must contain compatible options, users and user metadata tables and the same WordPress
database schema version. Views in the source, non-InnoDB source engines, CHECK
constraints, triggers and incoming or outgoing foreign keys on affected tables require a
manual restore. A backup made without verified source trigger visibility also needs the
manual path. Destination views that use affected tables block the exchange when visible
to the WordPress account. Installations sharing tables with external applications or
views outside that account's visibility need a database administrator to plan recovery.
[MySQL view dependency visibility](https://dev.mysql.com/doc/refman/8.4/en/information-schema-view-table-usage-table.html).

The preflight verifies database-wide SELECT, INSERT, UPDATE, DELETE, CREATE, DROP,
ALTER, LOCK TABLES and TRIGGER privileges. It also verifies dictionary access requiring
global PROCESS. PROCESS exposes server-wide metadata, so a shared host may reserve it
for its database administrators. Current verification recognizes direct database grants
and applicable direct global grants. Global grants alone cannot establish schema access
when partial revokes are enabled. MariaDB 11.4.13 rejected the locked RENAME with error
1192. The plugin supports the rehearsal on MariaDB and directs live recovery to the
manual SQL procedure.

## Decision

1. The rehearsal imports checked base tables into a random, private namespace. Each row
   batch commits with its cursor and an ordered digest of the original SQL bytes. The
   report binds the table transcripts to the exact manifest and snapshot. WordPress
   stores the report digest in the successful restore job.
2. The review checks the current destination and describes every table it will replace,
   create or keep. The confirmation binds the restore, report, physical table IDs, schema
   digests, selected administrator and that administrator's current credentials and
   database-backed permissions. WordPress verifies the current password, typed home URL
   and user/session-bound review proof. The current user must have the configured backup
   capability, manage_options, edit_users, promote_users and update_core.
3. A separate apply job imports again with a new token. Its private job document exists
   before the first SQL mutation. The completed import must reproduce the reviewed
   transcript. The job records each original and shadow InnoDB TABLE_ID, schema digest,
   database, MySQL server UUID and ownership witness. The worker restores each shadow's
   original table comment and verifies that the native ID survived.
4. WordPress enters maintenance for the final exchange. The worker sets bounded metadata
   and InnoDB lock timeouts, obtains WRITE locks on every existing source and the
   witness, then confirms the identities, schemas, permissions and dependencies again.
   It refreshes protected WordPress state in the destination, commits that copy, and
   moves every table plus the witness in one RENAME TABLE. The witness distinguishes a
   committed rename from a pending one after a lost reply. WordPress then flushes its
   local object cache and ensures the heartbeat is scheduled.
5. The protected records include the current home and site addresses, active plugins,
   plugin options and transients, cron array, WordPress roles, and the approving user's
   full record and user metadata including session tokens. The account's ID, login or
   email conflicting in the destination stops the job. The SQL copy preserves the user ID
   and allocates local option and user-meta row IDs. A limit of 4,096 rows per protected
   source or destination set bounds the write-lock interval.
6. A confirmed undo uses the same exchange protocol and rechecks the current
   administrator. The original generation stays available for 24 hours after apply.
   Undo brings that generation live and retains the entire generation it replaced for
   another 24 hours. This preserves posts, accounts and settings changed since the apply
   for selective recovery by a database administrator. The two generations are never
   merged automatically. A deadline applies from the final exchange attempt, including
   after a lost response.
7. Heartbeat expiry checks the private name and native ID under a write lock before
   each DROP, checks at most eight objects per cleanup call, and removes the witness
   last. An interrupted window keeps its owner document through history pruning. Staging
   is pinned while an apply uses it and can expire independently afterwards. All-tables
   backups exclude verified physical tables retained by this plugin. Uninstall cleans
   owned generations before deleting their private job records.

## Evidence

The database suite exercises actual MySQL and MariaDB. MySQL tests cover a concurrent
writer queued on a live name, worker processes killed immediately before and after RENAME, changed native
identities, changed administrator authority, original table comments, protected
WordPress state, post-apply writes retained after undo and expiry replay. The job tests
exercise the normal filesystem store, timeout, confirmation digest, staging pin, history
pruning and recovery windows. A local real WordPress 7.1.4, PHP 8.4 and MySQL 8.4.11
restore also verified authentication state, addresses, active plugins and the heartbeat.

The server behavior is described in the MySQL manual. [RENAME TABLE](https://dev.mysql.com/doc/refman/8.4/en/rename-table.html), [LOCK TABLES](https://dev.mysql.com/doc/refman/8.4/en/lock-tables.html), [atomic DDL](https://dev.mysql.com/doc/refman/8.4/en/atomic-ddl.html).
