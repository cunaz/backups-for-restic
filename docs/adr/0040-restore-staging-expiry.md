# 0040 · Restore staging expires, and a removal sets the directory aside first
Status: accepted
Date: 2026-11-16

## Context
A restore job that succeeded leaves the selected files in `<private>/restores/<job-id>/`
([ADR 0037](0037-restore-job.md)). They are plaintext copies of site data outside the
encrypted repository, and nothing removed them: the longer they lie there, the more a later
exposure of the private directory gives away. A job that failed and could not remove its
partial staging leaves a tree that looks whole. A tree of many files takes longer to delete
than a request or a heartbeat may run, and the screens and the download of the later
packages must never be offered a tree that is half gone.

## Decision
- **One class, `Restore\Staging`, without WordPress.** It lists the staging directories,
  removes one for an administrator, and removes what is due for the heartbeat. It gets the
  `restores/` folder, the job store and a clock.
- **Staging expires a number of hours after its job finished.** The setting
  `restore.staging_expiry_hours` is 24 by default, at least 1 and at most 168. The time
  comes from `finished_at` of the job document. The setting applies to what is already
  staged. What has expired is not listed any more, also before a heartbeat has removed it.
- **The list is read from the job documents, not from the trees.** Snapshot, number of
  files and bytes are what the job recorded as its result. A request must not depend on
  how much was restored.
- **A directory whose job document is gone** was pruned with the old jobs
  (`advanced.keep_jobs`). It is still listed when it holds the report, which a job moves
  there only after its last file; snapshot, counts and the time it finished are unknown.
  Its expiry counts from the creation time in the job ID. That is earlier than the unknown
  end, so such a directory never stays longer than a known one.
- **Leftovers go at once.** The directory of a job that failed or was cancelled, of a job
  that is not a restore, a directory without job document and without report, and a link
  or a file under the name of a job are not staging. They are never listed and are removed
  by the next heartbeat.
- **Some directories are never touched:** that of a job that has not ended, that of a job
  whose document this version cannot read, because nothing proves that such a job has
  ended, and names in `restores/` that are not job IDs.
- **A removal sets the directory aside, then deletes it.** The directory is renamed to
  `.removing-<job-id>` in the same folder, which is one atomic step and takes it off every
  list. The deletion then runs until a deadline, and a later call continues it. Everything
  due is set aside before anything is deleted, so a short deadline delays the deletion and
  not the end of the offer. A symbolic link is removed as a link and never followed. A
  directory is read 256 names at a time, so a huge one costs no memory. An entry that
  another request removed in the meantime counts as removed.
- **The heartbeat removes what is due as its last duty,** for at most five seconds, and
  without the runner lock. A long backup holds that lock tick after tick, and staged
  plaintext would outlive its time meanwhile. The lock is not needed: the directory of a
  job that has not ended is spared, and a job document names a terminal state only after
  the last step of the job.
- **Two routes:** `GET /restores` answers the list, the setting and how many directories
  are on their way out; `DELETE /restores/{id}` removes one, for at most ten seconds, and
  says whether the deletion is complete.

## Consequences
- Staged plaintext lives for the configured hours, then until the next heartbeat, then
  for as many heartbeats as a large tree needs. On a site without visitors and without a
  system cron the heartbeat comes with the next request. It is not offered past its expiry
  in any case.
- Lowering the setting removes older staging at the next heartbeat.
- Deactivation unschedules the heartbeat and removes no staging, so a deactivated plugin
  keeps staged plaintext past its expiry until it is activated again or uninstalled. The
  first heartbeat after the activation removes what is due.
- A staging directory whose job document this version cannot read is never listed and
  never removed, for example after a downgrade. Nothing proves that its job has ended; a
  version that reads the document, or the uninstall, removes it.
- A directory that the file system refuses to delete stays under its `.removing-` name.
  It is counted in the answer of `GET /restores` and every heartbeat tries again; nothing
  reports it by name yet.
- The heartbeat removes the preview data of file reviews and the undo workspaces of
  applies that are due before it looks at `restores/`. Since 2026-11-21 one that the file
  system refuses to remove, or whose job document cannot be saved afterwards, is counted
  as failed and tried again by the next heartbeat; it never keeps the staging of other
  restores from expiring. Before, one such failure stopped every later expiry.
- A private directory that the configuration names through a symbolic link is used by its
  real path ([ADR 0007](0007-private-directory.md)), so the checks that refuse a path
  whose real location differs from its spelling hold for it.
- The download of package 2.4 and the apply of phase 4 have to find their files through
  the list of `Staging`, so that expired staging is not served. A job that reads the
  staging of another job has to become known to `Staging`: today a directory is protected
  only while the job it is named after is active.
- The `last_restore` entry of the state keeps naming a staging directory after it is
  gone. Screens take what exists from `GET /restores`.
- Deleting staging after a successful apply belongs to the apply package.

## As built (2026-11)

- "A job whose document this version cannot read" includes one whose document cannot be
  looked up, below a job directory that the PHP user cannot search, or that is something
  other than a file (`JobStore::has_document()`): its staging is neither listed nor removed,
  and `DELETE /restores/{id}` answers `job_unreadable`. A directory whose job document is gone,
  which is established only where the job's directory or its document is missing, and whose
  report cannot be looked up is not touched either: it is neither staging with a report nor a
  leftover without one. Before (until 2026-11-26), both counted as gone, so that the staging
  expired by the time in its ID, and a directory without a report that could be read was
  removed at once as a leftover ([ADR 0041](0041-guarded-file-changes.md), As built).
- The undo workspace of an apply is removed once its window of 24 hours is over or its records
  establish that no window is open; records that cannot be established keep it, and the
  staging and the sealed review of the apply, for the window, as an open window does.
