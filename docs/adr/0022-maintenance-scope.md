# 0022 · Maintenance in PHP: forget with restic's semantics, prune without repacking, structural check with sampled data
Status: accepted
Date: 2026-11-09

## Context
restic's `forget` is a pure policy computation over snapshot metadata. `prune` downloads
partially used packs, repacks live blobs and rewrites the index, which is bandwidth- and
CPU-heavy. `check` verifies structure cheaply and data expensively. The native engine must
offer maintenance that fits bounded ticks on shared hosting while keeping the repository in
a state every restic tool understands.

## Decision
- **Forget** reimplements restic's `ApplyPolicy` exactly (newest first; `last`, `hourly`,
  `daily`, `weekly` with ISO weeks, `monthly`, `yearly` buckets keeping the first snapshot of
  each new bucket value and the oldest while counts remain; `keep-within` relative to the
  latest snapshot; `keep-tag`). Parity is tested against `restic forget --dry-run --json`.
- **Prune-lite**: mark blobs referenced by all snapshots, delete packs with no referenced
  blob, write new index files without them, then delete the superseded index files. Packs
  with a mix of live and dead blobs are left alone; the dashboard reports the reclaimable
  amount and the guide explains `restic prune` from any computer. Exclusive lock.
- **Check**: structural (index ↔ listed packs with sizes, snapshot tree walks resolve, no
  duplicate ids) plus a random sample of packs (`read_data_subset_percent`, default 2)
  downloaded and fully verified (header, blob tags, blob ids). Findings raise a persistent
  critical notice; repairs are never automatic.
- All three are resumable steps under the tick model and respect append-only mode (403 →
  `repository_append_only`; lock handling unaffected).

## Consequences
- Repositories stay fully consistent for restic; nothing the plugin does needs a later repair.
- Space from partially used packs is reclaimed only by a full prune elsewhere; growth is
  bounded in practice because WordPress media rarely changes and database dumps deduplicate.
- A full "prune with repack" in PHP remains possible as a later enhancement (download pack,
  rewrite live blobs into a new pack, upload, delete) and is on the backlog.

## Alternatives considered
- **Full prune in PHP in 1.0**: feasible but slow and risky under tick limits; deferred.
- **No retention at all in the plugin**: leaves every user with unbounded growth.
