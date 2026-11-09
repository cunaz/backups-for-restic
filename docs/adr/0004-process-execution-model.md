# 0004 · Detached processes supervised by a tick-driven state machine
Status: superseded by [0018](0018-native-php-engine.md) — the restic binary is no longer part of the product; the tick-driven job model lives on in 0018 without detached processes
Date: 2026-11-09

## Context
Backups take minutes to hours. PHP web requests (and WP-Cron, which is a loopback web
request) are limited to seconds; PHP-FPM may be restarted at any time; WP-Cron only runs
when the site has traffic. The plugin needs long-running restic work to complete reliably
regardless of how it was started, and it must know afterwards exactly what happened.

## Decision
- Work is modelled as **jobs** with explicit states persisted as JSON files. A **runner**
  advances the active job in bounded **ticks** triggered by WP-Cron events, admin polling
  requests, or `wp backups-for-restic tick` from system cron.
- restic runs as a **detached process**, started through one `proc_open` gate using a
  constant POSIX `sh` script that redirects output to files, records the PID and, after
  `wait`, the exit status. All variable parts are positional parameters, never interpolated.
- Liveness is checked via `/proc/<pid>/stat` with a start-time fingerprint; outcomes are
  derived from the status file plus restic's `--json` output.
- WP-CLI offers an **attached mode** (`--wait`) that runs restic as a direct child with
  pipes for operators who use real cron.
- Pure PHP steps (database dump, manifest, cleanup) are written to be resumable within the
  same tick budget.

## Consequences
- Progress survives request timeouts and PHP restarts; a killed restic is detected as
  `process_vanished`, never left as "running forever".
- Requires a POSIX shell and Linux `/proc` for the strongest guarantees; macOS/BSD work with
  the POSIX fallback; Windows is unsupported.
- The code has two launch paths (attached, detached) that must stay behaviourally
  identical — covered by tests that run the same command through both.
- Exposes the plugin to cgroup-level kills when PHP-FPM is restarted on systemd hosts; the
  job becomes `failed` and the next scheduled run continues (restic reuses uploaded data).

## Alternatives considered
- **Run everything inside the PHP request with `set_time_limit(0)`**: fails under FPM
  `request_terminate_timeout` and proxies; the dominant cause of broken WordPress backups.
- **Require system cron + WP-CLI only**: robust, but excludes managed hosts without cron
  access; kept as the recommended mode, not the only one.
- **Action Scheduler**: solves scheduling, not process supervision; large dependency.
- **A PHP CLI wrapper process instead of `sh`**: needs a PHP CLI binary that often differs
  from the web PHP; `/bin/sh` is universal.
- **`--stdin-from-command` to let restic drive the dump**: needs `mysqldump` or a PHP CLI;
  kept on the backlog as an accelerator (ADR 0005).
