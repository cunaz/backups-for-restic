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
  and restore read under a shared lock, kept as backups keep theirs: the server is asked
  for the lock once per `advance()` call, at its start, and before and after a refresh.
  Inside the call only the clock is compared with the time the lock was taken or
  refreshed: a lock that is due is refreshed, and a lapsed one stops the call. A lock that
  falls due inside a call is asked for before it is replaced, and a missing one stops the
  call: a refresh writes a new lock whether or not the old one is still there, so it would
  otherwise hide a removal by another client from every later call. After a lock gap,
  reacquire it at the start of a call, verify that the snapshot remains, and load the
  current index: cooperating prune may have moved blobs to different packs without
  changing their content IDs. The checkpoint names the new lock only after the snapshot
  was seen under it, so a call that ends in between is followed by one that looks again.
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
- Staging is owner-only. A staged file is `0600` and a staged directory `0700`, whatever
  mode the snapshot recorded; the original mode is kept in the report for the apply step.
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
  streams, leading ASCII spaces, trailing spaces/dots, invalid Unicode, long components
  and reserved device names, including superscript COM/LPT digits and the `CONIN$`/`CONOUT$`
  [console handles](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilea#consoles).
  Device names with spaces before an extension, console names with extensions and names
  containing `~` are conservatively refused on Windows to avoid device/short-name aliases.
  A key made with simple, one-to-one case folding supports collision checks: Windows
  compares names character by character, so a sharp s and "ss" stay distinct there.
  Every node of a visited directory must be a safe path component (not empty, `.`, `..`,
  and without `/` or NUL). The limits of the destination, such as the component length,
  apply only to entries that are visited, by the rules of the destination platform: an
  unselected sibling is never written and cannot exceed them.
  These rules follow [Microsoft's filename documentation](https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file).
  Leading ASCII spaces follow [Microsoft's whitespace rules](https://learn.microsoft.com/en-us/troubleshoot/windows-client/shell-experience/file-folder-name-whitespace-characters);
  padded devices follow the conservative [CPython reserved-name check](https://github.com/python/cpython/blob/main/Lib/ntpath.py).
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
refuses recorded backup errors, and finds every selected path. It counts at most 128 blobs
per step, persisting the current file path, blob ordinal and accumulated size. A file's
totals and traversal position advance together only after all its blobs are counted. A
lease refresh cannot publish a half-counted batch, and an interrupted batch replays from
its last committed ordinal. Free-space checks cover the target and work filesystems,
with per-entry rounding and a 16 MiB reserve. They are an
estimate, not a reservation: every later write is still checked. One decoded tree is cached
at a time and its names are validated once per path, avoiding a directory-wide scan for
every file. Unselected siblings are passed over inside one step and do not get a
checkpoint each. Index loading compares the clock with the lease between index documents
and refreshes a lock that is due; it sends no lock request otherwise. An ordinary tick
sends one lock request, a tick that refreshes at its start two, and a tick that refreshes
later three: its start, the lock that is due and the lock that replaced it.

`Files` checks directory identity, parent components and regular-file identity, rejects
symbolic and hard links, completes short writes and checks seeks/truncation/flush/close.
A persisted directory or file creation intent allows the narrow gap between creating an
entry and saving its identity to be replayed. A committed partial file binds device/inode
and cannot silently grow missing bytes with a sparse truncate. This relies on a private
job-owned directory: PHP has no portable openat/no-follow primitive protecting against
another local process that controls those directories. `Files::open()` creates files
under a umask of `077` and sets `0600` on every open, so no staged file, report or
checkpoint exists with a wider mode, also when a request is killed right after creating it.
A `start()` that was killed while it published its first checkpoint leaves only
`restore.json.tmp`; the next `start()` removes it, because without `restore.json` no run
exists. Beside a published checkpoint the directory belongs to a run and is refused.

Aborting still attempts repository-lock cleanup if publishing the aborted checkpoint fails;
even a second cleanup failure cannot replace the original checkpoint error.

`entries.jsonl` stays beside `restore.json`, outside the payload. Each selected node has
its original path, type, mode, mtime, size and raw link target; every entry is committed
once. Payload modes are fixed: `0600` for files and `0700` for directories. They never
carry the group, other or special bits of the snapshot, and they keep the owner access
that checkpoints, downloads and the later apply need. Original modes remain
in the report for the separate apply operation. Uncommitted report suffixes are truncated
on resume; the report is never accumulated in checkpoint memory.

Unit tests resume after each unit, replay creation and write gaps, and cover stale objects,
replaced destinations, lost/repacked repository state, lock gaps during reads, throttling,
low disk space, exactly-once report entries, bounded/interrupted per-file planning and
cleanup of unrecorded locks or a failed abort checkpoint. They pin the owner-only modes,
the number of lock requests per tick, a lock removed before a refresh inside a tick and
the repeated start after a killed one. The conformance suite compares restic-created
full and selected restores, including raw names and links represented only in the report,
for the current and minimum supported restic versions: names, types, times and content
come from staging, modes from the report.
Final-head CI remains the merge gate.

## Remaining release work
This engine is not yet a WordPress restore feature. Jobs, the browser, downloads and expiry
follow in phases 2.2–2.5. Full-job memory under 128 MiB remains a separate release gate;
bounded blob groups do not bound every decoded index/tree document or the decompressor's
allocation before checking its output size. Windows name rules are unit-tested, not a
claim of real Windows filesystem acceptance.

Two known costs remain for the job package (phase 2.2) and later work:

- The index is loaded again in every tick. It lives only in the PHP process, so every
  resumed request lists and downloads all index files before its first step, and
  `start()` and `resume()` take no index cache, unlike backup and check. A repository
  whose index takes longer to fetch than a tick allows makes one traversal step per tick.
  The restore job has to hand in the index cache.
- One decoded tree is kept at a time. After the traversal returns from a child directory
  the parent's tree is downloaded, decrypted and decoded again, so a directory with N
  subdirectories is fetched N + 1 times in each of the two passes.

## Alternatives considered
- Flatten selected basenames: unrelated selections can overwrite each other.
- Keep every file's complete contents in memory: memory grows with the file size.
- Coalesce every location of one pack regardless of file order: requires reordering or
  retaining unrelated plaintext, complicating bounded resumable writes.
- Create snapshot links while staging: later code could follow them outside the target.
- Reuse an index after a lock gap: a concurrent prune can invalidate its pack locations.
