# 0005 · PHP-native streaming database dump into the spool
Status: superseded by [0021](0021-database-streaming.md) — the database is streamed per table into the native engine; no spool file
Date: 2026-11-09

## Context
The database must be part of the backup. Options: shell out to `mysqldump`/`mariadb-dump`
(not always installed, version/flag differences, credentials handling), stream a dump from
PHP into restic's stdin (`--stdin`, needs a long-lived PHP process), or write a dump file to
disk and let restic back it up together with the files.

## Decision
- A PHP-native dumper streams the database through `$wpdb`'s connection into
  `<private>/spool/database.sql` (mode `0600`) in the plugin's own, deliberately simple
  format `backups-for-restic-dump/1`: one statement per line, hex-encoded binary, ~1 MiB `INSERT` batches,
  a trailer with statement count and SHA-256.
- The dumper is resumable across ticks (cursor per table/primary key). In WP-CLI attached
  mode the dump runs in one pass inside `START TRANSACTION WITH CONSISTENT SNAPSHOT` when all
  tables are InnoDB.
- The dump and `manifest.json` are included in the **same snapshot** as the files (ADR 0009)
  and deleted after the run.
- The importer executes the file line by line through `$wpdb` after validating header and
  trailer; it preserves the plugin's own options across the import.

## Consequences
- Zero extra binaries; identical behaviour on every host.
- Tick mode gives per-table consistency only; documented, with attached mode as the
  recommendation for write-heavy sites.
- Views are supported; triggers, routines and events are not dumped in 1.0.
- A plaintext dump exists briefly in the private directory — mitigated by `0600`,
  directory protection, the HTTP probe and immediate deletion.

## Alternatives considered
- **`mysqldump` via `--stdin-from-command`**: fastest and consistent, but an external
  dependency with credential handling (`--defaults-extra-file`) and a second snapshot per
  run; planned as an optional accelerator.
- **Streaming into restic's stdin from PHP**: elegant (no plaintext on disk) but requires
  the PHP process to live for the whole upload — exactly what ADR 0004 avoids.
- **Standard `mysqldump`-compatible SQL format**: tempting for interoperability, but our
  format *is* standard SQL; the restriction "one statement per line" is what keeps the
  importer parser-free.
