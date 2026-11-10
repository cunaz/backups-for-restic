# 05 · Roadmap, milestones and decisions

The milestones are ordered so that the riskiest part, the native implementation of the
restic format, is proven first. Every stage ends with something verifiable against the
restic CLI and against real servers.

## Milestone 0 — Engine core and conformance

**Status (2026-11-10): implemented and verified, except the BorgBase measurements, which
need the owner's two free-tier repositories. An audit of the code against this plan
(2026-11-10) found two deviations in the engine, both fixed: a paused run refreshed a lock
other clients no longer honoured instead of taking it again, and the key derivation table
was not created with mode 0600. What the audit could not close is carried into
Milestone 3.**

| Acceptance criterion | Result |
| --- | --- |
| Plugin-written repository passes `restic check --read-data`, restore byte for byte | Met: a 20 MB fixture tree, checked by restic 0.19.1, 0.18.1 and 0.14.0, and a 300 MB streamed database dump, checked and restored by restic 0.19.1; incremental backups diff correctly in restic |
| Restic-written repositories restore identically in PHP | Met for format 1 (restic 0.19.1 and 0.14.0), with zero new data when the plugin backs up the same files. Format 2 needs `ext-zstd` (see below); CI covers it in a second job |
| Chunk-boundary, forget and append-only parity | Met: 17 of 17 chunk boundaries (one 25 MB stream from restic's chunker library; with the CLI indirectly, by zero new data blobs) and 11 forget policies over 150 snapshots in 4 time zones match restic's recorded results. The recorded policies are applied to one group; how the forget job selects and groups a site's snapshots is compared with live restic in Milestone 2. Append-only behaviour green on rest-server. BorgBase pending |
| Mutation score of `Crypto` and `Format` ≥ 90 %; fuzzing without open findings | Met. Mutation: 97 % of 2627 mutants in `Crypto`, `Format` and `Engine\Forget` killed or timed out, 70 escaped, none uncovered (CI, 2026-11-09). Fuzzing: 54 million inputs across 17 parsers in a 30-second-per-parser campaign, no findings |
| Benchmark gates on PHP 8.3 without JIT | Met with wide margins for what was measured: chunker 24 MB/s, scrypt 1.4 s in memory, backup 1.3 CPU minutes per GB, extrapolated from 256 MB written to an in-memory backend by one process ([benchmarks](benchmarks/2026-11-milestone-0.md)). CPU time across ticks to a real server, and the file-backed key derivation that the plugin uses, are not measured yet |

Findings that changed the design:
- PHP does not expose Poly1305; the engine computes it in pure PHP and needs nothing from
  sodium ([ADR 0027](adr/0027-poly1305-in-pure-php.md)).
- In format 2, restic compresses index and snapshot files even with `--compression off`, so
  format 2 repositories from restic always need `ext-zstd`
  ([ADR 0019](adr/0019-repository-format-and-chunker.md)).
- Fuzzing uses a built-in, structure-aware fuzzer instead of php-fuzzer
  ([ADR 0028](adr/0028-built-in-fuzzer.md)); a review for its targets found and fixed a
  type confusion in the server listing.
- restic before 0.16.1 reads REST credentials only from the repository URL; the test
  harness passes them there for old versions.

Moved to Milestone 1: the server profiles (BorgBase host check, CA certificate upload),
because they live in the settings and the WordPress HTTP client. The engine already accepts
only `https://` URLs without embedded credentials.

Scope
- Repository scaffold, autoloader, tooling, CI, ADRs accepted.
- `Crypto`: `Cipher` (seal/open), `MasterKey`, `Scrypt` (file-backed, resumable),
  known-answer vectors.
- `Format`: `KeyFile`, `Config`, `Pack` writer/reader, `Index`, `Tree` encoder/decoder with
  Go quoting and mode mapping, `Snapshot`, `Lock`, `Unpacked`, `Chunker` with polynomial
  generation.
- `Backend`: REST protocol on an `HttpClient` interface with a plain-cURL test client;
  retries; error mapping; server profiles (BorgBase preset, own rest-server with path and CA
  certificate); append-only probe.
- `Engine`: `Repository` open/init, `Unlock`, `Locking`, `BlobWriter`/`BlobReader`, minimal
  `Backup` and `Restore` as resumable steps driven by a test harness; `Forget` parity.
- Quality gates for a solo maintainer ([ADR 0024](adr/0024-governance-and-quality-assurance.md)):
  conformance suite, mutation testing, fuzzing harness, benchmarks.
- **Empirical BorgBase verification** with two free-tier repositories (normal and
  append-only): append-only probe, lock deletion, ranged reads, v2 listing, behaviour under
  many small requests, quota response. Findings go into
  [ADR 0025](adr/0025-repository-servers.md).

Acceptance
- A PHP-written repository with the fixture trees and a 300 MB database fixture passes
  `restic check --read-data`; `restic restore` reproduces the fixtures byte for byte.
- PHP restores repositories written by restic 0.14 and 0.19 (format 1) identically; with
  `ext-zstd`, also format 2 repositories written by restic 0.19.
- Chunk-boundary parity and forget parity green; append-only behaviour green on rest-server
  and on BorgBase.
- Covered mutation score of `Crypto` and `Format` at least 90 %; nightly fuzzing without open
  crashes.
- Benchmarks on PHP 8.3 without JIT: chunker at least 5 MB/s, scrypt `N = 16384` within
  30 s, 1 GB fixture backup to a local rest-server within 15 minutes of CPU across ticks.
  Otherwise the fallback chunking rule is enabled and documented
  ([ADR 0019](adr/0019-repository-format-and-chunker.md)).

## Milestone 1 — First backup in WordPress (0.1.0, internal alpha)

**Status (2026-11-10): implemented; the integration suite passes in CI. It runs the plugin
in a real WordPress 7.1.4 on PHP 8.3: setup through the REST API, a backup driven by
`wp-cron.php` with `max_execution_time = 30` and a memory ceiling of 128M, `restic check
--read-data`, a restore with restic, a database import with identical checksums, and a web
server that is stopped in the middle of a tick. Until 2026-11-10 the suite failed, because
the CI setup switches on PHP's tracing JIT for the built-in web server and PHP 8.3 then
crashed in backup ticks; the test server now runs without the JIT, as PHP does by default.
The sizes of the criterion (2 GB of files, 300 MB of database) run in the nightly workflow,
which GitHub starts from `main` only, so not before the pull requests are merged.**

**Found by the audit of 2026-11-10 and carried into Milestone 3:** no test loads the
classes of the WordPress layer other than through the HTTP suite, which calls 10 of the 16
routes; loopback chaining, the wizard, WP-CLI, uninstall and the multisite refusal are
untested. Time estimates, a schedule more frequent than twice a day, and the Doctor's
checks for outbound HTTPS and for how to switch the PHP version were promised and do not
exist.

| Acceptance criterion | Result |
| --- | --- |
| Large site through `wp-cron.php` with 30 s and 128M, to BorgBase and a TLS rest-server; `restic check --read-data`; CLI restore; database import with identical checksums | Without WordPress, verified: the whole backup job from settings and encrypted secrets to a resumable unlock and a 20,000-row table, in many short ticks against a TLS rest-server and MariaDB, passes `restic check --read-data`, restores, and imports with identical checksums. With WordPress and `wp-cron.php`: `tests/Integration` passed in CI on 2026-11-10 with 64 MB of files and 32 MB of database, the restore compared for the uploads directory and the checksums for the fixture tables; the run with 2 GB plus 300 MB is nightly and has not run yet. BorgBase pending |
| A tick killed at any point leaves a resumable job; no stale lock | Engine and runner verified: every step can be interrupted, a killed tick's lease is taken over, and cleanup releases the lock on every path. Through `wp-cron.php` in CI: the web server is stopped once during a backup tick; the job finishes after the restart, `restic check` passes and no lock is left. The test does not choose the step in which the server stops |
| No secret in files under `jobs/`, in REST responses or in HTML | `jobs/` verified in the job test and in CI; the integration suite searches the responses of four routes (status, settings, jobs, job log) for the two passwords; the admin HTML contains no settings or secrets by construction, which no test checks |

Findings that changed the design:
- The engine read virtual files in one go and restarted them after an interruption, so a
  table larger than one tick could never finish. Tables are now resumable per page
  ([ADR 0021](adr/0021-database-streaming.md)).
- `spawn_cron()` does nothing inside a `wp-cron.php` request and spawns at most once a
  minute; loopback chaining takes the cron lock itself, as `spawn_cron()` does.
- The repository ID lies inside the encrypted config, so a cached master key counts as valid
  when it decrypts that config.
- Every tick downloaded all index files; a local, encrypted index cache now fetches only new
  ones.
- rest-server serves `config` without range support; the connection test reads a key file
  instead. mysqlnd returns `BIT` values as decimal strings; `offset` is reserved in MariaDB.
- The core is separated from WordPress by one namespace and two PHPStan rules
  ([ADR 0029](adr/0029-wordpress-free-core.md)).

Not done in this milestone: time estimates (progress shows files and bytes so far) and
BorgBase measurements. The name was settled before the first public commit
([ADR 0015](adr/0015-naming-and-license.md)).

Scope
- Jobs, ticks, loopback chaining, admin polling ticks, heartbeat; preflight and Doctor for
  shared hosting (extensions, PHP version and how to switch it, memory, time limit, outbound
  HTTPS, loopback, cron advice, private directory probe).
- Setup wizard: environment → repository server (BorgBase or own rest-server, credentials,
  optional CA certificate, connection test, append-only detection) → password → unlock
  progress → recovery kit → schedule → first backup.
- Settings schema and page, secrets store with cached master key.
- Multisite guard: activation refused with a clear message
  ([ADR 0026](adr/0026-multisite-deferred.md)).
- `Backup\Plan`, database streaming per table, manifest, checkpoints, progress and estimates.
- Dashboard, snapshot list, Logs page, Site Health basics, WP-CLI `doctor`, `backup`, `tick`,
  `status`, `snapshots`.

Acceptance
- A 2 GB fixture site with a 300 MB database backs up through real `wp-cron.php` requests
  with `max_execution_time = 30` and `memory_limit = 128M`, both to BorgBase and to a TLS
  rest-server with a self-signed CA. The snapshot passes `restic check --read-data` and
  restores with the CLI; the database imports into a fresh database with identical table
  checksums.
- Killing PHP during any tick leaves a resumable job; the next tick continues; no stale lock
  older than 30 minutes remains after completion.
- No secret substring in any file under `jobs/`, in REST responses or in HTML.

## Milestone 2 — Operations (0.2.0, public beta)

**Status (2026-11-10): implemented, in review (six stacked pull requests). Done: prune
without repacking and the repository check in the engine
([ADR 0022](adr/0022-maintenance-scope.md)), both verified against restic 0.19.1; forget,
prune and check as jobs with their schedules, the retention modes with append-only
re-detection by jobs and a daily probe ([ADR 0010](adr/0010-retention-and-append-only.md));
starting the three jobs from REST and WP-CLI; e-mail notifications; the dashboard for
maintenance and alerts with the space only a full prune reclaims; the gentle CPU mode;
cache limits; `readme.txt` and guides for cron jobs and rest-server; the seven-day
simulation of the first acceptance criterion (`RetentionSimulationTest`).**

**Found by the audit of 2026-11-10 and fixed:** retention never removed a snapshot of a
real backup job, because every snapshot carries its job's tag and forget grouped by all
tags; the simulation had used snapshots with one shared tag and now uses the real ones.
Prune could delete used packs when a run was tried again after a failed request, could not
continue after a request ended while it deleted, and could leave its exclusive lock behind.
A check lost its progress with a failed request and called a repository it could not read
damaged.

**Open, and carried into Milestone 3:** tests for the
WordPress wiring of the second and third acceptance criterion (the daily probe, the stored
switch to `external`, the administrator's click back, the heartbeat's stale notice), which
are proven below that layer only; browser tests; a preview of what retention would remove;
repository usage on the dashboard; packs that no index lists, which aborted backups leave
and prune does not remove yet; the POT file. The German translation follows once the
plugin is listed on translate.wordpress.org.

Scope
- Schedules, retention (forget parity), prune-lite, check with sampled data, retention modes
  with append-only re-detection (daily and before every forget or prune, automatic switch to
  `external`, proposal to switch back), notifications, cache management, `cpu_throttle`.
- `readme.txt` with FAQ and the External services section (BorgBase and self-hosted
  servers); user documentation for shared-hosting cron setups and for rest-server setups;
  German translation prepared for translate.wordpress.org.
- WP-CLI parity for maintenance commands.

Acceptance
- Seven simulated days of scheduled runs produce the retention set that `restic forget
  --dry-run` computes; prune-lite deletes exactly the unreferenced packs and the repository
  passes `restic check` afterwards.
- Turning on append-only while the plugin runs switches retention to `external` within a day
  and before the next forget; turning it off never resumes deletions without an
  administrator's click.
- The stale notification fires once at 36 h without a success and not again until recovery.

## Milestone 3 — Restore (0.3.0)

**Status (2026-11-10): planned. "Connect to an existing repository" below already exists
since Milestone 1.**

Scope
- Snapshot browser (tree walk per directory), manifest view.
- Restore jobs: single file, selected paths to staging, zip download, database import with
  `PluginStatePreserver`, and the guarded apply to the live site with a dry-run report
  (confirmed for 1.0).
- "Connect to an existing repository" and "fresh install import" in the wizard.
- Disaster-recovery guide finalised with tested commands for BorgBase and rest-server.

Acceptance
- A full-site restore of the fixture site onto a fresh WordPress through the UI yields a
  working site (E2E) with plugin settings intact.
- Apply refuses a crafted staging tree containing a symlink to `/etc` and a `../` path.
- The WordPress.org submission package is ready (assets, Plugin Check clean). The owner
  decides the submission date; the planning default is the end of this milestone.

## Milestone 4 — 1.0.0

Scope
- Hardening against the pre-release checklist; an internal security pass over `Crypto`,
  `Format` and `Engine` with the self-review checklist; an extended fuzzing campaign with
  malformed server data; mutation score maintained.
- Bedrock layout verified; accessibility review; performance guidance per hosting class;
  benchmarks published.
- WordPress.org review feedback addressed; `deploy-wordpress-org.yml` live; translations on
  translate.wordpress.org.

Acceptance
- All criteria above hold on WordPress latest and latest-1, PHP 8.3 (no JIT) and 8.5, restic
  oracle 0.18.x and latest; live smoke tests green against BorgBase and rest-server.
- Zero PHPStan and PHPCS findings; Plugin Check passes without errors; the plugin is approved
  and live on WordPress.org; the first SVN deployment matches the signed tag byte for byte.

## After 1.0 (backlog)

- **1.1: Multisite** with network-only activation, site options, `manage_network_options`
  and scheduling on the main site ([ADR 0026](adr/0026-multisite-deferred.md)).
- Prune with repacking in PHP under the tick model.
- `ext-zstd`: optionally write compressed blobs into format 2 repositories (reading them
  works since Milestone 0).
- Parallel uploads with the bundled Requests library's `request_multiple`.
- Optional restic-binary engine for VPS users ([Appendix A](appendix-a-binary-engine.md)).
- Webhook notifications; scheduled restore drills; multiple repositories.
- Consistent database snapshots in tick mode where the host allows a persistent connection.
- A second committer and an external audit as soon as a person or funding is available; no
  architectural change is needed ([ADR 0024](adr/0024-governance-and-quality-assurance.md)).

## Decision log (2026-11-09)

| # | Topic | Decision | Recorded in |
| --- | --- | --- | --- |
| Q1 | PHP minimum version | PHP 8.3 | [ADR 0003](adr/0003-version-floors.md) |
| Q2 | Distribution | WordPress.org only; GitHub hosts the source | [ADR 0017](adr/0017-distribution-via-wordpress-org.md) |
| Q3 | Name, slug, identifiers | Backups for restic, slug `backups-for-restic`; applied before the first public commit | [ADR 0015](adr/0015-naming-and-license.md) |
| Q4 | Apply restores to the live site in 1.0 | Yes, guarded | [ADR 0013](adr/0013-restore-safety.md) |
| Q5 | Self-hosted restic REST servers | Officially supported in the wizard next to BorgBase | [ADR 0025](adr/0025-repository-servers.md) |
| Q6 | Append-only handling | Detect automatically and recommend; the plugin cleans up when deletions are allowed | [ADR 0010](adr/0010-retention-and-append-only.md) |
| Q7 | Documentation language | English in the repository; German UI and listing through translate.wordpress.org | [ADR 0024](adr/0024-governance-and-quality-assurance.md) |
| Q8 | Indentation | Tabs | [04 §3](04-development-and-quality.md#3-coding-standards) |
| Q9 | WordPress.org ownership | The owner's personal account only; bus factor 1 accepted | [ADR 0024](adr/0024-governance-and-quality-assurance.md) |
| Q10 | Submission timing | **Deferred**, decided when needed; planning default after Milestone 3 | this document |
| Q11 | Target and engine | Shared hosting; native PHP engine; repository format 1 | [ADR 0018](adr/0018-native-php-engine.md) |
| Q12 | External security review | None; internal quality assurance with compensating controls; residual risk accepted | [ADR 0024](adr/0024-governance-and-quality-assurance.md) |
| Q13 | Relationship with BorgBase | No contact and no public association; the plugin simply works with BorgBase; behaviour verified by live tests | [ADR 0025](adr/0025-repository-servers.md) |
| Q14 | Business model | Completely free with a donation link; no add-on | [ADR 0024](adr/0024-governance-and-quality-assurance.md) |
| Q15 | Multisite | Deferred to 1.1; activation refused in 1.0 | [ADR 0026](adr/0026-multisite-deferred.md) |

Still open: Q10 (submission timing), deferred by the owner.

Architecture decisions taken during Milestone 0:

| # | Topic | Decision | Recorded in |
| --- | --- | --- | --- |
| A1 | Poly1305 | Pure PHP; the engine needs nothing from sodium | [ADR 0027](adr/0027-poly1305-in-pure-php.md) |
| A2 | Format 2 repositories from restic | Only with `ext-zstd`; restic compresses their index and snapshot files unconditionally | [ADR 0019](adr/0019-repository-format-and-chunker.md) |
| A3 | Fuzzing | Built-in structure-aware fuzzer instead of php-fuzzer | [ADR 0028](adr/0028-built-in-fuzzer.md) |
| A4 | Server listings | Names that are not object ids are skipped, as restic does | `Backend\Backend::list()` |

## Risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| PHP throughput too low on cheap hosting (chunker, Poly1305, scrypt) | low | medium | Measured in Milestone 0 with wide margins (chunker 24 MB/s, Poly1305 62 MB/s without JIT); file-backed resumable scrypt; cron-ping guidance. A fallback chunking rule was considered and never built |
| PHP's JIT compiler crashes PHP in the plugin's loops | low (the JIT is off by default) | medium | Seen in CI on PHP 8.3.36 and once locally on 8.3.6 with the tracing JIT: a segmentation fault in a backup tick. The job layer recovers (the lease expires and the heartbeat resumes the job). Tests run without the JIT; a Doctor hint and a report to PHP are planned for Milestone 3 |
| A format bug damages repositories | low | critical | No home-made cryptography; textbook primitives tested against reference implementations; differential tests on every change; mutation and fuzzing gates; monthly checks; the CLI recovery path does not depend on plugin code. No independent review before 1.0 (accepted) |
| The single maintainer is unavailable when a security fix is needed | medium | high | Accepted ([ADR 0024](adr/0024-governance-and-quality-assurance.md)); public source, documented pipeline and `SECURITY.md`; a second committer can be added at any time |
| BorgBase changes behaviour without notice | low | medium | Weekly live smoke tests; generic rest-server compatibility; no BorgBase-specific code paths beyond the preset |
| The PHP 8.3 floor excludes many sites at launch (45 % reach in November 2026) | high | medium | Reach grows until 1.0 in 2027; WordPress blocks installation on older PHP gracefully; `readme.txt` explains how to switch PHP |
| Hosts block loopback requests or outbound HTTPS | medium | medium / high | Doctor detects both; cron-ping documentation; outbound HTTPS is a stated requirement |
| Self-hosted servers vary in setup quality (TLS, versions, proxies) | medium | low | Connection test checks Range reads, listing and append-only first; clear errors; rest-server documentation |
| restic changes its format | low | medium | Format 1 is frozen; the conformance suite runs nightly against pinned restic releases (0.19.1, 0.18.1, 0.14.0), and a new release is added by a reviewed pull request. Automatic notice of new releases is planned |
| First backups take many hours on large sites | high | low | Documented; incremental runs are fast; chaining and cron ping |
| Operators lose the repository password | medium | critical | Mandatory recovery kit acknowledgement; re-display after re-authentication; constants option; DR guide |
| WordPress.org review questions the cryptographic code or filesystem access | medium | medium | FAQ answers prepared; readable, documented code; Plugin Check clean; screenshots of graceful failures |
| WordPress.org review queue delays the launch by weeks | high | low | Submission timing is flexible; development continues meanwhile |
| Plugin closed by WordPress.org for a guideline violation | low | high | Guidelines are hard constraints; Plugin Check on every release; the name satisfies guideline 17 ([ADR 0015](adr/0015-naming-and-license.md)) |
| Scope creep into a generic backup product | medium | medium | Non-goals; ADR process for any new backend or format change |
