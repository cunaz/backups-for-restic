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
   create or keep. It also says what the exchange keeps from the running site and how the
   roles and active plugins of the two generations differ (decision 5). The confirmation
   binds the restore, report, physical table IDs, schema digests, selected administrator,
   that administrator's current credentials and database-backed permissions, the live list
   of active plugins and those differences. WordPress verifies the current password, typed
   home URL and user/session-bound review proof. The current user must have the configured
   backup capability, manage_options, edit_users, promote_users and update_core.
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
   local object cache, reads the roles again and ensures the heartbeat is scheduled.
5. The protected records are the current home and site addresses, the WordPress database
   version, which both generations must share, WP-Cron's lock (the `doing_cron` transient
   and its timeout), this plugin's options and transients, and the approving user's full
   record and user metadata including session tokens. The roles, the list of active plugins
   and the cron array are those of the generation that becomes live: the backup's for an
   apply, the earlier generation's for an undo. That list gets this plugin's entry if it
   lacks it, and under that generation's roles the approving user's own roles and
   capabilities must still give the five capabilities of decision 2 (amended 2026-11-23,
   see "Roles, active plugins and scheduled events" below; until then the running site's
   roles, active plugins and cron array were protected records as well). The account's ID,
   login or email conflicting in the destination stops the job. The SQL copy preserves the
   user ID and allocates local option and user-meta row IDs. A limit of 4,096 rows per
   protected source or destination set bounds the write-lock interval.
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
  (`database_foreign`) with what to do: review its discard on the Restores tab or with the
  command below, which show the database, the server UUID and the table names and drop
  nothing, and drop the tables by hand only where they can be verified. Backups and all other
  jobs start again; only a new database apply waits, in `JobStore::create()` and as the review
  blocker `database_exchange_foreign`. A binding whose server answers again is cleaned up as
  usual.
- A discard forgets a binding that the connected server positively disowns, after a review
  and a confirmation like those of an undo (below), on the Restores tab, through
  `POST /database-changes/{id}/discard` or with `wp backups-for-restic database-discard
  <apply-job>`. The review names the database, the server UUID and every private table name
  the binding can own (`_p`, `_a`, `_u`, and `_t<n>` and `_b<n>` per table); the discard
  records the decision in the job (`database_discarded`, and the table names in its warning,
  which takes the place of the last warning in a job that already holds the 50 it keeps)
  and drops nothing. None of these names starts with the site's prefix; a database
  administrator drops those that exist on the server that holds them.
- Uninstall forgets such a binding without dropping anything: its tables are not on this
  server, and a table here with one of its names cannot be proven to be its copy. Private
  tables elsewhere keep names that begin with `_bfr_r_` or `bfr_r_`.
- An all-tables backup excludes only tables it can prove to be private copies. For such a
  binding it excludes nothing, so tables that a migration copied under these names stay in
  the backup. An unfinished import of this server excludes the shadows and the ledger that
  still carry its ownership comment.

### The review and confirmation of a discard

Until 2026-11-26 the discard was confirmed with the WordPress password alone through the REST
route, and with a prompt or `--yes` in WP-CLI: no review bound the decision to what the
administrator had seen, no site address was typed, and no route told the admin page which
binding exists and which tables it names, so the page had no control for it (reported with
pull request 58). An administrator without shell access whose site had moved to another
database could not get rid of the binding. Since then:

- `GET /database-changes/foreign` lists the finished database applies whose binding the
  connected server positively disowns, each asked about as its review asks. Where the server
  cannot be asked or identified, it lists those whose job recorded `database_foreign` and
  says that the list may be incomplete (`complete: false`).
- `POST /database-changes/{id}/discard-review` (`DatabaseChanges::discard_review()`) says what
  a discard forgets and what stays: the job and its restore, the database and server UUID that
  the binding names, the database and server UUID that the site uses now (`connected`), the
  private table names, the generation and the end of the window that the job recorded,
  whether the undo of that exchange ends with the discard (`undo_until`: an undo still in its
  window, which the site could review again once it uses the database that holds the tables),
  and what stays (`kept`: the database that the site uses, whose tables the discard does not
  touch; the private tables wherever they are; the job and its log). Nothing is asked of the
  database beyond its identity. The digest is built as the exchange's
  (`DatabaseReview::digest()`, whose version 2 the exchange and its undo keep): version 1 of
  this review, the kind `discard`, the confirming user and everything that the review shows.
  The answer carries a review token bound to the session, the site address, the operation
  `discard`, the job and the digest.
