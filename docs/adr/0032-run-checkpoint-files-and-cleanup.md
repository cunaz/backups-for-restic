# 0032 · Complete working-file writes and cleanup by run ownership
Status: accepted
Date: 2026-11-12

## Context
A backup keeps completed directory entries in local files and records their committed
lengths in its checkpoint. Creating one of those files and truncating an uncommitted tail
were not checked. A failed read was cast to an empty string, so a directory whose saved
entries became unreadable could be written to the repository as empty. Other local write
failures were reported as damaged repository data.

Job cleanup resumed an engine run to release its lock. That required a compatible
checkpoint, and for backups a usable plan, index and node files. The very failure that
ended a job could therefore prevent it from releasing its lock.

## Decision
- A backup checks creation, permissions, complete writes, truncation, close, replacement
  and removal of its working files. A short write is continued until all bytes are written;
  a refused write stops the operation. Node files and temporary checkpoint files have mode
  `0600` before content is written. The checkpoint is replaced only after its complete
  temporary file has been closed successfully.
- Reading saved directory entries must return exactly the committed byte count. An
  unreadable or truncated file ends the backup with `StorageFailed`, which the job layer
  reports as an environment problem. It cannot become an empty directory in a snapshot.
  Progress is recorded only after the relevant write succeeds. A later resume trims
  uncommitted node bytes before appending more entries.
- The positive integer `pid` field, from 1 to 2147483647, is a stable ownership field of
  every engine checkpoint, independent of `version`. Future checkpoint layouts must keep
  its name, type and meaning. Normal resume still refuses a checkpoint version it does
  not understand.
- Job cleanup uses `Engine\CheckpointLock` to read that ownership field and release only
  authenticated repository locks with the current host name and that run number. It
  ignores the checkpoint's `lock` field and does not construct a run, build a backup plan,
  load an index or inspect node files. This also removes locks written just before a
  request ended without recording their IDs.
- An unreadable or malformed checkpoint, or an invalid run number, authorizes no lock
  deletion. Existing cleanup error handling applies; a lock that cannot be identified or
  whose server cannot be reached eventually becomes stale.
- Shared recursive cleanup also checks directory listing, unlink and directory removal.
  A failure raises `StorageFailed` instead of passing silently, including during cache and
  job-history removal. Symbolic links are removed without following them. Earlier removals
  are not rolled back. The job runner records a cleanup error and proceeds to the next
  cleanup step, so a retained local file cannot keep a job active indefinitely.

## Consequences
- Local storage failures cannot silently publish missing directory contents and are not
  described to administrators as repository corruption.
- Unknown checkpoint versions and damaged working files no longer prevent cleanup when
  the ownership field remains readable. Checkpoints are still read as a whole; the memory
  measurements and checkpoint reductions of phase 0.9 must include this cleanup path.
- Permission tests, a real partial write induced by a POSIX file-size limit, and job-layer
  regressions cover the failure paths. Cleanup tests keep another run's lock, including
  when an unfamiliar checkpoint names it, and a different host's lock with the same number.
  Separate filesystem regressions cover refused unlink, directory listing and directory
  removal; a job-layer test verifies that its lock is already released when removal fails.

## Alternatives considered
- **Resume and immediately abort:** repeats the failing plan, index or file operation and
  rejects unknown checkpoint versions before cleanup can act.
- **Delete the recorded lock ID regardless of layout:** interprets an unfamiliar field and
  does not establish that the lock belongs to this run.
- **Continue with whatever bytes could be read:** can publish an incomplete directory as a
  successful backup.
