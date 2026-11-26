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
  limited to 16 MiB and 128 blobs, and to what the memory left can take (As built below).
  Repeated, reordered, separated or cross-pack blobs end the group. Each result separately
  authenticates and matches its content hash. Data chunks retain restic's 8 MiB maximum;
  metadata is checked before a download, and a response must have exactly the requested
  length. One downloaded group is held at a time, and its blobs are handed to the writer
  one at a time, each let go once it is written.
- A compressed chunk may be stored larger than its content, up to libzstd's bound for
  8 MiB (`Compression::bound()`) and the 32 bytes of encryption (`BlobBatch::max_length()`).
  restic compresses every data blob of a compressed repository, also data that does not
  compress, and stores a full chunk of such data in raw zstd blocks, 202 bytes longer than
  itself. Planning and reading refused that length until 2026-11; it occurs in about one
  chunk in 1,800 of random data, so in about one conformance run in 200.

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

## As built (2026-11)
- Free space that the host does not report, or reports as no number, is unknown, not too
  little: the run notes it in its checkpoint (`space_unknown`, absent from older
  checkpoints) and goes on, and the restore job says so in a warning. Every write is
  checked all the same. The default probe looks for `disk_free_space()` when the space is
  checked: PHP treats a function in `disable_functions` as undefined, and taking it as a
  callable in the constructor ended every restore on such a host. Free space known to be
  too little raises `StorageFailed` with the code `staging_space`.
- A file operation in the staging or working directory that the file system refuses (an
  open, write, flush, close, truncation, `mkdir`, `chmod`, `touch`, rename or removal, or an
  entry that cannot be inspected) raises `StorageFailed` with the code `staging_write`
  (`Files::WRITE_FAILED`), not a format error: the job layer reported those as damage in
  the repository. A selected path that the snapshot does not hold carries
  `restore_path_missing`. An entry that changed or is not what the run left there, and data
  from the repository that does not add up, stay format errors.
- `start()` and `resume()` take the index cache of the jobs, as backup and check do. Only
  the index files that the cache does not cover are downloaded, and the lease is compared
  with the clock around every one of them (`IndexCache::load()` takes the callback that
  `Repository::load_index()` takes). After a lock gap the run drops its index as before
  and loads it through the cache, which is not used when an index file it covers is gone.
- A group is read within the job's memory budget (`MemoryBudget`, ADR 0031). Beside an
  index of 1,000,000 blobs and WordPress, the run ended with PHP's fatal error in
  `Cipher::open()` or in the download, or refused a group of compressed chunks that it had
  already downloaded with `decompression_limit`. What reading a group takes follows from the
  copies that exist at once, measured on PHP 8.3.37 in 2026-11 and the same in a census of
  every step:
  - while the response arrives, three times the group: WordPress' HTTP API keeps the body it
    collects, the raw response with the headers in front, and the body cut out of that
    (3.0 times the body with cURL and with fsockopen; `MemoryBudget::RESPONSE_COPIES`), with
    up to 16 KiB of status line and headers;
  - then, for each blob but the last, the group beside what opening the blob takes: three
    copies of its stored length while it is decrypted (the slice, the ciphertext without
    nonce and tag, the plaintext), and for a compressed blob the decrypted frame and twice
    its content while it is decoded. `PackReader::open_blob()` lets the slice go before the
    frame is decoded (`MemoryBudget::opening()`);
  - the last blob alone: it takes the group with it (`MemoryBudget::slice()`).

  Since 2026-11-26 each copy counts as what PHP reserves for it at most
  (`MemoryBudget::footprint()`), not by its length: a string smaller than 2 MiB less 4 KiB
  goes into one of PHP's blocks of 2 MiB, which in a process whose blocks are full or cut
  up is a new one; a larger string gets memory of its own in whole pages of the system, of
  which the budget assumes the largest, 64 KiB (on Windows PHP rounds to 2 MiB). Each step
  also counts one block, for the small allocations of the read, or for the empty block that
  PHP keeps for reuse once a request let go of many small strings and that a string with
  memory of its own cannot use. So counted, a full chunk stored uncompressed takes
  26.2 MiB, three times 129 pages and a block, and a full chunk that restic stored
  compressed in 2.5 MiB takes 20.7 MiB, most of it for twice its content
  (`MemoryBudgetTest`).

  The plaintext of a blob is handed to the writer, which keeps no reference to it, and is
  let go before the next blob is opened. Until 2026-11 a generator yielded it, and its
  value and the caller's loop variable kept it while the next blob was decoded: one more
  chunk of up to 8 MiB at the peak. Before each download the run asks the budget what the
  memory left can take (`MemoryBudget::blob_room()`): the limit less the memory reserved
  and 2 MiB. The group ends before a blob that would not fit (`MemoryBudget::range()`),
  down to one blob; a first blob that does not fit by itself is refused before anything is
  downloaded, with `IndexTooLarge` and the code `restore_blob_memory`. A full chunk takes
  about 24 MiB, three times its size, both while it arrives and while it is opened, and
  26.2 MiB as the budget counts it. The index budget leaves at least 32 MiB once the index
  has loaded, so the refusal comes only when other memory was taken after that. A restore
  with memory enough sends the requests it sent before: beside WordPress and an index of
  500,000 blobs every group is as large as before (`ReadMemoryTest`). The staged files are
  the same byte for byte.

  | Peak reserved under 128 MiB, in MiB | 500,000 blobs, before | 500,000 blobs, now | 1,000,000 blobs, before | 1,000,000 blobs, now |
  | --- | ---: | ---: | ---: | ---: |
  | Format 1, full chunks | 88.5 | 88.5 | 116.5 | 118.5 |
  | Format 1, two chunks just under 8 MiB, one group of 16 MiB | 112.5 | 112.5 | fatal error in the download | 118.5, one chunk per request |
  | Format 1, many small blobs | 79.5 | 79.5 | 107.5 | 109.5 |
  | Format 2, full chunks that do not compress | 96.5 | 88.5 | 124.5 | 118.5 |
  | Format 2, full chunks that compress three times, three in a group | 101.2 | 90.6 | `decompression_limit` | 120.6 |
  | Format 2, many small blobs | 71.0 | 71.0 | 99.0 | 101.0 |

  Each restore ran in a fresh process beside WordPress' 34 MiB and a real index loaded from
  the cache, with every response held as WordPress holds it. Before the first blob was read,
  64.5 MiB were reserved beside 500,000 blobs, and 92.5 MiB (before) or 94.5 MiB (now)
  beside 1,000,000 blobs. An index of 2,000,000 blobs does not fit under 128 MiB and is
  refused with `index_too_large` before a blob is read, before and now.