- The confirmation is that of an undo (`RestRoutes::confirmed_plan()`): `reviewed`, the typed
  `site_url`, the digest and the review token, and last the WordPress password through the one
  throttled check. The route needs `database_writes_allowed`, as the undo does, and takes the
  runner lock. Under it and under staging's guard the service makes the review again, and a
  digest that is not the current review's is refused with `database_discard_changed`: a
  binding whose record changed, a site that moved on to another database, an undo window that
  ended or a review of another user is not discarded. The job records who confirmed it and
  how (`database_discarded`), its log line names the digest, and its warning the tables; the
  limit of a job's warnings does not drop that warning.
- The names of the tables that are left to remove by hand stay available to the
  administrator after the discard, whatever the job history does: for 90 days
  (`JobStore::DISCARD_LISTED_DAYS`, counted from the discard's own time) or until the
  administrator marks them as taken care of (`POST /database-changes/{id}/discarded-done`,
  `wp backups-for-restic database-discarded <apply-job> --done`, which record who did and when
  under `database_discarded.done`). Until then `JobStore::prune()` keeps the job, and the
  Restores tab lists the binding under "Discarded bindings" with its names, from the answer of
  `GET /database-changes/foreign` (`discarded`), as `wp backups-for-restic database-discarded`
  prints them. The job is kept rather than the names copied into a record of their own,
  because the discard then stays one atomic write of one document: a second record could be
  lost after the binding was forgotten, or written for a discard that did not happen, and it
  would need a cleanup of its own. The texts that send the administrator to that job's log
  point to something that can be opened: its card, and the card of the review, have "Show the
  log of this job", and WP-CLI names `wp backups-for-restic logs`; the Logs tab lists only the
  latest jobs. After a lost answer to a confirmation the page reads the lists again and shows
  the result of a discard that the server recorded. Uninstall forgets the listing with every
  job.
- A binding that cannot be discarded is answered with what is the case:
  `database_binding_missing` (no database apply with a binding of this ID), `job_unreadable`,
  `database_binding_discarded`, `database_binding_busy` (the job or an undo of it has not
  ended), `database_binding_verified` (the connected database holds the tables),
  `database_binding_unverified` (a server without a server UUID, which cannot be told apart
  from the original one) and `database_binding_unreadable` (a damaged record). The discard has
  no job of its own: a database failure during its review or the discard itself is written to
  the log of the job that holds the binding and answered with `database_discard_failed`, which
  points there. Until 2026-11-26 such a failure was answered with the text of a failed backup,
  "The log of the job contains the database error". A private directory whose jobs cannot be
  read, or in which the decision cannot be recorded, is answered with
  `database_discard_storage`, a runner lock held by another request with
  `database_discard_busy`. Any other failure is unexpected: nothing records it, and
  `database_discard_unexpected` says so and that WP-CLI prints its details, where it is
  appended to the text.
- WP-CLI prints the same review with its digest and asks for the site address on a terminal; it
  refuses where there is no terminal to ask, after the review, which gives a script its digest.
  A script confirms with both `--site-url` and `--digest`, the digest of a review that an
  earlier run printed: `--site-url` without `--digest`, and a `--digest` without a value, empty
  or not 64 lower-case hexadecimal characters, are refused before the review, so a script whose
  digest variable came out empty does not confirm the review of the same run. Both are declared
  with an optional value, so that WP-CLI hands over a bare flag instead of dropping it with a
  warning. It does not ask for the WordPress password: whoever runs it can read and change the
  site's files. A WordPress user selected with `--user` needs the capabilities of
  `database_writes_allowed`. `--yes`, which skipped the only confirmation before, is refused with
  an error that says so.
- The admin page lists these bindings on the Restores tab under "Private tables on another
  database server", and the card of an undo whose review answers `database_exchange_foreign`
  offers the same review. Every name is shown as text, escaped where it does not print.
- The discard needs the capabilities of an undo, `update_core` among them, and WordPress gives
  that capability to no account under `DISALLOW_FILE_MODS` or a `file_mod_allowed` filter that
  refuses it. The guard stays as strong as the undo's. The review and the list say why an
  account may not confirm (`write_lacking`, `file_mods_disallowed`), and the page says it in
  its words and names the WP-CLI command, which needs neither when it runs without `--user`.

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

### Roles, active plugins and scheduled events

Until 2026-11-23 the exchange copied the running site's role map (`{prefix}user_roles`), its
list of active plugins and its cron array into the generation that it brought live. A restore
of a clean backup therefore kept what had changed on the site since the backup: a
capability given to the subscriber role after the backup reached every restored subscriber,
and a plugin activated since stayed active. The review did not show it, and the confirmation
said only that the site address, the administrator's account and sessions and the backup
configuration were kept (finding B1-5 of the review of 2026-11-20). A restore brings back
what the backup holds. The exchange now keeps from the running site only what keeps the site
reachable, the approving administrator in control and this plugin able to finish, report and
undo the exchange:

- `home` and `siteurl`, so that the site answers at its address;
- `db_version`, which both generations must already share (decision 5);
- `_transient_doing_cron` and its timeout: the WP-Cron request that runs the exchange holds
  this lock, and `wp-cron.php` compares it after every event;
- this plugin's options and transients: its settings, its encrypted secrets and its state;
- the approving administrator's user row and all of their user meta, session tokens and their
  own roles and capabilities included.

The roles, the active plugins and the scheduled events stay those of the generation that
becomes live. The three reasons why they were copied are kept as follows.

- **This plugin stays active.** Its job has steps after the RENAME, and its heartbeat expires
  the retained generations and offers the undo. If the generation's list of active plugins
  lacks this plugin's path, as that of a backup made before the plugin was installed does,
  the path is appended to it. That happens on the private target before the exchange takes
  its write barrier over the live tables, under a write lock on that one private table and
  after its identity check, and again on every attempt; a list that has the path is not
  rewritten. A list that the path would take beyond the bound below is not written either:
  it could not be read back, so the review refuses it with `database_plugins_too_large`, and
  so does the exchange before it writes (found in the automated review of pull request 59).
  Under the barrier the exchange checks that the list holds the path
  (`database_plugins_invalid` otherwise). The path is the one that `activate_plugin()`
  stores, `plugin_basename()` of the main file, and only for a copy that WordPress loads from
  the plugins directory; a copy loaded otherwise, for example as a must-use plugin, adds
  nothing. Network activation does not occur: the exchange refuses multisite installations
  (blocker `wordpress_environment`), so `active_sitewide_plugins` never takes part.
- **The heartbeat stays scheduled.** After the exchange WordPress flushes its object cache and
  reads the generation's cron array from the database; `Cron::ensure_heartbeat()` schedules
  the heartbeat if the array lacks it (`Container::after_exchange()`), and it runs again on
  every `init`. A backup's array without the heartbeat gets it; the integration suite checks
  this on a real WordPress with the database changed under its cache.
- **The approving administrator stays in control.** Their own roles and capabilities
  (`{prefix}capabilities`) are kept. Under the roles of the generation that becomes live they
  must still give the configured backup capability, `manage_options`, `edit_users`,
  `promote_users` and `update_core`, evaluated as `WP_User::get_role_caps()` evaluates them:
  every role that the account's list names, in its order, then the list itself. Filters such
  as `user_has_cap` and `map_meta_cap` do not take part, so a capability that only a filter
  grants counts as missing. The review refuses otherwise (`database_admin_capabilities`), and
  the exchange checks it again under the write barrier, where it already rechecks the
  administrator's identity. Nothing grants a capability or changes a role to make it pass.
  The review stores the capabilities it checked in the job, and the exchange checks those;
  if the site requires other capabilities by the time of the exchange, because a filter of the
  backup capability changed, the job stops with `database_exchange_changed` before the private
  target or the live tables are written, and the review has to be repeated. An undo does the
  same.
  A record of the account's own capabilities that is missing, stored more than once, longer
  than 64 KiB or not in the form WordPress writes is refused as such
  (`database_admin_capabilities_unreadable`), not as missing capabilities; WordPress itself
  uses the first of several records. Apply and undo reviews read the record the same way and
  in the same order, so they answer the same for the same account.

The role map, the list of active plugins and the account's own capabilities come from a
backup or from tables that other code can write. `Database\Restore\SerializedValue` reads
them without `unserialize()`: only arrays with integer or string keys, strings, integers,
booleans and null as `serialize()` writes them, nested no deeper than the value needs, no
data after the value. A key written as a string of decimal digits becomes an integer key, as
it does with `unserialize()`. Objects, references, floats and anything else are refused, as
are names that are empty, longer than 1,024 bytes, not UTF-8 or contain control characters.
A role needs exactly a display name and a map of capabilities; a list of active plugins has
integer keys and distinct paths. Such values are refused with `database_roles_invalid` and
`database_plugins_invalid`.

The reads are bounded: a role map at 1 MiB, a list of active plugins at 256 KiB, an account's
capabilities at 64 KiB. A longer value is not transferred from the database; its length is,
and it is refused with a code that names the bound: `database_roles_too_large`,
`database_plugins_too_large`, and for the account's record the code above. The role map's
bound was 512 KiB at first. The checks under the write barrier grow with it: on MySQL 8.4.11
a whole exchange took 70 to 73 ms with WordPress's own roles (3 KiB), 92 to 106 ms with a
512 KiB role map and 118 to 123 ms with a 1 MiB one (52,824 capabilities in one role, three
runs each); with shorter names, about 80,000 capabilities in 1 MiB, one run took 155 ms. That
stays far below the three seconds that each statement under the barrier may wait for a lock,
so the bound is 1 MiB, enough for role maps that `add_role()` and `add_cap()` wrote for sites
with dozens of custom roles. The parser's element bound, 262,144, lies above anything that a
value within these bounds can hold, so it never stands in for them. The rehearsal report
records the snapshot's role map and list of active plugins as stored (base64,
`wordpress_state`), or the length of one beyond its bound, or `null` where the snapshot has
none; its own bound is 3 MiB. A rehearsal from before has no such record and its review is
refused with `database_review_required`; a `null` is refused with the code of the value,
`database_roles_invalid` or `database_plugins_invalid`, because a new rehearsal would find the
same. A review of a site that lacks its live options, users or user meta table reads nothing
from them: it answers the blocker `wordpress_tables` and compares nothing.

