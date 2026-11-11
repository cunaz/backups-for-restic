# 0011 · Scheduling and progress on shared hosting: WP-Cron, loopback chaining, external ping
Status: accepted (revised for 0018)
Date: 2026-11-09

## Context
Shared hosting offers no system cron for WP-CLI; at best a control-panel cron that can fetch
a URL. WP-Cron runs inside web requests and only when the site has visitors. Some hosts block
loopback requests. The native engine needs many short slices of work (ticks) per backup.

## Decision
- The runner advances jobs in bounded ticks (default 20 s, capped by `max_execution_time`).
- **Loopback chaining**: while work remains, the runner schedules `backups_for_restic_tick` for
  now and calls `spawn_cron()`, so the next tick starts within a second. WordPress' own
  `doing_cron` lock and the plugin's `flock` prevent overlap. Chaining can be disabled.
  Only a tick that advanced a job which still has work chains (`TickReport::should_chain`).
  A tick that found the runner busy, or a job waiting out its back-off after a retryable
  error (30 s, doubling up to five failures), does not chain: otherwise every request would
  start the next one until the lease or the back-off ends, which a host can count as a
  request flood.
- **Resuming**: a job that no request carries on is never lost. Its state is saved after
  every tick, and a tick holds the job's lease for its budget plus 60 s. When a chain breaks
  (a killed request, a blocked loopback, a back-off), the next heartbeat, admin poll or cron
  ping takes the job up again once the lease has expired or the back-off has passed.
- **External cron ping**: the documentation and the Doctor recommend a control-panel or
  external cron fetching `wp-cron.php?doing_wp_cron` every 1–5 minutes, optionally with
  `DISABLE_WP_CRON`. No plugin-specific URL or token exists.
- **Admin polling** performs short ticks while an administrator watches a job.
- A recurring heartbeat (5 minutes) creates due jobs, detects expired leases, refreshes
  repository locks and sends stale notifications.
- WP-CLI (`tick`, `backup --wait`) is supported where available but never required.
- **Gentle CPU mode** (`advanced.cpu_throttle = gentle`, for hosts that limit CPU time per
  request or per minute): each tick gets half the budget, and the engine rests a tenth of a
  second after every quarter second of work, between units of work and inside the long
  ones (the chunks of a large file, the pieces of a large pack). Rests lower the average
  load; they cap no single stretch of work, so a host's per-request limits still apply.
  Jobs take longer and resume as above.

## Consequences
- Works everywhere WordPress works; fastest with chaining or a cron ping.
- No new public surface: `wp-cron.php` is WordPress' own.
- Long first backups are expected and explained in the wizard.

## Alternatives considered
- **Secret trigger URL**: a long-lived bearer secret in URLs for little benefit over
  `wp-cron.php`.
- **Requiring system cron and WP-CLI**: excludes the target audience.
- **Action Scheduler**: see ADR 0002.

## As built (2026-11)
- Chaining does not call `spawn_cron()`. `WordPress\Cron::chain()` schedules
  `backups_for_restic_tick` for now, writes WordPress' `doing_cron` lock itself and sends a
  non-blocking request to `wp-cron.php`. Unlike `spawn_cron()`, that also works from inside
  a `wp-cron.php` request and more than once a minute; the running cron request sees the new
  lock holder and stops after its current event.
- Disabling chaining (`advanced.loopback_chaining: off`) turns `Cron::chain()` off in every
  place: after a tick, and after a job was created through REST or WP-CLI. No tick event is
  scheduled then and `wp-cron.php` is not requested. A job advances with one tick per
  heartbeat, every five minutes, and with the ticks of an open admin page; an external cron
  ping makes the heartbeat fire on time but starts no tick of its own.
- The heartbeat runs the append-only probe when it is due, starts the jobs that are due,
  runs one tick when a job is active and hands waiting notices to the mailer. It refreshes
  no repository lock.
- The wizard says that the first backup uploads everything once and names no expected
  duration: the plugin does not know the size of a site before it has walked it. From the
  second backup on the progress is measured against the bytes the last backup of the
  repository went through, and the admin page shows a bar and the minutes left at the
  speed so far (since 2026-11-11). A walk of the site before the first backup, only to
  have an estimate, was rejected: on shared hosting it costs ticks that the backup needs.
- Since 2026-11-11 the tick deadline holds back regular steps only. The cleanup steps of a
  job (release the repository lock, remove working files) run in the tick that reaches
  them, also past the deadline, and a request to cancel that arrives while a step ends or
  fails is turned into the cleanup by the same tick. The reason is deactivation: it asks
  the job to cancel and no later tick comes, so a job left between its cleanup steps would
  keep its lock in the repository for half an hour and stay active with nobody to finish
  it. The price is a request that can outlast its budget by the time limits of the
  cleanup's own requests.
