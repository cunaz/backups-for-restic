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
  in the meantime, so the run looks whether the repository changed and starts marking again
  if it did (see "As built"). If marking meets a used blob or tree
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

## As built (2026-11)
- The check has no finding for a blob id that occurs twice. Duplicates are legal in a restic
  repository, and the index keeps the first location. What the check reports is a whole
  pack that more than one index file lists, as the warning `pack_in_several_indexes`.
- A pack that no index file lists is the warning `pack_unindexed`. Until 2026-11 prune-lite
  did not delete such packs, because it took its candidates from the index files only; what
  a cancelled or failed backup left behind stayed until a `restic prune` from another
  computer. Since then the plan phase lists the packs on the server and adds those that no
  index file lists to the packs to delete, as restic's prune does. That is safe for the
  same reasons as the rest: marking ends with an error when a snapshot uses a blob the
  index does not lead to, every used pack must be among the listed ones before anything is
  deleted, and under the exclusive lock no client uploads packs. A backup of this plugin
  that was paused for longer than its lock lasts loses its unindexed packs to such a prune,
  notices it and starts over ([ADR 0030](0030-lock-continuity.md)). Verified against restic
  0.19.1: `restic check` counts the same packs as additional files before, and none after.
- After a time without a valid lock, prune no longer starts marking again in every case. It
  takes the lock again and compares the snapshots with those it marked, and the index files
  with those it read apart from the ones it has written or deleted itself. If both are the
  same, no client added a snapshot, repacked or pruned, so what the run found still holds
  and it goes on where it was. Before, a site whose ticks were more than 25 minutes apart
  marked again at every tick and never finished a prune that needed more than one. If
  something changed, marking starts over, at most three times; then the job fails with
  `repository_changed`. A restart while deleting used to leave behind the packs whose index
  entries were already removed; the next plan now finds them as packs that no index file
  lists.
- The setting for the sample is `schedule.check.read_data_subset_percent`.
