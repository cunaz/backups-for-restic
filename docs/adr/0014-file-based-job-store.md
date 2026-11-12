# 0014 · File-based job store
Status: accepted (revised in Milestone 1)
Date: 2026-11-09

## Context
Job state must be shared between PHP processes (ticks), survive restarts, be written
atomically, and sit next to the ledger and logs. A database restore replaces the
options table and would wipe any database-stored job state in the middle of the restore job.

## Decision
- Each job is a directory `<private>/jobs/<job-id>/` with `job.json` (atomic
  temp-and-rename writes), `events.log` (redacted JSON lines, capped at 1 MiB), the backup
  engine's checkpoint in `backup/` and the database dump state in `database.json`. IDs sort
  by creation time.
- The active job is found by scanning for a non-terminal `job.json`; the history is pruned
  to `keep_jobs`.
- One runner at a time: `flock()` on `<private>/runner.lock`, plus a lease in `job.json`
  that a killed tick leaves behind and that expires one minute after its budget.
- Sensitive working state lives outside `jobs/`: the scrypt table and checkpoint in
  `kdf/<job-id>/`, deleted when the job ends; the merged index in `cache/<repository-id>/`,
  encrypted with the repository key.
- The database stores only settings, secrets, and a small `backups_for_restic_state` document (last
  success, exposure probe, schedule slot).

### Revised during Milestone 1
The separate backup ledger of the original design is gone: the engine's own checkpoint
(directory frames with node files, ADR 0018) already records every finished entry and is
resumable after any step.

## Consequences
- Database restores do not interfere with the running restore job.
- Readable finished job history is capped by count; unreadable documents are retained.
  Logs and state live together for downloads and support.
- Requires `flock` for the runner lock — a documented limitation on some network
  filesystems.

## Alternatives considered
- **Custom database table**: schema migrations, no co-location with checkpoints and logs, and the
  restore-wipes-state problem.
- **Options/transients**: object caches make transients unreliable for state; same restore
  problem.

## As built (2026-11)
The documents in the database that more than one request changes are the state, the
notifications and the secrets. Every change starts from the database and holds a `flock()` on
`state.lock` in the private directory until it is written (`Storage\FileLock`). Without
that, a tick that had read the state when it started wrote back its copy seconds later,
with everything that other requests had stored in between undone. Where `flock()` does not
work the changes are not serialised, as before; they still start from the database.
Notices are handed to the mailer by one request at a time (`notify.lock`, not waited
for), and one that is queued while a mail is on its way goes out with the next heartbeat
instead of at once.

The database holds one more option than the decision lists:
`backups_for_restic_notifications` (`Notifications\Notifier::OPTION`, not autoloaded), added
with the notifications of Milestone 2.

- It is the notifier's state: per job type the time at which the current run of failures
  began, the time of the last stale notice, and the outbox of notices that the mailer has
  not accepted yet.
- An outbox entry holds the kind of notice, times, the job type and job id, an error code,
  a count and the number of attempts so far. The WordPress layer writes the sentences of an
  e-mail only when it sends it, so the option holds no secret, no path and no log line.
- The outbox exists so that a mail server that is down for a while delays a notice instead
  of losing it: a notice the mailer refuses stays in the outbox and is retried over two
  days, then dropped.
- The code gives no reason why this state is an option and not a file in the private
  directory, where the job state lives.
- Uninstalling deletes it with the other options (`uninstall.php`).

With it the plugin writes four options: settings, secrets, state and notifications. Since
2026-11 a fifth one, `backups_for_restic_schema_version`, holds the layout number of
settings, state and notifications, and every `job.json` carries a `schema` field of its
own; a version that finds a higher number than it knows stays inert or does not load the
job (docs/02-architecture.md §18). An existing document that cannot be read is retained:
this version cannot establish that its job has finished, even if a raw state field looks
terminal. Under the creation lock it blocks both new jobs and `while_idle()` changes with
`job_unreadable`; pruning history does not remove it. A directory with no document does not
count as an existing job. The first options layout number is inserted atomically and never
overwrites a competing writer's value. A malformed options marker is preserved and keeps
the plugin inactive, including leaving jobs and locks alone during deactivation; only a
missing marker denotes legacy settings.
