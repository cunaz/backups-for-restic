# Changelog

All notable changes are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

### Added
- The stored settings and every job carry a layout number, so that later versions can
  convert them. A version installed over a newer one stays inactive and says so instead of
  acting on settings it may misread. The plugin also stays inactive if the database refuses
  to record the first layout number. Unreadable job documents are kept and block new jobs
  and repository changes until a compatible version can read them.
- A running backup shows a progress bar and the minutes left, measured against the last
  backup.
- The dashboard shows how much the repository holds, measured after every backup and
  every prune.
- WP-CLI: `settings list`, `settings get` and `settings set`, `cancel` and `logs`.
- The plugin records when the recovery kit was shown and to whom; the Settings tab shows
  the latest time, and the action `backups_for_restic_recovery_kit_shown` fires.
- The environment checks and Site Health point out a JIT compiler that is switched on,
  and a repository server that does not answer from this host.
- Settings → Repository: forms to enter the REST password and the repository password
  again, for example after the security keys in `wp-config.php` changed, and to upload or
  remove the CA certificate of the server. Values that `wp-config.php` defines are marked.
- On hosts where a site cannot request its own address, the browser of the administrator
  checks that the working directory is not served, in the setup wizard and on the
  Environment tab. Before, the check stayed "could not be verified" on such hosts.
- E-mail notifications: the first failure of a backup, forget, prune or check, once when
  backups become stale (36 hours by default, at least one and a half backup intervals), once
  when they work again, every check that finds damage, and an automatic switch to retention
  mode external. Unsent notices are retried.
- Dashboard cards for old snapshots, unused data and the repository check, with buttons;
  notices for check findings, the switch to external retention and the proposal to switch
  back. A check with findings is also shown on every admin screen and in Site Health.
- Prune estimates the space that only a full `restic prune` from another computer reclaims.
- A daily append-only probe by the heartbeat, so a switch back is proposed even when no
  forget or prune job runs.
- Settings for old snapshots, maintenance and notifications; a gentle CPU mode for hosts
  with CPU limits; a cache size limit, a cache size display and a button to clear the cache.
- `readme.txt` for WordPress.org and guides for cron jobs and for an own rest-server.
- Maintenance jobs: forget after every successful backup, prune and check on their schedules,
  and on demand from the REST API and WP-CLI (`forget`, `prune`, `check`). Forget and prune
  detect append-only servers first and then switch retention to external without deleting
  anything; switching back needs an administrator. A check with errors raises a lasting alert.
- Repository check: index files, packs, snapshot trees and a random share of packs downloaded
  and verified completely, resumable in short ticks; it reports the damage `restic check`
  reports.
- Prune without repacking: deletes the packs no snapshot uses, resumable in short ticks under
  an exclusive lock, verified against `restic prune` and `restic check --read-data`.
- First backup in WordPress (Milestone 1): setup wizard for an own restic REST server or a
  hosted one such as BorgBase, with connection test, append-only detection and an optional CA certificate; a new
  repository with a generated password or an existing one unlocked in the background; a
  recovery kit shown once and later only after re-entering the WordPress password.
- Backup jobs in resumable ticks on WP-Cron with loopback chaining, admin polling and a
  five-minute heartbeat that starts scheduled backups; one job at a time; cancellation.
- Database dump streamed into the snapshot per table, resumable per page, importable with
  `cat *.sql | mariadb`; site manifest; default excludes for caches and other backup
  plugins' archives.
- Encrypted secret store keyed by the salts in `wp-config.php`, with constants that override
  it; settings schema with validation; private working directory with an HTTP exposure
  check; local encrypted index cache.
- Dashboard, snapshots, settings, environment checks and logs in the admin area; Site Health
  tests; WP-CLI commands `doctor`, `backup`, `tick`, `status` and `snapshots`; uninstall
  removes everything except the repository.
- Database and WordPress integration test suites, a disposable test site script, and PHPStan
  rules that keep the core free of WordPress and `Secret::reveal()` on a reviewed list.
- Engine core (Milestone 0): pure-PHP implementation of the restic repository format
  (format 1, and format 2 when PHP has `ext-zstd`), REST backend with
  append-only detection, resumable backup and restore, forget with restic's policy
  semantics, and locking that restic respects.
- Poly1305 in pure PHP: the engine needs nothing from sodium.
- Conformance suite with the restic CLI (0.19.1, 0.18.1, 0.14.0) and rest-server as oracle,
  including a 300 MB streamed database dump and every server status code the plugin maps.
- Built-in structure-aware fuzzer for every parser that reads server data.
- Benchmarks with the Milestone 0 gates, mutation testing configuration, a PHPStan rule
  that keeps the engine core free of WordPress, and CI workflows.

### Changed
- The setup wizard lists an own restic REST server first and preselects it; BorgBase is the
  second option, and `repository.server` defaults to `rest-server`.
- Retention needs `keep.within` of at least one day or `keep.last` of at least 1. The
  settings refuse anything below (`retention_floor`), and a forget job that finds such a
  pair stored fails without deleting anything.