The running site's role map and list of active plugins are read for the comparison only: the
exchange discards both, so their form protects nothing. If one of them cannot be read, the
review does not refuse. It says so (`readable: false`), compares against nothing and lists
every role, or every plugin, of the generation that becomes live, and the digest covers that.
At first such a value refused the review with `database_roles_invalid` or
`database_plugins_invalid`, so that whoever could write the option in a form WordPress reads
but the reader refuses could switch off the restore of a clean backup (finding 1 of the
review of pull request 59). The values of the generation that becomes live and the account's
own record still have to be readable. For an undo the generation that is live now plays the
part of the running site: its values are only compared, and those of the earlier generation
have to be readable. A restore whose running site's role map or list cannot be read therefore
cannot be undone through the plugin: the undo would bring that value back and cannot check
it. The review of the restore says so; the earlier tables stay retained for the window
(decision 6).

The review answers what the exchange keeps (`kept`) and takes from the other generation
(`restored`), whether it adds this plugin's path (`plugin`), the plugins active now and not
afterwards and the other way round, each with whether WordPress would find its file on the
site (`plugins`), the roles that differ (`roles`) and the capabilities the administrator would
lack (`missing_capabilities`). A role differs if only one generation has it, or if it grants
or denies other capabilities: for each role the review lists the capabilities granted only
afterwards and only now, and the denials stored only afterwards and only now. A stored denial
matters for an account with several roles, where a later role's `false` takes away what an
earlier one grants. The page shows these four lists of a role under their own labels, each
only when it has entries, and says in one sentence what a denial does. Each list shows at most
50 entries and counts the rest. Every name is
untrusted text: the page shows it as text. The digest (now version 2) covers all of it and the
capabilities checked, so a review from before or with other facts cannot be confirmed
(`database_exchange_changed`). The authority that the exchange compares under its write barrier
(now versioned) covers the live list of active plugins as well as the live role map, each by
its fingerprint or its absence: a plugin activated between the review and the exchange stops
the job with `database_exchange_changed`, and so does a job confirmed before this change, which
has no checked capabilities stored. Whether a plugin's file exists is read at the review only.