- What the budget lets through is decoded without a refusal for memory (2026-11-26). Until
  then the budget counted lengths. The decoder, which measures the memory left once more
  before it decodes (ADR 0019), could then find less than the budget had left for it: PHP
  had reserved more for the copies than their lengths, kept the pages of small strings that
  the request had let go, or put a string of about 1 MiB into a block of its own. A blob
  read with exactly what the budget says it takes left beside the memory in use
  (`ReadMemoryTest`, `tests/Fixtures/memory/boundary.php`) ended a restore after its
  download, and a check reported it as damage:

  | Blob read at the boundary of the budget | Restore before | Check before | Both now |
  | --- | --- | --- | --- |
  | Full chunk in raw zstd blocks | `decompression_limit` | `blob_corrupt` | read |
  | The same, small strings made and let go while it arrives | fatal error in `Cipher::open()` | `blob_corrupt` | read |
  | Full chunk that compresses three times | `decompression_limit` | `blob_corrupt` | read |
  | The same, small strings while it arrives | `decompression_limit` | `blob_corrupt` | read |
  | 1.5 MiB that compresses, small strings while it arrives | `decompression_limit` | `blob_corrupt` | read |
  | 1.5 MiB that compresses, blocks cut up by strings of 1 MiB | read | read | read |
  | 1.1 MiB uncompressed, blocks cut up | fatal error in `Cipher::open()` | fatal error | read |
  | 200 bytes in a frame without a declared size, as restic writes it | `decompression_limit` | `blob_corrupt` | read |

  Now the budget counts what PHP reserves, and `PackReader::open_blob()` lets PHP give back
  the pages that freed strings keep before the decoder measures, as PHP does itself before
  it refuses an allocation; the decoder then finds what the budget found before the
  download, less what is alive now. A refusal that the decoder still makes is an
  `InsufficientDecodingMemory`, a limit of the host and not damage: for a frame without a
  declared size whose window takes more than the budget counted for its content (restic
  writes such frames only for blobs below 256 bytes, with a window of 1 KiB), or for a
  compressed blob beside memory that other code took while it arrived. A restore reports it
  as `restore_blob_memory`, after the download, and a check reports the pack as the warning
  `pack_memory`. An index file, a snapshot or a tree that a check cannot decode in the
  memory left stops it with `decompression_limit`, as an index that does not fit stops it,
  instead of a finding of damage; an index file is not left out of the index either, which
  would report its blobs as missing. The decompression limit of ADR 0019 is unchanged:
  content beyond 128 MiB, and a frame that declares more, are refused before they are
  decoded.

## Remaining release work
This engine is not yet a WordPress restore feature. Jobs, the browser, downloads and expiry
follow in phases 2.2–2.5. Full-job memory under 128 MiB remains a separate release gate;
bounded blob groups do not bound every decoded index/tree document or the decompressor's
allocation before checking its output size. Windows name rules are unit-tested, not a
claim of real Windows filesystem acceptance.

One known cost remains for later work; the other one of phase 2.2, the index loaded again
in every tick, is gone since the restore job hands in the index cache (As built above):

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