- A notification that could not be sent is tried again after 15 minutes, 1 hour, 6 hours
  and 24 hours, not at the next four heartbeats. It is dropped after the fifth refusal or
  after two days.
- With `advanced.loopback_chaining` off, creating a job no longer requests `wp-cron.php`;
  the heartbeat and an open admin page advance the job.
- Prune streams its pack listing to a private file and processes it in resumable batches.
  Unindexed pack IDs stay on disk, so a large listing cannot exhaust PHP memory by itself.
  The complete listing is validated before any index rewrite or deletion begins.
  A live pack missing from that listing stops prune before it can remove an unindexed
  recovery copy of the missing data.
- Prune also deletes the data that a cancelled or failed backup left on the server without
  ever listing it, as `restic prune` does. Until now only a prune from another computer
  removed it.

### Security
- The plugin reads no more of a server's answer than a repository file of that kind can
  hold. Before, a broken or malicious server could answer the request for a lock or a
  snapshot with a body larger than PHP's memory.
- Repository requests verify the server's certificate even when other code on the site
  switches certificate verification off for all requests (a filter on `https_ssl_verify` or
  on the request arguments, or the `http_api_curl` action).
- After the setup, `POST /repository/connect` and `POST /repository/create` answer with the
  recovery kit only if the request carries the administrator's WordPress password, like
  the recovery kit route; before, any session with the plugin's capability got the
  repository password and the REST credentials from them. A refused connect no longer
  replaces the stored password or drops the cached key.
- The stored REST password is only sent to the host, port and user name it was saved for.
  A connection test or a save for another server must carry the password
  (`password_required`); before, a request that named another host got the stored password
  sent there.
- Backup, forget, prune and check jobs cannot start before the setup is completed and the
  recovery kit acknowledged (`setup_incomplete`), neither through the REST API nor WP-CLI.
- A repository password chosen by the administrator must have at least 12 characters
  (`password_too_short`); a secret is read back after it is stored, and saving fails if the
  options table did not keep it.
- Job files and logs also redact the REST credentials in the form the `Authorization`
  header carries them.

### Fixed
- Reading a snapshot now refuses directory listings with duplicate or out-of-order names,
  including differently escaped copies of the same name. The repository check reports
  these trees as damaged before a restore can use their entries.
- Local backup working-file failures now stop the job with an environment error. Refused
  creation, incomplete writes and failed truncation cannot advance a checkpoint, and an
  unreadable saved directory cannot silently become empty in a snapshot.
- Job cleanup releases locks by the checkpoint's run number without resuming the engine.
  It works with unknown checkpoint versions and damaged node files, and needs no backup
  plan or repository index. Other hosts' and other runs' locks are kept.
- Failed recursive cleanup of private working files or caches is reported as a local
  storage error instead of silently leaving files behind. Other cleanup actions still run,
  including removal of an unconfirmed password. If the server refuses deletion, retention
  switches to external even when local cleanup fails.
- A backup that was paused for longer than its repository lock lasts could end with a
  snapshot that referred to data a `restic prune` on another computer had removed in the
  meantime. The backup now checks after such a pause that everything it relies on is still
  in the repository, and starts over if not. It does the same when its lock is removed
  while it works, before it writes an index file or the snapshot. A check starts over
  instead of reporting as damage what another computer pruned or forgot during its pause.
- A request that ended at the very start of a backup, check or forget could leave a
  repository lock that blocked other programs for 30 minutes. Every run now records its
  number first and removes such a lock itself.
- On a site whose scheduled requests were more than 25 minutes apart, a prune that needed
  more than one request started from the beginning every time and never finished. It now
  goes on where it was unless another program changed the repository in the meantime, and
  gives up with `repository_changed` after starting over three times.
- A prune that had to start over while it was deleting left data behind that nothing
  removed later. The next pass now finds and deletes it.
- Settings that the database refused to store no longer pass for saved: the request answers
  with an error, and saving a server puts back what it had changed. WordPress reports such a
  refusal the same way as an unchanged value, so it went unnoticed before.
- Saving a server that fails half way, for example because the database refuses a write,
  leaves the site with the server, the REST password and the key it had. Before, the new
  address could stay with the old password or without the certificate that belongs to it.
- Where `wp-config.php` defines the repository address, testing another address with a
  certificate of its own no longer replaces the stored certificate.
- What the plugin remembers between requests (last results, handled schedule slots, who
  looked at the recovery kit, waiting notices) is no longer overwritten when two requests
  change it at the same time, for example a running backup and the admin page. A notice is
  not sent twice when two requests try to deliver it at once.
- Deactivating the plugin just as a backup or a check finishes no longer leaves the job
  active and its lock in the repository: the result is recorded and the job cleaned up in
  the same request, and recording the result of a check no longer needs the server.
- A wrong repository password entered under Settings → Repository no longer replaces the
  stored one. Before, the stored password and the cached key were gone as soon as the form
  was sent, and backups failed until the right password was entered. Now the entered
  password is kept apart and takes effect only once the unlock job has opened the
  repository with it.
- The setup wizard sends the CA certificate along with the connection test. Before, the
  test ran without it and a server with a certificate of its own authority could not be
  set up. The test stores nothing; the certificate is kept when the server is saved.
