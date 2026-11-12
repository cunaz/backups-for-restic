# 0030 · A run trusts the repository only while its lock holds
Status: accepted
Date: 2026-11-10

## Context
A restic lock protects a client for 30 minutes from the time written into it; after that
every other client calls it stale, and `restic unlock` removes it. restic itself refreshes
its lock every five minutes from a process that runs until the work is done. A job of this
plugin runs in ticks, and nothing runs between ticks. A site without visitors and without a
cron job, a host that is down for an hour or a job that waits for a retry can leave more
than 30 minutes between two ticks.

Until now a run that came back after such a gap took its lock again and went on. What
other clients had done in the meantime was not examined:

- **Backup.** The snapshot is written last. Until then nothing in the repository protects
  the packs the run uploaded or the older blobs it decided to reuse; only the lock keeps a
  prune away. An administrator who sees a stale lock runs `restic unlock` and `restic
  prune`; prune removes everything no snapshot uses. The backup then finished with a
  snapshot that referred to data that was gone, and only a later check said so.
- **Check.** Index files and snapshots that a prune or forget removed during the gap were
  reported as damage.
- **Order.** A tick loaded the index and then looked at its lock. An index read while
  another client's prune was still deleting could list packs that no longer existed.
- **Start.** Backup, check and forget took their lock and then wrote their first
  checkpoint. A request that ended in between left a lock that no later request
  recognised, because the run's number existed only in memory. Prune already wrote its
  checkpoint first.

Clocks add to this: a lock is judged by the clock of the client that reads it. A lock that
is 28 minutes old for the plugin is 31 minutes old for a client whose clock runs three
minutes ahead.

## Decision
1. **A lock is trusted for 25 minutes** (`Locking::TRUSTED_SECONDS`), five minutes less
   than other clients honour it. A run that finds its lock older than that, or gone, takes
   it again with `Locking::acquire()`, which fails with `repository_locked` while another
   client holds a conflicting lock. Prune used this margin already.
2. **The lock comes before the index in every call.** `start()` and `resume()` of
   `BackupRun` and `CheckRun` send nothing to the server; `ForgetRun::start()` sends only
   the append-only probe, a delete of a random snapshot name. `advance()` first takes or
   refreshes the lock and only then loads the index, through the `IndexCache` it
   was given. What the index lists can then no longer be removed by a client that honours
   locks.
3. **`start()` writes the checkpoint with the run's number before any lock exists.** The
   first `advance()` takes the lock. A request that ends between writing a lock and
   recording its name leaves a lock that the next request, or `abort()`, recognises by the
   number and removes.
4. **A backup keeps a record of what it relies on, and checks it whenever its lock did not
   hold.** The record names the index files the run has read its index from or written
   itself, and the packs it uploaded that no index file lists yet. It only grows. Every
   blob the run refers to was found in one of these index files or lies in one of these
   packs. restic's prune and the plugin's own delete an indexed pack only after every
   index file that lists it, and packs that no index lists at any time; index files are
   named by their content. So if every index file and every pack on the record is still
   there, nothing the run uploaded or reused is gone.

   The run makes sure of its lock at the start of every call, and again in every checkpoint
   and at the end, each time after the packs are uploaded and recorded and before an index
   file or the snapshot is written. A lock that still exists and is younger than 25 minutes
   has held since the run last looked, so nothing is compared. A lock that is gone or older
   is taken again, and the record is compared with the server. If something is missing the
   run is given up: its checkpoint is deleted, its lock released, and `advance()` throws
   `RepositoryChanged`. This also holds within one long call and in the first call, which
   has no earlier checkpoint to compare with: an index file must not list packs that were
   removed while the lock did not hold, and a snapshot must not refer to them. A call that
   cannot take the lock again, because another client holds an exclusive lock by now, has
   recorded what it uploaded before it asked, and the next call goes on from there.

   The checkpoint names the new lock only after the comparison. A request that ends between
   the two, because a listing fails or the host stops it, leaves the old lock in the
   checkpoint, so the next request takes the lock again and compares again. A lock written
   a moment ago says nothing about the time before it. The lock that was written and not
   recorded is recognised by the run's number and removed, as in decision 3.

   Each call reads the index from the index files that the server lists at that moment and
   compares its record with that listing, which costs no request. An index file that is
   missing there although the lock held, or that goes between the listing and the reading,
   was removed by a client that does not honour locks; the run is given up in the same way.
