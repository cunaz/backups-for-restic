# 0021 · Database backup streams per table into the engine; no plaintext dump on disk
Status: accepted (supersedes 0005; revised in Milestone 1 and after the 0.3.0-rc.1 review)
Date: 2026-11-09, revised 2026-11-22

## Context
With the engine in PHP, the database dump no longer has to be a file for an external program.
Writing a plaintext dump to disk costs disk quota, leaves sensitive data on the filesystem,
and makes an interrupted dump start from zero.

## Decision
- `Database\Dump\DatabaseDump` produces the dump as a set of **virtual files** inside the
  snapshot: `spool/database/000-header.sql`, one `010-<table>.sql` per table, `900-views.sql`
  and `manifest.json`. Names sort in import order, and the backup reads them in that order.
  Nothing is written to disk except a small state file with the table list and the row
  counts of finished tables.
- Format `backups-for-restic-dump/1`: one SQL statement per line; values escaped, binary data
  and text that is not valid UTF-8 as hex literals, `BIT` values as numbers, `FLOAT` values
  as the exact `DOUBLE` they convert to; INSERT statements of about 1 MiB; every file starts
  with the session settings (character set utf8mb4, `sql_mode`, time zone `+00:00`) so that
  it also imports on its own. Each table file ends with `-- end table rows=<n> bytes=<n>`.
  Views lose their `DEFINER` clause so that they import on other servers; generated columns
  are left out of the INSERT statements and computed again on import. Generated-only tables
  select a constant to count rows and emit one empty INSERT tuple per row.
- **Every read in utf8mb4 and UTC.** Rows and definitions are read with utf8mb4 as the
  character set of the connection and of its results, whatever `DB_CHARSET` is, and with the
  session time zone `+00:00`, as `mysqldump --tz-utc` does. The manifest and the preambles
  record `+00:00`. TIMESTAMP values therefore import to the same instant in every half of
  the year, and text arrives as the one character set the manifest names (below). Every read
  also runs without a row limit: a session can inherit `sql_select_limit` from the server's
  settings, and the limit cuts `SELECT` and, on MySQL 8, `SHOW FULL TABLES`, `SHOW FULL
  COLUMNS` and `SHOW KEYS` short without an error.
- **Pages follow a key in full.** A table is read in pages along its primary key, or else a
  unique key over NOT NULL stored columns, with all of its columns: each page asks for the
  rows after the last key written, with the row-value comparison `(a, b) > (x, y)` written
  as `(a > x) OR (a = x AND b > y)`, the form for which the server reads index ranges. Each
  key value is read and written back so that the comparison orders exactly as `ORDER BY`
  (`KeyColumn`): numbers as the exact strings the server sends, `FLOAT` and `DOUBLE` as
  `col + 0e0`, `ENUM` and `BIT` as `col + 0` (they sort by index and bits), `SET` as
  `CAST(col AS UNSIGNED)`, read and compared, since the server takes a SET as a signed
  `DOUBLE` in arithmetic and in a comparison with a number, strings
  as their stored bytes in their own character set and collation, binary strings byte by
  byte, dates and times as checked quoted text. The session's sort length is raised to its
  maximum, so that a sort compares whole key values. An insert or a delete before the cursor
  can therefore not shift rows: a row that exists for the whole dump of its table, with the
  same values in that key, appears exactly once. A row whose key value changes across the
  cursor between two ticks can appear twice or be missing. Keys over TEXT, BLOB, JSON or spatial columns are not used, because an index
  and a sort compare only a prefix of such values.
- **Resumable per page.** When a tick's deadline passes, the table source finishes the
  current page, the chunker emits the bytes it holds as a final chunk, and the checkpoint
  records a cursor after that page. The next tick continues there, and a table larger than
  one tick can process still finishes.
- **One snapshot per table and tick, on a connection of the dump's own.** The dump opens a
  connection with the host, port or socket, user, password, database and client flags that
  `$wpdb` connected with. It uses it only after checks that it reaches the same server
  (the site's connection takes a lock with a random name, and only the same server sees it
  taken), the same database, and is encrypted if the site's connection is. Where the site's
  connection is encrypted but the client flags (`MYSQL_CLIENT_FLAGS`) do not ask for
  encryption, for example behind a drop-in that sets up TLS itself, the dump does not even
  connect, so that the account never authenticates in the clear. Each table's
  part of a tick is one `READ ONLY` transaction there; it begins with a read of the table
  before its definition is read, so the table's metadata lock holds the definition for the
  tick, and InnoDB serves every page from one snapshot. A transaction is never started on
  the site's shared connection, which WordPress and other plugins use in the same request.
  Where no connection of its own can be opened and verified, for example behind a database
  drop-in with settings of its own, the dump reads through `$wpdb`: every query then runs
  between one statement that saves the site's session settings in user variables and sets
  the dump's, and one that restores them, also after a failed query; a replaced connection
  has no saved values and fails the restore. Two read-backs check that the statements reach
  the session with the dump's settings, and a drop-in that fails either is refused with
  `database_dump_session`. Before the first query, a value set by one statement without a
  table is read back by the next: consecutive statements reach one connection. Before the
  first read of each table, a statement that names the table reads that value back with the
  dump's settings: the table's statements reach the same session as the settings, as a
  drop-in that sends the reads of a table to a replica would not. Neither shows where a
  drop-in sends a single later statement, if it chooses per statement rather than per table,
  nor are view definitions checked this way, because a statement that names a view fails
  when a table the view uses is gone.
