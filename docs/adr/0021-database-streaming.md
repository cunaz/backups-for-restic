# 0021 · Database backup streams per table into the engine; no plaintext dump on disk
Status: accepted (supersedes 0005; revised in Milestone 1)
Date: 2026-11-09

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
  and text that is not valid UTF-8 as hex literals, `BIT` values as numbers; INSERT
  statements of about 1 MiB; every file starts with the session settings (character set,
  `sql_mode`, the dumping session's time zone) so that it also imports on its own. Each
  table file ends with `-- end table rows=<n> bytes=<n>`. Views lose their `DEFINER`
  clause so that they import on other servers; generated columns are left out of the
  INSERT statements and computed again on import.
- **Resumable per page.** A table is read in pages: along an integer primary key where one
  exists, otherwise by offset in primary-key order. When a tick's deadline passes, the table
  source finishes the current page, the chunker emits the bytes it holds as a final chunk,
  and the checkpoint records a cursor after that page. The next tick continues there. A row
  therefore always comes from exactly one read, even if the table changes between ticks, and
  a table larger than one tick can process still finishes.
- Consistency: per page in tick mode, as with a non-transactional `mysqldump`. A consistent
  InnoDB snapshot in WP-CLI `--wait` mode remains a later option.
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

## Alternatives considered
- **Single dump file spooled to disk** (ADR 0005): plaintext on disk, restart from zero,
  poorer deduplication.
- **`mysqldump` via a process**: no processes on shared hosting.
