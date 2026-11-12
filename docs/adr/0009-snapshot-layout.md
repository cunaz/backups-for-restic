# 0009 · One snapshot per run with files, database directory and manifest; stable host and tags
Status: accepted (revised for 0021)
Date: 2026-11-09

## Context
restic snapshots are per run. Files and database could be separate snapshots or one. Snapshot
grouping for retention depends on host name and paths; container and shared-hosting host
names are not meaningful.

## Decision
- One snapshot per run covering the distinct site roots plus a virtual
  `<private>/spool/` tree that contains `manifest.json` and `database/` (one SQL file per
  table plus header, views and a database manifest, ADR 0021). Files and database are always
  restored as a pair from one snapshot id.
- Snapshot `hostname` is the site's host name (from `home_url()`); tags are
  `backups-for-restic`, `site:<12 hex of sha256(home_url)>`, `run:<job-id>`. Forget selects
  the snapshots with this host name that carry the first two tags and treats them as one
  group ([ADR 0010](0010-retention-and-append-only.md)). The `run:` tag only names the job
  that wrote a snapshot; it is different on every snapshot, so nothing may group by it.
- `manifest.json` records plugin/WordPress/PHP/DB versions, repository format version,
  roots, excludes, table prefix, table list and per-file hashes, so a restore on a different
  layout can map paths.
- Every scheduled run produces a snapshot even when nothing changed (BorgBase's freshness
  monitoring relies on repository activity); an option to skip unchanged runs exists.

## Consequences
- The `site:` tag is computed from the site address. After the address changes (another
  domain, `http` to `https`), earlier snapshots carry another tag and no longer fall under
  this site's retention: they stay until someone removes them with restic. That is the safe
  direction; a site never removes snapshots it cannot tell from another site's.
- Simplest possible restore semantics; the database is found at a stable virtual path.
- Several sites may share a repository (distinct host/tags), but one repository per site
  remains the recommendation.

## Alternatives considered
- **Separate snapshots for files and database**: pairs must be reconstructed by tag.
- **Single `database.sql` file**: no per-table resumability; an interrupted dump restarts
  from zero; worse deduplication of large unchanged tables.

## As built (2026-11)
- New snapshots also carry `backups-for-restic-manifest:<base64 path>`, an encrypted
  locator for the manifest's original snapshot path. It survives a site move and is not
  a retention grouping key. Older snapshots are searched for a unique manifest, within
  the limits of [ADR 0034](0034-snapshot-reader.md).
- `spool/manifest.json` has the format `backups-for-restic-site/1` and records the time, the
  site address, `abspath`, `content`, `uploads`, `roots`, `excludes`, the table prefix,
  whether the database is included, the versions of the plugin, WordPress and PHP, and the
  repository format. The table list with rows and bytes per table and the version of the
  database server are in a second manifest, `spool/database/manifest.json`
  ([ADR 0021](0021-database-streaming.md)). Neither holds hashes per file: the trees of the
  snapshot already carry the id of every blob.
- Every run writes a snapshot. No option skips a run without changes; one is not planned
  yet.
- The snapshot list of the admin page and of WP-CLI shows every snapshot in the repository,
  whatever its host name and tags. A list limited to this site is planned for Milestone 3.
