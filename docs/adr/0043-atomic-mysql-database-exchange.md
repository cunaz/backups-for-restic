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
case-sensitive table names and enabled InnoDB table locks. Its Performance Schema must list
every session of the server (`performance_schema.processlist`, new in
[MySQL 8.0.22](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/news-8-0-22.html), with
`performance_schema` enabled and enough thread instances for every connection). Binary logging, where it is on for the session, must not use
the statement format, and the server must not be a Galera node. Source and live tables must
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
ALTER, LOCK TABLES and TRIGGER privileges. If the current database contains a view,
it also requires SHOW VIEW to inspect dependencies created by another account. Overlapping
database grant patterns establish only the rights shared by every matching pattern.
[MySQL database grants](https://dev.mysql.com/doc/refman/8.4/en/grant.html).

The preflight also verifies dictionary access requiring
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
4. WordPress enters maintenance for the final exchange. The worker waits until the requests
   that started before maintenance have ended (see "Requests from before maintenance"
   below). It then sets bounded metadata
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
   administrator. It binds to the physical tables observed at its review, which a rebuild
   may have renumbered since the apply (see "Table rebuilds" below). The original generation stays available for 24 hours after apply.
   Undo brings that generation live and retains the entire generation it replaced for
   another 24 hours. This preserves posts, accounts and settings changed since the apply
   for selective recovery by a database administrator. The two generations are never
   merged automatically. A deadline applies from the final exchange attempt, including
   after a lost response.
7. Heartbeat expiry checks the private name and native ID under a write lock before
   each DROP, checks at most eight objects per cleanup call, and removes the witness
   last. A rebuilt private table is kept and reported. A generation whose cleanup fails
   does not hold up the others. An interrupted window keeps its owner document through
   history pruning. Staging
   is pinned while an apply uses it and can expire independently afterwards. All-tables
   backups exclude verified physical tables retained by this plugin. Uninstall cleans
   owned generations before deleting their private job records.

## As built after the review of 0.3.0-rc.1

### Requests from before maintenance

WordPress checks its maintenance marker once per request, in `wp-settings.php` before it
loads the database class. A request that passed the check before the marker existed keeps
running. Its statements that wait for the exchange's table locks would run after
`UNLOCK TABLES` against the other generation: a post or an order inserted before the lock
would get its metadata in the restored tables, where its parent does not exist, and a later
row there could reuse its ID.

After entering maintenance, `Database\Restore\SessionDrain` waits two seconds, which covers
the step from WordPress's check to its connection. It then lists the other sessions of the
site's account whose current database is the site's (`performance_schema.processlist`,
columns `USER` and `DB`) five times a second, and the exchange starts only after that list
has stayed empty for one second, the quiet interval. Waiting only for the sessions of a
first listing would miss a request from before maintenance that loses its session, for
example to a proxy or a server timeout, and reconnects under a new connection ID: within
the quiet interval its new session is listed, and the interval starts again. The requests
that the maintenance gate admits on purpose are waited for the same way, because the server
cannot tell them apart: the plugin's cron events, the administration page and its status
poll, which follows three seconds after each answer, the job's control requests, and
WP-CLI. The tick's own WordPress session is the one that takes the locks and is not listed.
The job saves its swap marker, and for an undo the owner's record, before the wait, so
that only a few statements on that session separate the last empty listing from the table
locks.

The list comes from the Performance Schema because MySQL 8.4 raises deprecation warning
1287 for [`INFORMATION_SCHEMA.PROCESSLIST`](https://dev.mysql.com/doc/refman/8.4/en/information-schema-processlist-table.html),
also behind `SHOW PROCESSLIST`, and the restore port refuses warnings.
[`performance_schema.processlist`](https://dev.mysql.com/doc/refman/8.4/en/performance-schema-processlist-table.html)
gives a non-anonymous account its own sessions without PROCESS. On MySQL 8.4.11 with the
Performance Schema disabled it listed nothing, not even the asking session, so the preflight
and every listing require that session to be listed with the site's database (blocker and
code `performance_schema`).

An empty list must also be a complete one. A server that has run out of Performance Schema
thread instances (`performance_schema_max_thread_instances`) leaves further sessions out of
`performance_schema.processlist` for every observer, while `Threads_connected` counts them.
On MySQL 8.4.11 with the limit at 44, five client sessions were connected and three listed:
each listed session saw the other two listed ones and nothing of the remaining two, which
did not see their own rows either. With the limit at 8, none of six sessions was listed.
Neither `setup_actors` nor `setup_threads` hid a session; they change only what is
instrumented. `Performance_schema_thread_instances_lost` cannot prove completeness, because
`FLUSH STATUS` resets it to zero. So every listing compares `Threads_connected` from
`performance_schema.global_status` with the number of listed sessions that are not daemon
threads, and uses the list only when at least as many are listed as are connected; three
readings allow for a connection that opens or closes between the two counts. The comparison
runs before the account's sessions are read and again afterwards: a session that connected
without a thread instance in between is missing from the list, is still connected, and so
shows in the second comparison. Counting the
sessions of other accounts needs PROCESS, which the exchange already needs for the InnoDB
dictionary; without it the preflight reports `dictionary_privilege`. A list that cannot be
shown complete never counts as empty: the preflight reports `performance_schema`, and a wait
that ends with an incomplete list stops the job with that code, which is not retried,
because it names a server setting.
The listing leaves out this session by its connection ID: "Every connection has an ID that
is unique among the set of currently connected clients"
([CONNECTION_ID()](https://dev.mysql.com/doc/refman/8.4/en/information-functions.html)).
The wait compares no other IDs; it only asks whether any other session is listed.

The bound: the wait ends with the tick, after at most 60 seconds and at least 5 seconds even
late in a tick. A tick with less than seven seconds left first defers the swap once to a
fresh tick, before it enters maintenance. If the account has not been quiet for the
interval by then, the job leaves maintenance and fails the step with the retryable code
`database_exchange_sessions`; the
import is kept, and the runner tries again after 30 seconds, doubling, at most five times.
One maintenance window therefore lasts at most about 62 seconds plus the exchange, whose
statements each wait at most three seconds for a lock.

What remains: a request that needs more than two seconds from WordPress's maintenance check
to its first query is not waited for, nor one that stays without a session for longer than
the quiet interval before it reconnects, for example after the server closed its idle
connection, nor a session of the account without a default database. A session that opens
and closes between two listings, within 0.2 seconds, is not seen either. Between the last
empty listing and LOCK TABLES lie a few statements on the exchange's session, a few
milliseconds in which a new session is not seen. Clients that do not stop at the marker,
WP-CLI among them, are waited for only while their sessions are listed; other database
accounts and applications with accounts of their own are not covered. Persistent
connections, long-running WP-CLI processes, another application that uses the same account
and open pages of the plugin whose polls leave no quiet second keep the account busy; the
exchange then refuses until they end.

### A binding of another server or database

`DatabaseGeneration::load()` still refuses a server that does not hold the private tables.
It now reports a different valid server UUID, a MariaDB server or another database name with
the code `database_exchange_foreign`. A server without a readable UUID cannot be told apart
from the original one and counts as unreachable, as before.

- The job's cleanup and the heartbeat record such a binding once on its job
  (`database_foreign`) with what to do: run the discard command below, which shows the
  database, the server UUID and the table names and drops nothing, and drop the tables by
  hand only where they can be verified. Backups and all other jobs start again; only a new
  database apply waits, in `JobStore::create()` and as the review blocker
  `database_exchange_foreign`. A binding whose server answers again is cleaned up as usual.
- `wp backups-for-restic database-discard <apply-job>` and
  `POST /database-changes/{id}/discard`, with the WordPress password in the JSON body, forget
  a binding that the connected server positively disowns. Both name the database, the server
  UUID and every private table name the binding can own (`_p`, `_a`, `_u`, and `_t<n>` and
  `_b<n>` per table), record the decision in the job (`database_discarded`) and drop
  nothing. None of these names starts with the site's prefix; a database administrator drops
  those that exist on the server that holds them.
- Uninstall forgets such a binding without dropping anything: its tables are not on this
  server, and a table here with one of its names cannot be proven to be its copy. Private
  tables elsewhere keep names that begin with `_bfr_r_` or `bfr_r_`.
- An all-tables backup excludes only tables it can prove to be private copies. For such a
  binding it excludes nothing, so tables that a migration copied under these names stay in
  the backup. An unfinished import of this server excludes the shadows and the ledger that
  still carry its ownership comment.

### Lost replies

After a lost reply to the swap's RENAME, WriteBarrier closes the session. The cleanup of the
same request no longer reads the outcome through the closed handle; it reconciles in a later
tick on a new session. While the outcome cannot be read, because the server is unreachable
or the old session still holds its advisory lock, the cleanup waits for at most ten minutes,
then ends the job. The WordPress connection that the restore port closed is replaced by a
new one before its next use, also right after the loss, so the rest of the request and a
WP-CLI process that runs several ticks go on. Nothing continues the old session's work.

An undo saves its attempt and timestamp on the owning apply before its RENAME
(`database_undo_attempt`). The heartbeat recognises a committed undo from it, as it
recognises a committed apply from the apply's own timestamp, records the 24-hour window from
the attempt and notes the recovered outcome on the undo job if that still exists.

### Table rebuilds

On MySQL 8.4.11 these operations assigned a table a new `TABLE_ID`: `OPTIMIZE TABLE`, which
InnoDB maps to `ALTER TABLE ... FORCE` ([OPTIMIZE TABLE](https://dev.mysql.com/doc/refman/8.4/en/optimize-table.html)),
`ALTER TABLE ... FORCE`, `ALTER TABLE ... ENGINE=InnoDB`, `TRUNCATE TABLE`, a column type
change, `CONVERT TO CHARACTER SET`, a `ROW_FORMAT` change and an `ALTER` that rebuilds in
place, such as `ADD COLUMN ... ALGORITHM=INPLACE`. These kept it: a comment change,
`ADD COLUMN` with the default instant algorithm, `ADD INDEX`, `ANALYZE TABLE`, an
`AUTO_INCREMENT` change, `RENAME TABLE` and `ALTER TABLE ... RENAME`. The name, definition
and comment survive a rebuild; `TRUNCATE` also removes the rows.

- The undo review binds the undo to the tables that hold the applied generation's names
  now, as long as every retained table and the witness are the recorded ones. The
  confirmation digest covers the observed identities. The undo job keeps them and saves
  them on the owning apply before its RENAME, so the generation it replaces expires under
  its new identities.
- A witness whose recorded identity is gone from all three witness names is recognised when
  exactly one of them holds a table with this operation's ownership comment. Cleanup saves
  the new identity in the plan and reports `database_exchange_rebuilt`. The comment binds the
  table to the random token: the same proof that lets cleanup drop the shadows of an
  unfinished import.
- A rebuilt retained table or shadow cannot be proven to be this operation's copy. The undo
  review refuses with `database_exchange_rebuilt`. Expiry keeps such a table, records its
  name on the job (`database_rebuilt`) with a warning, and drops the tables it can prove as
  before. It looks when it starts and again after its last DROP, before it forgets the
  binding, so a table rebuilt while expiry runs over several ticks is named as well.
- An apply still binds to the live identities confirmed at its review: a live table rebuilt
  during the import makes the apply refuse, and the administrator reviews it again.

### Preflight settings and packet size

The preflight reports `innodb_table_locks` when LOCK TABLES takes no InnoDB table locks,
`binlog_format` when binary logging is on for the session in the statement format, and
`wsrep_on` on a MySQL-family Galera node, which takes LOCK TABLES for itself only. With
statement-based logging MySQL 8.4.11 returned note 1592 for the protected copy's
`INSERT ... SELECT` into AUTO_INCREMENT tables, which the restore port refuses. The values
come from `SHOW SESSION VARIABLES`, because reading `@@binlog_format` raises a deprecation
warning. Expiry needs InnoDB table locks as well: it drops each private table under the
exchange's write barrier, which locks the table, checks its identity and refuses to start
without them. One barrier stays the locking contract for every DDL on private tables; a
generation exists only if the setting was on at the exchange, and when it is switched off
later, the heartbeat retries and the job log names it. Expiry depends neither on the
binary log format nor on Galera or the Performance Schema (`SwapCatalog::EXCHANGE_ONLY`).

The importer reads the session's `max_allowed_packet` once per tick and refuses a batch that
would not fit before sending it (`database_restore_packet`, ADR 0035). The refusal names the
batch's SQL size, the limit it needs and the session's limit. The needed limit is a multiple
of 1024, because MySQL 8.4.11 rounded a larger value down to one without a warning.

### Several generations

Expiry visits the owning jobs newest first. A generation whose cleanup fails is reported on
its job, and the older ones are still expired; the first failure is raised after every due
generation had its turn.

## Evidence

The database suite exercises actual MySQL and MariaDB. MySQL tests cover a concurrent
writer queued on a live name, worker processes killed immediately before and after RENAME, changed native
identities, changed administrator authority, original table comments, protected
WordPress state, post-apply writes retained after undo and expiry replay. The job tests
exercise the normal filesystem store, timeout, confirmation digest, staging pin, history
pruning and recovery windows. A local real WordPress 7.1.4, PHP 8.4 and MySQL 8.4.11
restore also verified authentication state, addresses, active plugins and the heartbeat.
After the review of 0.3.0-rc.1 the MySQL tests also cover: a second session that keeps a
unit of work open across the swap, the same with a reconnect during maintenance, and one
that outlasts the wait; a binding with another
server UUID during an apply, with a backup, a review, the discard and uninstall; a lost reply
to the undo's RENAME followed by cleanup in the same request, a later tick and the heartbeat;
`OPTIMIZE TABLE` on a live table, on the witness and on a retained table inside the window;
a stuck newer generation in front of an expired older one; statement-based logging and
disabled InnoDB table locks, for the exchange and for expiry; and a batch beyond
`max_allowed_packet`. Unit tests cover the
drain's grace period, quiet interval and bounds on an injected clock, the session listing
and its completeness check, the setting blockers, the plan's
validation and rebinding, and the job-level handling of foreign bindings and lost sessions.
A separate MySQL 8.4.11 account restricted to the privileges listed above rehearsed,
applied, undid and expired its own private tables; without PROCESS its session list could
not be shown complete. By hand, a MySQL 8.4.11 server with
`performance_schema_max_thread_instances` at 8 and at 44 left sessions out of the list as
described above. Removing PROCESS or withholding
SHOW VIEW on a schema containing a view produced specific preflight blockers.

The server behavior is described in the MySQL manual. [RENAME TABLE](https://dev.mysql.com/doc/refman/8.4/en/rename-table.html), [LOCK TABLES](https://dev.mysql.com/doc/refman/8.4/en/lock-tables.html), [atomic DDL](https://dev.mysql.com/doc/refman/8.4/en/atomic-ddl.html).