- Connecting to an existing repository now records its ID and format version. Before, the
  settings kept format 1, so the Doctor did not ask for the zstd extension, and the recovery
  kit and the site manifest named the wrong format.
- A repository of format version 2 is refused when a job unlocks it on a server without the
  zstd extension, instead of failing later in the first backup.
- After a change of the salts in `wp-config.php`, a site that defines both passwords there
  goes on backing up: the cached repository key is derived again. Before, every job failed
  until the stored secrets were deleted by hand.
- Creating a repository stores its password before the repository exists. Before, a failure
  between the two left a repository that nobody could open.
- The last backup, the last forget, prune and check and the check alert belong to one
  repository and are dropped when the jobs turn to another one. The stale notice then counts
  from that day.
- Saving another server or creating a repository is refused while a job is running.
- Saving a new REST password for the same address keeps the cached repository key.
- Deactivating the plugin ends the running job and releases its lock in the repository.
- The setup cannot be completed by a request while the Doctor reports a critical finding.
- A request to cancel that arrives while a step is running is honoured by the same tick.
- A request to cancel that arrives just after a backup wrote its snapshot no longer ends
  the job as cancelled with the snapshot left unrecorded; the job ends as succeeded.
- A CA certificate that could not be written to disk is no longer announced as uploaded.
- `wp backups-for-restic tick --until-idle` and the commands with `--wait` returned at once
  when another tick was working on the job. They now wait for it.
- A cancel request that arrived while a tick was running could be lost, or overwrite the
  progress the tick had just saved, and two requests at the same moment could both start a
  job. A cancel request is now a marker file that the runner reads before every step, and
  jobs are created under a lock.
- The check alert lists errors before warnings; many warnings of an early phase can no
  longer push the errors off the list.
- The dashboard's append-only line shows the latest conclusive probe of the current
  repository. An inconclusive probe no longer replaces it with the wizard's answer, and a
  result for another repository is ignored.
- Uninstalling also removes the working directory selected with the
  `backups_for_restic_private_dir` filter.
- The recovery kit is refused (`ca_certificate_missing`) when the settings name an uploaded
  CA certificate that is missing, instead of leaving the certificate out silently.
- The texts of the admin page can be extracted for translation: every call passes the text
  domain as a literal. The settings page no longer claims that repeated checks read the
  whole repository; each check reads a random sample.
- Retention removes old snapshots again. Forget grouped snapshots by all their tags, and
  every snapshot carries the tag of the job that wrote it, so each was the newest of a group
  of its own and none was ever removed. Forget now selects this site's snapshots by host
  name, the plugin's tag and the site's tag and applies the policy to them as one group
  (`restic forget --host <host> --tag backups-for-restic,site:<hash> --group-by host`).
- Prune stays safe when requests fail or end at the wrong moment: a tree that was missing
  once is still walked when marking could not start over, so its packs are not taken for
  unused ones; a request that ends while prune deletes is continued in that phase, and the
  packs it had planned are deleted, as long as the lock was not lost in between (after a
  lost lock marking starts over, and packs whose index entries are already gone stay until
  a full `restic prune`); a start that was interrupted no longer leaves an exclusive lock
  that blocks every client for 30 minutes.
- After the site was connected to an existing repository, the cleanup after each job
  removed the index cache of the repository in use, or left the caches of earlier
  repositories, because it went by a setting that only a newly created repository sets. It
  now goes by the repository whose key is cached.
- A check keeps what it has checked when a request fails (it writes its checkpoint at every
  change of phase and every five seconds), and a repository that this PHP cannot read
  (format 2 with compression, no zstd extension) is reported as that, not as damaged.
- A check no longer hangs on a pack that the host cannot read within one request: the
  requests that end while reading it are counted also when they read other packs first,
  and after three the pack is reported as unverified.
- A backup, check or forget that no request advanced for more than 30 minutes takes its
  repository lock again instead of refreshing a lock other clients no longer honour, and
  waits for a client that holds a conflicting lock by then.
- A backup or forget whose start fails after it took its lock releases the lock.
- The key derivation table is created with mode 0600.
- The test site of the integration suite runs without PHP's JIT, with which PHP 8.3
  crashed in backup ticks, and with WordPress's memory ceiling at the 128M of the
  acceptance criteria.
- A request that died while refreshing a repository lock no longer leaves the new lock
  behind; leftovers of the same run are removed and never block it.
- Forget counts `keep-within` back from the newest snapshot that is not in the future, as
  restic does; a snapshot from a wrong clock no longer makes it remove snapshots that
  restic keeps.
- Timestamps before the year 100, such as Go's zero time, are read exactly instead of as
  two-digit years, and dates that do not exist are refused.
- Pack headers longer than restic allows are refused.
- A prune that is retried after a failed read keeps every pack that a snapshot uses, and
  a long prune or check refreshes its lock while it works.
- Cancelling a forget releases its lock even when the retention settings became unusable.
- A `keep-within` value that is not a non-zero duration, and notification recipients that are
  not e-mail addresses, are rejected when the settings are saved instead of failing later.
