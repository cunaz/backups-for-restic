# 0037 · A restore is a job that blocks other jobs and stages into a directory of its own
Status: accepted
Date: 2026-11-16

## Context
The engine restores selected paths of a snapshot into a private directory in resumable
slices ([ADR 0036](0036-selected-file-staging.md)). It needs a caller that owns the target
and the working directory, keeps it going across requests, releases its repository lock on
every path to the end, and says what became of the files. The plugin has such a caller for
backups and maintenance: the job runner with its steps, its lease, its cleanup steps and
its cancel request ([ADR 0014](0014-file-based-job-store.md)). The safety model asks that
every restore lands in staging first ([ADR 0013](0013-restore-safety.md)).

## Decision
- **A job type `restore`** with the steps `preflight`, `unlocking`, `planning`, `restoring`
  and `recording`, and the cleanup steps `releasing` and `cleaning_up`. `planning` and
  `restoring` drive the one `RestoreRun` of the job: started once, resumed from its
  checkpoint afterwards. A third step provider, `Jobs\Restore\RestoreSteps`, stands beside
  those of backup and maintenance and shares their preflight checks, unlock and cleanup.
- **The request is part of the job from its creation.** `Jobs\Restore\RestoreRequest`
  checks the snapshot ID (64 hexadecimal characters) and the paths (the engine's
  `Selection`; the whole snapshot when none are named) before a job exists, and the steps
  check them again when they read the job document. Paths must be valid UTF-8, because the
  job document is: other bytes would come back changed and name another entry. Such an
  entry is restored by selecting the directory that holds it.
- **A restore job blocks other jobs.** The job store allows one active job, and a restore
  is one. A backup, forget, prune or check that the schedule wants to start meanwhile is
  not started and its slot is not marked as handled: the heartbeat tries again every five
  minutes. Prune could not run beside a restore anyway, since its exclusive lock conflicts
  with the shared lock of the restore; one job at a time also keeps the load of a tick and
  the meaning of "the active job" as they are.
- **Staging per job under the private directory.** A job stages into
  `<private>/restores/<job-id>/`: the tree in `files/`, each entry under its full snapshot
  path, and the report of the entries in `entries.jsonl` beside it. The report is not
  inside the tree, so no name from a snapshot can take its place. Job IDs are unique, so two
  restores never share a directory, and what is staged inherits the protection of the
  private directory: deny files, the exposure check in the preflight, and owner-only modes
  of the staging directory, of `files/` and of every entry below (0700 and 0600 whatever
  the snapshot recorded, ADR 0036).
  The run's checkpoint, local lock and growing report live in `jobs/<job-id>/restore/`
  and are deleted when the job ends.
- **Partial staging is removed on failure and cancel.** Only a job that ends as
  `succeeded` leaves its staging directory. Half a tree looks like a whole one to whoever
  finds it later, and the report that would tell them apart is incomplete too. A cancel
  request that arrives after the last file is staged no longer cancels: the result is
  recorded, as for the other job types. This holds while the step `restoring` rehearses the
  database or builds the archive after the last file: the step itself answers the
  request, discards an unfinished archive or stops the rehearsal and removes its tables,
  and adds a warning (since 2026-11-21; the runner asks `JobType::cancels_before()` with
  the job's phase). A deactivation of the plugin is such a request too.
- **The lock is released on every path.** `releasing` aborts the run, which releases the
  repository lock even if a killed request left one behind that the checkpoint does not
  name. A run that completed has released its lock itself; if the second look for
  leftovers then fails, the job logs a warning and still succeeds, rather than throw away
  the staged files over a precaution.
- **A lost lock resumes, a few times.** A run whose lock did not hold gives up its tick.
  The next tick takes a new lock, verifies that the snapshot still exists and reads the
  index again. After three such ticks the job fails. A snapshot that the repository does
  not hold, because the request named an unknown ID or another client forgot it, does not
  come back: the job fails in the tick that finds out, without another attempt.
- **A finished restore survives a failing cleanup.** When `cleaning_up` cannot remove
  working files or old jobs after the run completed, the job logs a warning and still
  succeeds. Failing it would leave a failed job that owns a complete staging directory
  and the entry of the last restore. Since 2026-11-21 this holds for every failure of
  `releasing` and `cleaning_up` after a complete run, an Error of PHP included, such as a
  function that the host disabled, and for opening the repository to look for a leftover
  lock. It holds as well for a failure in the call that stages the last file, once the
  run's checkpoint says that every file is staged and the directories it names are still
  the ones the run bound: the release of the lock or the checkpoint written after it. The
  job keeps the files and goes on with a warning (log code `restore_release_deferred`);
  `releasing` asks for the lock again, and a lock that stays expires after 30 minutes.
- **Restore jobs are not reported by e-mail.** An administrator starts a restore and
  watches it, as with an unlock; failure streaks and recovery notices are for unattended
  jobs.

## Consequences
- A long restore delays scheduled backups until it ends. The stale-backup notice still
  fires if that takes longer than the schedule allows.
- Staged files are plaintext site data in the private directory. A staging directory
  expires, is listed and can be removed since package 2.3 of the Milestone 3 plan
  ([ADR 0040](0040-restore-staging-expiry.md)).
- If the cleanup of a failed job cannot remove the staging directory, the job ends as
  failed with the directory partly there; the heartbeat removes a staging directory whose
  job did not succeed as a leftover (ADR 0040).
- Too little free space, a failed file operation in the private directory and a selected
  path that the snapshot lacks have codes of their own since 2026-11-21: `staging_space`,
  `staging_write` and `restore_path_missing` ([ADR 0036](0036-selected-file-staging.md),
  As built). Until then the engine reported the first two as format errors, which the job
  showed as `repository_damaged`. Free space that the host does not report lets the
  restore go on with a warning.
- The WP-CLI command, the screens, the download and applying files to the live site are
  separate packages; this decision only fixes where they find the files and the report.
