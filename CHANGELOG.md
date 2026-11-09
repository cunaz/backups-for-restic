# Changelog

All notable changes are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

### Added
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
