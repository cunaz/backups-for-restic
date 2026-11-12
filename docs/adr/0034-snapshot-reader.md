# 0034 · Read snapshots under shared locks and locate manifests independently of the live site
Status: accepted
Date: 2026-11-12

## Context
The snapshot browser and restore planner need directory listings and the site manifest.
The current site's private directory is not a reliable location for an older snapshot:
the site may have moved, or an administrator may be restoring another site. Existing
snapshots have no manifest locator. Browsing must not race a cooperating prune client,
retain a stale in-memory index between requests or write plaintext site metadata to disk.

## Decision
- `Engine\Snapshot\Reader` takes a fresh shared repository lock for each public operation
  and releases it in `finally`. It lists snapshots by an optional host and required tags,
  loads individual snapshot metadata, reads a directory by path and reads the site
  manifest. The index is loaded only on demand, after the lock, and discarded before the
  lock is released. Snapshot enumeration checks the lock around each metadata read and
  filters the host and tags before retaining snapshots for sorting. Longer walks refresh
  the lock after five minutes; a read that crosses
  the lock's trust interval is refused and must be retried from the beginning. No operation
  changes snapshots, indexes or data packs.
- Paths name entries inside the snapshot, never paths on the live filesystem. They must
  be absolute, at most 4096 bytes and 256 components. Empty, dot, parent, slash-containing
  and NUL-containing components are refused; a trailing directory slash is allowed.
  Raw name bytes are preserved. Links are displayed as nodes and never followed.
- Tree blobs may be cached as `cache/<repo-id>/trees/<tree-id>.bin`, encrypted with the
  repository key. Every cache hit authenticates the ciphertext and verifies the plaintext
  hash. Corruption is a cache miss. Trees larger than 4 MiB are not cached. Published tree
  and index files share `advanced.cache_limit_mib`; index bytes take priority, and either
  writer evicts trees as needed. A reader enforces a reduced limit on its first read;
  further cache hits in that instance do not rescan the directory. File authentication,
  size checks and symbolic-directory refusal still apply to each read.
  Temporary ciphertext can require additional disk space
  during replacement, as for the existing index cache.
- New backup plans add a `backups-for-restic-manifest:<base64 path>` tag, naming the
  manifest's absolute path inside that snapshot. The tag is part of the encrypted snapshot.
  The existing plugin/site/run tags remain; the locator is not a retention grouping key.
  A duplicate or malformed locator is refused rather than choosing one silently.
- Without a locator, the reader searches directory nodes for `spool/manifest.json`, visiting
  a shared subtree at each path so that an ambiguous location is not hidden. The search
  stops after at most 4096 directories and requires an explicit manifest path if it cannot
  establish a unique answer. An unfinished search never reports the manifest as absent.
  A directory with a recorded read error also prevents a complete search. A caller may
  supply an explicit snapshot path, including for nonstandard layouts.
- A manifest is a regular file of at most 1 MiB and 128 blobs. Each blob authenticates and
  matches its content ID, and the final length must match the tree entry. The reader
  validates `backups-for-restic-site/1` and its required field types. This metadata remains
  untrusted input to the later restore planner; reading it does not authorize an import,
  path mapping or filesystem write.

## Consequences
- Warm directory browsing can avoid loading the index or any pack. Cache limits count the
  index and trees together, and clearing the existing cache directory removes both.
- Another site's snapshots remain available through the repository-wide view. The site
  view uses the host and both required tags, matching retention's selection contract.
- New snapshots can locate their manifest after migration without scanning the site's
  entire directory tree. Old or unrelated snapshots may need an explicit selection, or
  have no site manifest at all. File browsing still works without that metadata.
- This is the engine reader of phase 1.1. Routes, CLI commands and the browser follow in
  phases 1.2 and 1.3. Large-index/full-job memory work remains phase 0.9; this decision does
  not claim that all existing repository reads fit a low PHP memory limit.
- A killed browsing request can leave a shared lock until it becomes stale under the
  existing lock protocol. It cannot leave partially restored files because browsing has
  no live-site or staging writes.

## Alternatives considered
- Using the live private-directory path would silently miss manifests after a migration.
- An unrestricted recursive search on every request would make a large or crafted
  snapshot keep a PHP request busy indefinitely. Explicit locators and a bounded legacy
  search make that limit visible to the caller.
- Plaintext tree caches would persist file names and metadata outside an explicit restore.
- Separate full-size budgets for index and trees would double the configured cache limit.