- **Tables without a usable key.** On the dump's own connection such a table is read with
  one unbuffered statement, which is consistent by itself. When a tick ends before the
  statement does, the connection is closed rather than read to the end, and the next tick
  continues at the row's position with a new statement. Through `$wpdb` such a table is
  read in pages by position. Rows added or removed between two reads can then be missing
  or repeated: the manifest marks the table (`"key": []`, `"snapshot": false`) and the
  backup job gets a warning that names it. A careful backup keeps the table this way
  rather than fail every backup, files included, over a table that cannot be read at once.
- **Consistency across ticks.** The pages of a table that spans ticks come from one
  snapshot per tick, and different tables from different moments. A row deleted and
  inserted again with a new key between two ticks, such as a transient that expires and is
  set again with a new `option_id`, can appear twice: once from the earlier tick with its
  old key and once from the later one with its new key. For a unique key other than the
  paging key the import then stops with a duplicate key. The manifest records for each
  table whether all its rows come from one read view (`"snapshot"`), so that an importer
  can explain such a failure precisely; the plugin's importer names the table and says so
  (`database_restore_duplicate`, ADR 0035). The field speaks of the rows only: a table read
  with one statement through `$wpdb` has `"snapshot": true` although no transaction held
  its definition, which the check after each page guards there (see below). The plugin's
  restore refuses a manifest larger than
  2 MiB before it reads any of it, and sixteen key columns with names of 64 characters take
  up to 6 KiB per table: a manifest that would be larger is written without line breaks,
  then without how the rows were read (`"key"`, `"snapshot"`, `"restarts"`,
  `"largest_statement"`). If even the list of tables is larger, which takes thousands of
  tables with long names, the backup keeps it and warns that its database can be restored
  with the command-line client only.
- **The definition is pinned.** The cursor carries a digest of the table's definition (SHOW
  CREATE TABLE without the `AUTO_INCREMENT` counter, the columns and the key). A tick that
  finds another definition starts the table over (the engine drops the chunks of the
  earlier parts), at most three times; then the backup stops with
  `database_dump_unstable`, which names the table. Through `$wpdb` nothing holds the
  definition during a tick, so it is read again after every page, before the page is
  written, and before the trailer: a page read under another definition is not written,
  even if the change is undone later, and the table starts over. The server holds a table's
  definition for the length of each statement but not from one statement to the next, so
  what remains is a change and its undoing that both complete between the dump's read of a
  page and its read of the definition right after it.
- **Locked tables.** Every read of the dump waits at most five seconds for a lock that
  another connection holds (`lock_wait_timeout`, which is a year on MySQL and a day on
  MariaDB unless the server's settings say otherwise), for example a table held with
  `LOCK TABLES ... WRITE` or by the last phase of an `ALTER TABLE`. On the dump's own
  connection the transaction of a table whose lock was not granted ends at once. The tick
  then stops with `database_dump_locked`, which names the table, and the job tries again
  as after any retryable error: 30 seconds later, then twice as long after each further
  failure in a row. The work of the tick since its last checkpoint is done again, and the
  table continues from its cursor. A table that stays locked fails the job at the sixth
  failure in a row, about a quarter of an hour after the first.
- **Large rows.** A row too large to share a statement is written as one of its own,
  whatever its size, as long as PHP can hold it; the plugin's importer reads statements up
  to 16 MiB (ADR 0035), the command-line client as large as `max_allowed_packet` allows.
  The manifest records each table's largest statement (`"largest_statement"`, with the
  semicolon and without LF) for an importer to compare with `max_allowed_packet`; the
  plugin's importer does so, and with its own 16 MiB, before it sends a statement
  ([ADR 0035](0035-transactional-shadow-import.md)). A row
  whose statement would not fit into the PHP memory limit stops the dump with
  `database_dump_row_memory`, which names the table. A large value is written in pieces of
  about 2 MiB, so that it is never held twice. A page is held in memory whole, so the pages
  of a table with TEXT, BLOB, JSON or spatial columns first ask the server for the sizes in
  which their next rows arrive, text in another character set converted to utf8mb4, where
  latin1 "é" takes two bytes and "€" three, and fetch only as many as fit, with their SQL,
  into what the memory limit leaves, at least one. A row that cannot fit on its own stops the dump before it is
  fetched. On the dump's own connection, a table without a key is read with its one
  statement row by row: each row is written before the next one is fetched, so that no two
  large rows are held at once, and the statement asks the server to send a row's values only
  if the row fits on its own into the memory left when the statement starts, so that no scan
  of the table looks for its largest row first. Through `$wpdb` the sizes and the page are two reads,
  and a row can grow in between: each row of the page may take an equal share of the memory
  left, and the page asks the server to send a row's text and binary values, VARCHAR
  included, only if the row stays within that share. A row that has grown beyond it comes
  with its size but without those values; the page ends before it, and the next page reads
  its size again and fetches it on its own if it fits. The page can therefore fetch no more
  than the memory it was admitted for. What remains is memory that other code takes between
  the two reads, which the reserve of 16 MiB for the backup's own reading has to cover. A
  table without such columns has rows that its column types bound, at four bytes for each
  character of text: its pages fetch no more rows than fit into the memory left if each were
  that large, so a latin1 VARCHAR(65000), which can arrive as 195,000 bytes of utf8mb4,
  makes pages of a few dozen rows where memory is tight.
