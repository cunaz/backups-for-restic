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
- **Prune-lite**: mark the packs that hold a blob used by any snapshot, write the index files
  that list an unused pack again without it, delete the superseded index files, and delete
  the unused packs last, so that every state in between is a repository restic accepts.
  Packs with a mix of used and unused blobs are left alone; the dashboard reports the space
  they hold and the guide explains `restic prune` from any computer. The run holds an
  exclusive lock from the first tree it reads to the last pack it deletes; if the lock
  disappeared or was not refreshed within 25 minutes, other clients may have written
  snapshots in the meantime, so marking starts again. If marking meets a used blob or tree
  that the index does not lead to, or planning finds a used pack that no index file lists,
  the index was read before another client changed the repository: marking runs again once
  with the index as it is under the lock. Prune refuses without deleting anything when that
  does not help, when the repository lists no snapshots, and when the server is append-only
  or the probe cannot tell. Verified against restic 0.19.1: it deletes exactly the packs that
  `restic prune -v` counts as unused, and `restic check --read-data` passes afterwards
  (`Engine\Prune\PruneRun`).
- **Check**: structural (index ↔ listed packs with sizes, snapshot tree walks resolve, no
  duplicate ids) plus a random sample of packs (`read_data_subset_percent`, default 2)
  downloaded and fully verified (header, blob tags, blob ids). A pack streams in ranged
  pieces of 8 MiB that never split a blob, so memory stays bounded by the piece and the
  largest blob, not by the pack size (restic allows packs of up to 128 MiB). PHP cannot carry
  a SHA-256 state into the next request without `unserialize()`, which the plugin does not
  use, so one pack is verified within one request; a marker written before the read counts
  requests that end during it, and after three the pack becomes the warning
  `pack_unverified` instead of stopping the check for good. Errors raise a persistent
  critical notice; warnings are kept with the result but raise no notice, because they
  call for no action on the site: restic itself only prints them as hints (for example a
  pack no index lists, which the next `restic prune` removes, or a sampled pack that could
  not be read within a request). Repairs are never automatic. The check holds a shared
  lock, as restic's does, so backups may run meanwhile. Damage becomes a finding and the
  check goes on; transport errors end the tick and the job retries. Errors are what restic
  check reports as errors; warnings are its hints. Verified against restic
  0.19.1: on a repository with one damaged and one deleted pack, both report the same packs
  (`Engine\Check\CheckRun`).
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
