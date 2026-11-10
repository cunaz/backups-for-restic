# Changelog

All notable changes are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

### Added
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

### Fixed
- Retention removes old snapshots again. Forget grouped snapshots by all their tags, and
  every snapshot carries the tag of the job that wrote it, so each was the newest of a group
  of its own and none was ever removed. Forget now selects this site's snapshots by host
  name, the plugin's tag and the site's tag and applies the policy to them as one group
  (`restic forget --host <host> --tag backups-for-restic,site:<hash> --group-by host`).
- Prune stays safe when requests fail or end at the wrong moment: a tree that was missing
  once is still walked when marking could not start over, so its packs are not taken for
  unused ones; a request that ends while prune deletes is continued in that phase, and the
  packs it had planned are deleted; a start that was interrupted no longer leaves an
  exclusive lock that blocks every client for 30 minutes.
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