An undo uses the same protocol in the other direction. The earlier generation comes back
with its own roles, active plugins and scheduled events, as they were when the apply took it
out of service; this plugin's path is appended to its list if it lacks it, for example after
the plugin moved to another directory; the administrator's own capabilities, kept from the
running site, must still give the five capabilities under its roles. The undo's review shows
the same facts with the earlier generation in place of the backup. Its refusals advise what
an undo allows: a role on the running site that the earlier generation also has, or a
database administrator's recovery of the retained tables before the window ends; not the
staged SQL files, which hold the backup that is live already.

Three consequences are accepted and shown in the review rather than prevented:

- A plugin that is active in the backup but whose file is missing on the site is not loaded:
  `wp_get_active_and_valid_plugins()` skips it, and the Plugins screen removes it from the
  list with a notice. Its scheduled events stay in the restored array and run without a
  callback. The review shows it with "file: no"; installing the plugin before the restore
  avoids it.
- A plugin that is active now and not in the backup stops without its deactivation routine.
  Its scheduled events leave with the running site's cron array; what it keeps outside the
  restored tables stays. Likewise, a plugin that the backup activates runs without its
  activation routine, with the options and tables it had when the backup was made.
- A plugin that the backup activates can change what an account may do afterwards, which the
  review cannot compute from the restored data: it can filter capabilities, filter
  `backups_for_restic_capability`, which names the capability that this plugin's pages and
  routes require, or filter `file_mod_allowed`, which takes `update_core` away. The check of
  the administrator uses the capability that the running site requires, and the roles and
  record as stored. The review's note on plugins that start says this, and that deactivating
  such a plugin on the Plugins screen undoes its effect. WP-CLI offers no database undo.

