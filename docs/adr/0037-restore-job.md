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
  (0700) of the staging directory and of `files/`. The entries below keep the permission
  bits of the snapshot plus owner access (ADR 0036): the 0700 parent protects them, not
  their own modes.
  The run's checkpoint, local lock and growing report live in `jobs/<job-id>/restore/`
  and are deleted when the job ends.
- **Partial staging is removed on failure and cancel.** Only a job that ends as
  `succeeded` leaves its staging directory. Half a tree looks like a whole one to whoever
  finds it later, and the report that would tell them apart is incomplete too. A cancel
  request that arrives after the last file is staged no longer cancels: the result is
  recorded, as for the other job types.
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
  and the entry of the last restore.
- **Restore jobs are not reported by e-mail.** An administrator starts a restore and
  watches it, as with an unlock; failure streaks and recovery notices are for unattended
  jobs.

## Consequences
- A long restore delays scheduled backups until it ends. The stale-backup notice still
  fires if that takes longer than the schedule allows.
- Staged files are plaintext site data in the private directory. Until expiry, listing and
  removal exist (package 2.3 of the Milestone 3 plan), a staging directory stays until the
  plugin is uninstalled or somebody removes it by hand.
- If the cleanup of a failed job cannot remove the staging directory, the job ends as
  failed with the directory partly there; the expiry of package 2.3 has to treat a staging
  directory whose job did not succeed as garbage.
- The engine reports too little free space as a format error, so the job shows the code
  `repository_damaged` for it. A code of its own belongs to the engine and the error
  table.
- The WP-CLI command, the screens, the download and applying files to the live site are
  separate packages; this decision only fixes where they find the files and the report.