- Restore: database files are restored to staging and executed through `$wpdb` in budgeted
  batches with maintenance mode and plugin state preserved (Milestone 3). The manual path is
  `cat *.sql | mariadb`, verified in the database test suite.
- Not dumped in 1.0: triggers, routines, events (rare in WordPress; documented).

### Revised during Milestone 1
- The trailer carries rows and bytes but no SHA-256. A running hash cannot survive the tick
  boundary in PHP without `unserialize()`, which the plugin bans, and restic authenticates
  every byte of the restored file anyway.
- A page cut costs some deduplication: the next backup chunks the bytes around the cut
  differently and re-uploads about one chunk per cut. With the default 20-second ticks a
  large table sees one or two cuts per backup. Keeping the chunker's pending bytes across
  ticks, encrypted, would avoid it and is a possible later optimisation.

## Consequences
- No plaintext database content on disk during backups; smaller disk footprint.
- Per-table files make partial restores (one table) natural and the manual CLI path simple
  (`restic dump` of the directory, then import in file order).
- Many tables mean many small blobs; harmless for restic.

## As built during Milestone 3 (2026-11)
The writer selects every insertable column explicitly, so invisible columns are included.
An expression default (`DEFAULT_GENERATED`) does not make a column generated; only virtual
and stored generated columns are omitted from INSERTs.

The first table importer is specified in [ADR 0035](0035-transactional-shadow-import.md).
It validates the SQL grammar, uses owned InnoDB shadows and commits each row batch together
with its progress. It uses the existing mysqli session without `$wpdb` retries or filters;
maintenance and plugin-state preservation belong to the separate confirmed swap. Schema
mapping, job integration and live apply remain open.

## Revised after the 0.3.0-rc.1 review (2026-11-22)
The consistency the decision claimed did not hold. A table without a single-column integer
primary key, `wp_term_relationships` among them, was read as `ORDER BY ... LIMIT n OFFSET m`
in separate statements: an insert or a delete before the offset made the dump skip a row
or write it twice, and a table without a primary key had no `ORDER BY` at all. Keyset pages
of one tick read the table at different moments, so a deleted and re-inserted row could
appear twice within a tick. The definition was read again on every tick, so a column added
between ticks produced INSERT statements that the CREATE already written did not fit.
TIMESTAMP values were read in the session's time zone, usually `SYSTEM` with daylight
saving time, but only the offset at the start was recorded, and text was read in the site's
connection character set while the manifest said utf8mb4. `SqlFormatter::one_line()` used
`\R` without the `u` modifier, which also matches the byte 0x85 inside UTF-8 characters
such as "х", "内" and "Å". A row above 16 MiB failed the whole backup. The decision above
replaces the earlier "per page" consistency, and the state file and the cursors carry a
version: a cursor of the earlier version makes its table start over, and a dump that the
earlier version started keeps the time zone it recorded until it is done, so that all of
its files agree with its manifest.

The engine gained the means to start a virtual file over: `ResumableByteSource::restarted()`
makes `BackupRun` drop the chunks of the earlier parts, and `warnings()` hands a complete
source's warnings to the run (`docs/02` §6).

The dump's own connection holds a table's metadata lock until the end of its part of the
tick, at most about one tick of 20 seconds: an `ALTER TABLE` on that table, and the queries
that queue behind it, wait that long. `mysqldump --single-transaction` holds it for the
whole dump.

## Alternatives considered
- **Single dump file spooled to disk** (ADR 0005): plaintext on disk, restart from zero,
  poorer deduplication.
- **`mysqldump` via a process**: no processes on shared hosting.
- **A transaction on `$wpdb`**: WordPress and other plugins use that connection in the same
  request; a transaction there would also hold their writes and could be committed or lost
  by them.
- **Pages by position inside a snapshot for tables without a key**: the order of rows
  without `ORDER BY` is not guaranteed between statements, and every page reads all rows
  before its offset again.
- **Failing the backup for a table without a key that does not fit into one tick**: no
  backup at all, files included, until an administrator changes the table; the marker and
  the warning keep the backup and say what may be wrong.
- **Writing a second CREATE into the file when a definition changes**: the command-line
  client would import it, but the plugin's importer accepts one CREATE per file; starting
  the file over keeps the format.
- **Refusing rows above the importer's limit** (as 0.3.0-rc.1 did): loses data that the
  command-line client can import; the manifest now tells an importer what it needs.
