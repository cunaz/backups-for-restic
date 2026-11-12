# 0035 · Import database batches and their progress in one transaction
Status: accepted; base-table import implemented, schema mapping, cleanup, apply and job integration pending
Date: 2026-11-12

## Context
The dump format has one physical line per SQL statement. That avoids splitting arbitrary
SQL scripts, but does not make a restored line safe to execute. It can name another table,
call a function, select an external engine or contain another statement. CREATE expressions
also need checking: MariaDB permits user-defined functions in generated columns.

A file checkpoint written after an INSERT is not enough. A kill between those writes
repeats rows, including legitimate duplicates in tables without a primary key. DDL
implicitly commits, and reconnecting and retrying an INSERT can lose its transaction.

## Decision
- `Database\Restore\Manifest` accepts completed `backups-for-restic-dump/1` inventories,
  the site's exact table prefix and the writer's encoded filenames. Limits are 2 MiB of
  manifest and 10,000 tables/views; numeric counts must fit this PHP host.
- `TableFile` reads regular files from a resolved private staging directory. It requires
  the exact header, five session-setting lines and expected DROP line, without executing
  those commands. It checks file size, column order, inserted rows, trailer bytes and EOF.
  File identity, size, timestamps and a header hash bind each resumed table to its source.
  The staging owner must keep these files immutable. Metadata checks do not protect
  against a local actor who can alter private files and their metadata; authentication of
  repository bytes belongs to the preceding restore.
- SQL is bounded to 16 MiB per physical statement, 20,000 rows per INSERT, 4,096 columns
  and 256 KiB per CREATE. INSERTs contain only the expected quoted table/columns and
  literal tuples. CREATE admits local columns, indexes and checks with an explicit builtin
  function list. Qualified names, unknown functions, external engines, paths and additional
  statements are refused. MySQL's exact `/*!80023 INVISIBLE */` marker becomes the ordinary
  `INVISIBLE` keyword; executable comments are never passed through to the server.
  The dump writer uses the same 16 MiB statement limit (semicolon included, LF excluded).
  It refuses an oversized encoded row with a database error before recording a completed
  dump; it never silently omits that row. Older oversized dumps require an external client.
  A table with only generated columns uses a constant SELECT projection and one empty
  INSERT tuple per row. The importer accepts these tuples only with the CREATE's matching
  empty insertable-column list.
- The job must persist a random 128-bit ownership token before the first tick. Table names
  derive from it and their ordinal, fit the identifier limit and never start with the site
  prefix. Comments bind objects to the token, import layout version and exact manifest.
  Existing objects must be owned InnoDB tables, including through SHOW CREATE, before use
  or deletion. Foreign objects and temporary tables hiding owned tables cause refusal.
- Shadows and the progress ledger use InnoDB. Every INSERT and its next offset/row count
  commit together. Completion retains a separate table record with its original engine
  and comment, advancing the cursor in the same transaction. A filesystem job checkpoint
  is never authoritative about which rows committed.
- The persisted `preparing` phase precedes shadow DDL. Resume recreates only an owned
  shadow still in that phase. Rows start after `importing` and the initial offset are saved.
  A kill after CREATE therefore loses only empty-table preparation; a kill during a batch
  loses at most that batch. Repeating a completed import changes no rows.
- Each tick holds a nonblocking session advisory lock across DDL and row transactions.
  Cleanup attempts rollback and all setting restores before releasing it. A dead connection
  releases the server lock; the next tick reads committed progress.
- The restore port requires one session without reconnect, retry or query rewriting.
  `MysqliConnection` uses an existing handle and reports numeric error codes or warning
  counts, never SQL, values or a chained server exception. Driver warnings are suppressed
  and checked. A warning, including truncation, refuses the batch and its progress.
- A tick requires autocommit and uses `SET TRANSACTION READ WRITE` without SESSION as a
  guard before DDL. [MySQL](https://dev.mysql.com/doc/refman/8.4/en/set-transaction.html)
  and [MariaDB](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/set-commands/set-transaction)
  refuse that statement inside an active transaction. Its one-transaction setting is
  consumed immediately by an empty transaction. A failed guard never rolls back or commits
  foreign work. Import establishes its SQL mode, UTF-8 connection, numeric time zone and
  enabled uniqueness/foreign-key checks, then restores prior session values.

## Scope of this first implementation
`TableImporter` prepares base-table data only. Its `done` result means that table loading
finished, not that the database is ready for live apply. It is not exposed through WordPress
jobs, REST or CLI yet. Foreign keys and unsupported schema constructs fail explicitly.
Manifest views are retained for planning, not executed or counted as restored. Mapped
constraints/views, bounded shadow cleanup, the report, original-engine/comment finalization,
confirmed swap, plugin-state preservation and undo remain required before the restore
release is complete. Maintenance belongs to the later swap; live tables stay in use here.

The dump writer also selects insertable columns explicitly, including invisible columns,
and distinguishes expression defaults (`DEFAULT_GENERATED`) from virtual/stored generated
columns. Otherwise a new importer would reproduce an already incomplete dump. This fixes
future backups; it cannot recover values omitted from older snapshots.

The transactional shadow omits engine-specific physical options: `ROW_FORMAT=FIXED` or
`PAGE`, MyISAM's `CHECKSUM` and `DELAY_KEY_WRITE`, and `KEY_BLOCK_SIZE` from non-InnoDB
tables. Compatible InnoDB row formats are retained. This component preserves logical data,
the original engine and table comment, not a guarantee of identical physical storage.
Session cleanup writes `foreign_key_checks` and `unique_checks` as validated numeric 0/1;
binary string literals containing those characters are not accepted boolean settings.

## Verification
Unit tests cover the SQL grammar and limits, unsafe commands, trailer and source replacement
checks, transaction guard and cleanup errors. Two writer regressions fail before the fix:
an expression-default column disappears, and an invisible column is emitted as NULL.
CREATE, INSERT and manifest parsers have fuzz targets; the import filter joins the covered
mutation gate.

CI runs the existing manual dump/import tests and new shadow tests on MySQL 8.4 and MariaDB
11.4. They compare table checksums with binary data, generated values, invisible columns,
timestamps and duplicates, while checking unchanged live tables. Across different storage
engines, they compare ordered rows: [MySQL's checksum depends on row format](https://dev.mysql.com/doc/refman/8.4/en/checksum-table.html).
Generated-only tables, a fixed-format MyISAM source and an unchanged completed retry also
have database tests. A parent sends SIGKILL
after ledger creation, shadow creation, INSERT, progress UPDATE, before COMMIT and after
COMMIT. Resume must preserve duplicate rows exactly once. Other cases refuse foreign
collisions, active transactions, truncation and INSERTs aimed at a live table. Database
runs are CI-only; local parser tests are not evidence of either server accepting the SQL.

## Alternatives considered
- Accept every line starting with CREATE or INSERT: allows functions, other destinations
  and extra statements that the dump never needed.
- Save a JSON cursor after INSERT: duplicates non-keyed rows after a kill.
- Import directly into MyISAM or MEMORY: cannot roll back rows together with progress.
- Use `$wpdb->query()`: reconnects and query filters can alter the transaction or command.
  WordPress integration must obtain a suitable handle or refuse, not fall back to retries.
