# 0036 · Plan selected files before restoring into an owned staging directory
Status: accepted
Date: 2026-11-12

## Context
The first engine restore has one selected subpath and writes files while traversing the
snapshot. It does not hold a repository lock, require an empty target, estimate the disk
space needed, or record links separately. The browser needs several paths without basename
collisions, useful progress, and recovery after a killed request.

## Decision
- A selection contains one to 256 absolute snapshot paths, each bounded to 4096 bytes and
  256 components. Validate all inputs before removing duplicates and descendants of an
  already selected path. Preserve each selected entry's original path below staging;
  two files with the same basename in different directories must remain distinct.
- `start()` records the run identity locally before its first repository lock. Planning
  and restore read under a shared lock, refreshing it during long work. After a lock gap,
  reacquire it, verify that the snapshot remains, and load the current index: cooperating
  prune may have moved blobs to different packs without changing their content IDs.
- A first resumable traversal validates the selected entries and counts files and bytes.
  It must also find every requested path. It writes no restored file until the complete
  selection and available space have been checked. Throttle applies during both traversals.
- The caller supplies an empty job-owned target and a separate private work directory.
  Bind resumed work to the target's canonical path and identity; refuse symlinks and
  unexpected replacements. All writes, seeks, truncations and checkpoint publications are
  checked. A local nonblocking lock must serialize requests, with the checkpoint reread
  after acquiring it so an older PHP object cannot overwrite newer progress.
- Before a file's first write, persist its intent. Resume truncates only that owned partial
  file to its committed byte offset. A completed file must match its declared size and
  every blob must authenticate and match its ID. Checkpoints advance only after successful
  writes. Recorded metadata must remain available for the separate apply planner.
- Links and special nodes are report entries, never created in staging. Report appends
  need a committed offset so a killed request cannot duplicate entries. Completion,
  cancellation and failure release the repository lock, including a lock left by a killed
  acquire or refresh. Expiry and removal belong to the staging job layer in phase 2.3.

## Components
- `Selection` validates the complete path list and removes overlapping selections. It
  tests component boundaries: `/a` does not select `/another`, and `/a-b` sorting between
  `/a` and `/a/child` cannot make the child a second independent restore.
- `Names` applies destination rules. Unix retains ordinary raw filename bytes, including
  names that Windows cannot represent. Windows rejects invalid characters, alternate data
  streams, trailing spaces/dots, invalid Unicode, long components and reserved device
  names, including superscript COM/LPT digits and the `CONIN$`/`CONOUT$`
  [console handles](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilea#consoles).
  Console names with extensions and names containing `~` are conservatively refused
  on Windows to avoid DOS short-name aliases. A case-folded key supports collision checks.
  These rules follow [Microsoft's filename documentation](https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file).
  They are unit-tested without claiming a real Windows filesystem acceptance run.
- `BlobBatch` groups consecutive, contiguous ranges of the same pack in file order,
  limited to 16 MiB and 128 blobs. Repeated, reordered, separated or cross-pack blobs end
  the group. Each result separately authenticates and matches its content hash. Data
  chunks retain restic's 8 MiB maximum; metadata is checked before a download, and a
  response must have exactly the requested length. A generator retains one downloaded
  group and yields plaintext one blob at a time.

## Run state, reports and recovery
`RestoreRun` checkpoint version 2 has `plan`, `restore`, `complete` and `aborted` phases.
Old version 1 checkpoints are refused. A legacy single-string selection is still accepted,
but now preserves its absolute snapshot layout like a list of paths. `State` validates
required fields and integer counters and refuses checkpoints above 4 MiB. Raw path bytes
are Go-quoted before JSON encoding.

The first traversal also checks each file's declared size against its indexed data blobs,
refuses recorded backup errors, and finds every selected path. Free-space checks cover the
target and work filesystems, with per-entry rounding and a 16 MiB reserve. They are an
estimate, not a reservation: every later write is still checked. One decoded tree is cached
at a time and its names are validated once per path, avoiding a directory-wide scan for
every file. Index loading checks/refreshes the lease between index documents.

`Files` checks directory identity, parent components and regular-file identity, rejects
symbolic and hard links, completes short writes and checks seeks/truncation/flush/close.
A persisted directory or file creation intent allows the narrow gap between creating an
entry and saving its identity to be replayed. A committed partial file binds device/inode
and cannot silently grow missing bytes with a sparse truncate. This relies on a private
job-owned directory: PHP has no portable openat/no-follow primitive protecting against
another local process that controls those directories.

`entries.jsonl` stays beside `restore.json`, outside the payload. Each selected node has
its original path, type, mode, mtime, size and raw link target; every entry is committed
once. Payload modes strip special bits and retain owner read/write (and directory search)
access so checkpoints, downloads and later apply remain possible. Original modes remain
in the report for the separate apply operation. Uncommitted report suffixes are truncated
on resume; the report is never accumulated in checkpoint memory.

Unit tests resume after each unit, replay creation and write gaps, and cover stale objects,
replaced destinations, lost/repacked repository state, lock gaps during reads, throttling,
low disk space, exactly-once report entries and cleanup of unrecorded locks. The conformance
suite compares restic-created full and selected restores, including raw names and links
represented only in the report, for the current and minimum supported restic versions.
Final-head CI remains the merge gate.

## Remaining release work
This engine is not yet a WordPress restore feature. Jobs, the browser, downloads and expiry
follow in phases 2.2–2.5. Full-job memory under 128 MiB remains a separate release gate;
bounded blob groups do not bound every decoded index/tree document or the decompressor's
allocation before checking its output size. Windows name rules are unit-tested, not a
claim of real Windows filesystem acceptance.

## Alternatives considered
- Flatten selected basenames: unrelated selections can overwrite each other.
- Keep every file's complete contents in memory: memory grows with the file size.
- Coalesce every location of one pack regardless of file order: requires reordering or
  retaining unrelated plaintext, complicating bounded resumable writes.
- Create snapshot links while staging: later code could follow them outside the target.
- Reuse an index after a lock gap: a concurrent prune can invalidate its pack locations.