5. **The backup job then starts a new run** with the next tick. What the old run uploaded
   and still exists is found by deduplication and not sent again; the database dump lists
   its tables again. After three such restarts (`BackupSteps::MAX_RESTARTS`) the job fails
   with the code `repository_changed` instead of going on for ever.
6. **A check that took its lock again compares the index files and snapshots it listed**
   with what the server lists now. If one is gone it starts over from its first phase with a
   new listing, at most three times (`CheckRun::MAX_RESTARTS`), then it fails with
   `repository_changed`. If nothing is gone it continues: new index files, packs and
   snapshots of other clients do not concern a check that began before them. The check
   looks once more before it ends, so that what it found in a call that lost its lock is
   not reported as damage. Like the backup, it records a lock it took again only after it
   has compared.
7. **Forget needs no comparison.** It holds an exclusive lock and removes snapshot files it
   selected by a policy. A snapshot another client wrote during a gap can only make the
   policy remove more, never keep one this run removes, and deleting a file that is already
   gone succeeds.

## Consequences
- A snapshot is never built on data that a prune removed during a pause. The conformance
  suite proves it against `restic unlock` and `restic prune`: the job starts over once and
  `restic check --read-data` finds no error; with the comparison switched off the same test
  fails.
- A gap costs two listings (index files and packs) when it happens. Without a gap a call
  costs one request for the lock at its start and one in every checkpoint, and the listing
  of the index files it reads the index from. A site whose ticks are often more than 25
  minutes apart still finishes its jobs, because nothing is repeated unless something was
  removed.
- A client that removes or rewrites index files without honouring locks makes a running
  backup start over, even if the data is still there. Clients that work without locks
  exist; rustic is one.
- The index is read later in a tick than before, after the lock request. The index cache
  keeps this cheap.
- The checkpoints of backup, check and forget have a new version. A job that is active
  while the plugin is updated fails once with its old checkpoint; the next one runs. The
  initial implementation left that job's lock until it became stale. Cleanup now reads the
  stable run number independently of the checkpoint version and releases authenticated
  locks belonging to that run without resuming it
  ([ADR 0032](0032-run-checkpoint-files-and-cleanup.md)). If the ownership record itself is
  unreadable, the lock stays until it is stale, 30 minutes at most. No released version has
  written the old checkpoints.
- A lock left by a killed first request no longer blocks other clients for 30 minutes.
- Prune reads the index files under its exclusive lock when it plans and compares them
  with what marking found. After a gap it compares the snapshots and the index files with
  those it marked and read, goes on if they are the same, and starts marking again if not,
  at most three times ([ADR 0022](0022-maintenance-scope.md)). It deletes the packs that no
  index file lists, which is why decision 4 names the unindexed packs of a backup.

## Alternatives considered
- **Start over after every gap.** Simple, but a site with long pauses between ticks would
  never finish a large backup or a check.
- **Verify every blob of the snapshot before writing it.** Certain, but it means walking
  all trees of the new snapshot and looking up every blob at the end of every backup, for a
  case that the comparison of a few names decides.
- **Write a snapshot early and replace it.** A partial snapshot would protect uploaded
  data from a prune, but other tools would list and restore it as a backup.
- **Refresh locks from a separate cron event between ticks.** It shortens gaps where cron
  works, and where cron works there are no gaps; it does nothing for the sites that have
  them.
