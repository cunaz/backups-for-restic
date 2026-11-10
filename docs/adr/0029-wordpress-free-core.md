# 0029 · A WordPress-free core behind adapters in one namespace
Status: accepted
Date: 2026-11-09

## Context
The engine, the jobs, the settings model, the secret store and the database dump must be
testable without WordPress: the development environment cannot always run WordPress, the
restic CLI and a database server together, and unit tests that boot WordPress are slow and
hide bugs behind globals. The architecture asked for "a thin WordPress shell around a
WordPress-free core" but left the boundary to discipline.

## Decision
- Every WordPress call lives in `BackupsForRestic\WordPress` (adapters such as `WpOptions`,
  `WpHttpClient`, `WpdbConnection`, `WpExposureCheck`, the REST routes, the admin page,
  cron, Site Health, WP-CLI and the `Container` that wires everything) or in the composition
  root `BackupsForRestic\Plugin`.
- The core talks to WordPress only through small ports: `Storage\Options`,
  `Backend\Http\HttpClient`, `Database\Dump\Connection`, `Storage\ExposureCheck`.
- The core returns stable codes instead of sentences; `WordPress\Messages` translates them.
- Two PHPStan rules enforce the boundary on every change: `WordPressFreeCoreRule` rejects
  WordPress functions and classes outside the two allowed places, and `SecretRevealRule`
  keeps `Secret::reveal()` on a reviewed list of classes.

## Consequences
- The whole backup job, from settings and encrypted secrets to restic-verified snapshots
  and a database import, is tested without WordPress against rest-server and MariaDB.
- The adapters are thin and type-checked against the WordPress stubs; their behaviour is
  covered by the integration suite against a real WordPress in CI.
- A future WP-CLI-only or binary engine reuses the core unchanged.

## Alternatives considered
- **Brain Monkey or similar function mocking**: tests the adapters' calls, not their effect,
  and adds a dependency.
- **Booting WordPress in every test**: slow, needs a database, and blurs which layer failed.

## As built (2026-11)
- A fifth port came with the notifications of Milestone 2: `Notifications\Mailer`, with the
  adapter `WordPress\WpMailer`.
- The integration suite covers part of the adapters. It requests 11 of the 16 REST route
  paths and drives jobs through `wp-cron.php`; Site Health, WP-CLI, the multisite guard,
  deactivation and uninstall have no test
  ([development guide](../04-development-and-quality.md) §5.3). Tests of this wiring are
  planned for Milestone 3.