What remains: a WP-Cron request that runs the exchange goes on with the events that were due
when it started and come after this plugin's tick in its list. WordPress reschedules each of
them in the cron array that is now live before it runs it, so such an event of the running
site can reach the restored array. The request still holds the code and the callbacks of the
plugins that were active when it started, so a due event of a plugin that the exchange has
stopped runs in it once more, against the restored database; later requests no longer load
that plugin. In a request that the maintenance gate admitted, only this plugin's events are
due. A backup whose list names another copy of this plugin in another
directory starts that copy next to this one; the review lists it as a plugin that starts.

## Evidence

The database suite exercises actual MySQL and MariaDB. MySQL tests cover a concurrent
writer queued on a live name, worker processes killed immediately before and after RENAME, changed native
identities, changed administrator authority, original table comments, protected
WordPress state, post-apply writes retained after undo and expiry replay. Since the
amendment of decision 5 they also cover the finding's case end to end (a capability added to
the subscriber role and a plugin activated after the backup are gone after the exchange and
back after its undo), the backup's cron array at the refresh, this plugin's path appended
once on the private target and never written after the exchange or into a replaced table,
an administrator refused at the review, at the exchange and at an undo review, role maps and
plugin lists that WordPress does not write, a fact changed after the review, and a job and a
rehearsal from before the amendment. Unit tests check the reader against `unserialize()`
with mutated input, the role evaluation against WordPress's order rules, and the review's
bounds and digest; a real WordPress shows the review of a rehearsal and the heartbeat after a
cron array without it. After the review of pull request 59 they also cover: a running site's
role map or list of active plugins that the reader refuses or that is absent, which no longer
blocks the restore, in both directions, with the undo of such a restore refused; a record of
the administrator's capabilities stored twice or not at all, named alike by apply and undo
reviews; stored denials that differ; role maps and lists beyond their bounds at the review
and under the barrier; the undo's own texts; and the version prefix of the authority. The
reviewer compared the evaluation with WordPress 7.1.5's `WP_Roles` and `WP_User` on 20,000
random role maps and the reader with `unserialize()` on 60,000 values without a difference. The job tests
exercise the normal filesystem store, timeout, confirmation digest, staging pin, history
pruning and recovery windows. A local real WordPress 7.1.4, PHP 8.4 and MySQL 8.4.11
restore also verified authentication state, addresses, active plugins and the heartbeat.
After the review of 0.3.0-rc.1 the MySQL tests also cover: a second session that keeps a
unit of work open across the swap, the same with a reconnect during maintenance, and one
that outlasts the wait; a binding with another
server UUID during an apply, with a backup, a review, the discard and uninstall; since
2026-11-26 also the review of a discard on MySQL for a binding of another server, one of the
connected database, none, and one that changed after its review, and an unfinished import of
another server on MySQL and MariaDB, each refusal of its routes and of the command against a
real WordPress, and its flow on the admin page in a browser; a lost reply
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
