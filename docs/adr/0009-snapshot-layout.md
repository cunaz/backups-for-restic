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
