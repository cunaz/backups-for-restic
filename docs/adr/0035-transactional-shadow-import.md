# 0035 · Import database batches and their progress in one transaction
Status: accepted. Restore jobs can rehearse base-table imports and clean their temporary tables.
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
  manifest and 10,000 tables/views; numeric counts must fit this PHP host. Every character
  of a table or view name prints as itself (amended 2026-11-27, see "Table names that do not
  print as themselves" below).
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
  The importer's limit is 16 MiB per statement (semicolon included, LF excluded). The dump
  writer fills statements with several rows up to about 1 MiB, far below it, and never
  omits a row: a row too large to share a statement is written as a statement of its own,
  whatever its size, if it fits with its SQL into the PHP memory limit; otherwise the dump
  stops with `database_dump_row_memory`, which names the table. The dump's manifest
  records each table's largest statement ([ADR 0021](0021-database-streaming.md)). The
  importer keeps its 16 MiB bound, so a table with a larger statement is restored with an
  external client, as dumps written before the limit existed are.
  Each import tick reads the session's `max_allowed_packet` and refuses a batch that would
  not fit before sending it, with `database_restore_packet`; the generated shadow name can
  make a batch longer than its line in the dump. A server that receives a larger packet
  closes the session ([Packet Too Large](https://dev.mysql.com/doc/refman/8.4/en/packet-too-large.html)),
  which a client cannot tell from a lost connection. On MySQL 8.4.11 a statement of
  `max_allowed_packet - 1` bytes was refused and one of `max_allowed_packet - 2` accepted;
  the check keeps a margin of 64 bytes.
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
  enabled uniqueness/foreign-key checks and quoted SHOW CREATE identifiers, then restores
  prior session values. Ownership checks therefore work even when the site normally
  disables identifier quoting.

## Scope of this first implementation
`TableImporter` prepares base-table data only. Its `done` result means that table loading
finished, not that the database is ready for live apply. Restore jobs accept the boolean `database_preview` through REST and `--database-preview` through WP-CLI. Foreign keys and unsupported schema constructs fail explicitly.
Manifest views are retained for planning, not executed or counted as restored. Mapped constraints/views, original-engine/comment finalization, confirmed swap, plugin-state preservation and undo remain open. Maintenance belongs to the later swap; live tables stay in use here.

The dump writer also selects insertable columns explicitly, including invisible columns,
and distinguishes expression defaults (`DEFAULT_GENERATED`) from virtual/stored generated
columns. Otherwise a new importer would reproduce an already incomplete dump. This fixes
future backups; it cannot recover values omitted from older snapshots.

The transactional shadow omits engine-specific physical options: `ROW_FORMAT=FIXED` or
`PAGE`, MyISAM's `CHECKSUM` and `DELAY_KEY_WRITE`, and `KEY_BLOCK_SIZE` from non-InnoDB
tables. Compatible InnoDB row formats are retained. This component preserves logical data,
the original engine and table comment, not a guarantee of identical physical storage.
The catalog temporarily enables `sql_quote_show_create` and the dump's SQL mode for each
SHOW CREATE TABLE/VIEW, then restores both original values even when the read fails. Otherwise a server
with quoting disabled can produce a complete dump the strict importer cannot read. Both
dump adapters return an empty row list for session SET statements; the shared port now
documents that scoped use and the non-reconnecting mysqli adapter follows it too.
The catalog makes no data changes. This follows the
[SHOW CREATE contract](https://dev.mysql.com/doc/refman/8.4/en/show-create-table.html).
An existing unquoted dump remains outside the strict import grammar.
Using the dump mode also retains backtick identifiers and backslash escaping and avoids
[MariaDB compatibility modes omitting table/column/index options](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/show/show-create-table).
Database regressions cover ANSI identifiers, literal-backslash mode and disabled quoting,
including schema defaults/comments and complete restoration of the site's session values.

Session cleanup writes `foreign_key_checks`, `unique_checks` and `sql_quote_show_create`
as validated numeric 0/1;
binary string literals containing those characters are not accepted boolean settings.

## Rehearsing a staged database

An administrator can request a database preview when restoring the whole snapshot or a
selection that includes its complete database directory. After staging, the job resolves
the original spool location from the snapshot's site manifest and checks the database
inventory against the current table prefix. A snapshot without a database dump, one without
a site manifest or whose manifest says that its backup left the database out, fails the job
with `database_not_in_snapshot` before the first file is staged; until 2026-11-27 it failed
after staging with `database_dump_failed`, whose texts send the administrator to a database
error in the log that there is not. The review of such a restore answers with the same code,
since a new rehearsal would find the same. The runner saves the random ownership token,
inventory hash and table count before a later tick creates any private SQL table.

A completed rehearsal writes `database-preview.json` beside the staged files. It records
the original site address, prefix, verified row counts per snapshot base table, and the
server's estimated row counts for current tables. Views are listed separately. A schema
or charset the server cannot recreate ends the rehearsal before it reports success.
The job's result includes the verified base-table and row totals and the report path.

Cleanup checks the generated name and ownership comment before dropping each shadow and
removes the progress ledger last. Cancelled jobs use the same operation. A killed tick
can repeat its last cleanup cursor. When a database connection failure outlasts a job,
the heartbeat resumes cleanup from the job's binding. History pruning retains these jobs.
Other backups wait so that an all-tables dump cannot include another job's shadow data.
Uninstall completes pending cleanup before removing the binding. If a database administrator
revoked DROP, restore the privilege and retry.

## What the manifest records about each table (2026-11-27)

Since pull request 54 the dump's manifest records for each table how it was read
([ADR 0021](0021-database-streaming.md)): `key`, the columns of the key that ordered its
pages, empty without one; `snapshot`, whether all of its rows come from one read view;
`restarts`, how often the table started over because its definition changed; and
`largest_statement`, its longest statement in bytes, with the semicolon and without the line
break. A table that a version before these fields dumped has none of them, and a manifest
that would be larger than 2 MiB with them leaves them out for every table. `Manifest` keeps
them by ordinal (`reads`) and refuses an entry that has some of the four but not all, or one
that is not of its kind, with `database_restore_format`. The fields do not name a restic
snapshot or key: the manifest is a file of the snapshot's tree, which restic authenticates,
and the rehearsal's report binds it to the snapshot by its digest (ADR 0043, decision 1).

- **Statements that cannot be sent.** Before the first statement of every tick, the progress
  table's CREATE included, the importer compares each recorded `largest_statement`, with the
  shadow's generated name in place of the table's, with the 16 MiB that it reads and with the
  session's `max_allowed_packet`. It refuses with `database_restore_too_large` or
  `database_restore_packet_limit`; the job log names the table and, for the second, the
  `max_allowed_packet` that the largest statement needs, rounded up to a multiple of 1024.
  Before, a line beyond 16 MiB stopped the import when it reached it, as an unreadable dump
  (`database_restore_format`), and a batch beyond the packet limit at that batch
  (`database_restore_packet`), hours into a large rehearsal and without the limit that later
  tables need. A table without the field is still checked batch by batch before each one is
  sent.
- **A definition that cannot be sent.** The dump measures its INSERT statements only: a
  table without rows records 0, while its CREATE can take up to the 256 KiB that
  `CreateStatement` reads, more than a `max_allowed_packet` set below that. On the first tick,
  before the progress table is created, the importer reads the header of each staged table
  file and compares the rewritten CREATE, with the shadow's name and the ownership comment,
  with the session's `max_allowed_packet`; it refuses with `database_restore_packet_limit`,
  naming the table and the limit that its CREATE needs. Each CREATE is checked again just
  before it is sent, for an import that a request with a smaller limit takes up again. Before,
  the server refused the CREATE with error 1153 after the ledger and the earlier tables were
  created (MySQL 8.4.11 and MariaDB 11.4.13, a definition of about 21 KiB under a limit of 16
  KiB).
- **A duplicate key.** A batch that the server refuses with error 1062 ends the import with
  `database_restore_duplicate`. The job log names the table and says what the manifest
  records: a table read at more than one moment (`"snapshot": false`) can hold a row twice
  that was deleted and inserted again with a new key between two reads; one read in one read
  view cannot, so the duplicate comes from elsewhere, for example a collation that compares
  two values as equal on this server; and for a table without the field, that the manifest
  does not say. The rows are not named, and the batch and its progress are rolled back. The
  way on is the manual import of the recovery guide, which reconciles such a table in a
  scratch copy (docs/06, step 5 of Scenario B).
- **Rows read by position.** A table without a key that the dump read at more than one moment
  (`"key": []` and `"snapshot": false`) can miss rows or hold them twice that were added or
  removed between the reads; the backup's job warned about it. The rehearsal report names
  such a table with the finding `read_by_position`. It describes the backup's rows and does
  not hold up an exchange, so the report's preflight blockers leave it out.
- **`restarts`** has no use in the import: the file holds the definition that the dump began
  last and the rows read under it.

## Table names that do not print as themselves (2026-11-27)

Until 2026-11-27 the check of table names accepted every valid UTF-8 name of at most 64
characters without `.`, `/`, `\` and ASCII controls, Unicode format characters included:
a right-to-left override (U+202E), zero-width characters. Pull request 58 made the admin page
show such names escaped, but in a terminal, a log or another tool a name with them can still
read as another one, and so can a review or a report that names it.

A table or view name of a restore is accepted when every character prints as itself:

- it is a letter, mark, number, punctuation, symbol or the ASCII space (Unicode categories
  L, M, N, P and S, and U+0020), which is what Go's `strconv.IsPrint()` accepts and what the
  admin page and the command line show unescaped (`GoQuote`);
- it is not one of the default-ignorable code points among them, which render as nothing:
  U+034F, U+115F, U+1160, U+17B4, U+17B5, U+180B to U+180D, U+180F, U+3164, U+FE00 to U+FE0F
  and U+FFA0 (DerivedCoreProperties.txt); the other default-ignorable characters are format
  characters or unassigned;
- it lies in the Basic Multilingual Plane: MySQL allows nothing beyond U+FFFF in an
  identifier ([Schema Object Names](https://dev.mysql.com/doc/refman/8.4/en/identifiers.html));
- and the name does not end with a space, which MySQL refuses for a table name.

So format characters (category Cf: U+00AD, U+061C, U+180E, U+200B to U+200F, U+202A to
U+202E, U+2060 to U+206F, U+FEFF and others), the ASCII controls U+0000 to U+001F and U+007F
and the C1 controls U+0080 to U+009F, spaces other
than U+0020, line and paragraph separators, private-use, unassigned and noncharacter code
points are refused, wherever they stand in the name. Names in other scripts, Cyrillic, CJK,
Arabic, Hebrew, Devanagari or Thai with their combining marks among them, and the ASCII names
that WordPress and its plugins create are accepted: WordPress requires a table prefix of
letters, digits and underscores, and plugins name their tables in ASCII after it. The
categories come from PHP's PCRE, so a character that a Unicode version newer than PCRE's
assigns is refused as unassigned until PHP knows it.

The refusal has the code `database_restore_name`. It comes from `Manifest::parse()`, before
the rehearsal or the apply sends a statement. Its context names the table as the page shows
such a name, in double quotes with Go's escapes and the invisible characters above escaped
as well (`Manifest::quoted()`), so that the log shows only printable characters; the
rehearsal's job keeps that name, and its texts give it. The older check of the characters of
a path comes after the check of printable characters: `.`, `/` and `\`, which print as
themselves, are refused as outside the format of the staged files, without the code. Until
the review of this change that check also held the ASCII controls and ran first, so that a
name with a tab was refused without the code and without its name. A rehearsal or a review
made before the change meets the same refusal when the review or the apply reads the staged
manifest again. A site that needs such a table restores the database by hand with the
command-line client, which imports any name that MySQL accepts, or renames the table, or
leaves it out of its backups, before the next backup (docs/06). Confusable letters of
different scripts, a Cyrillic "а" for a Latin "a", print as themselves and are not refused;
the page isolates each name's writing direction (`<bdi>`) and shows it as text.

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
have database tests, as does dumping/importing with identifier quoting disabled. The
unexpected INSERT-target fixture recomputes its trailer and asserts the precise refusal,
an empty shadow and an unchanged progress row, so a later trailer failure cannot satisfy it.
A parent sends SIGKILL
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
