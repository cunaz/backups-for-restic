# 02 · Architecture

This document describes how the plugin is built. It is the reference for implementation.
Decisions that needed weighing alternatives are recorded in [`adr/`](adr/) and linked inline.

**Core decision ([ADR 0018](adr/0018-native-php-engine.md)):** the plugin contains a native
PHP implementation of the restic repository format. It needs no `restic` binary, no
`proc_open` and no shell, so it runs on shared hosting. What it writes is an ordinary restic
repository that the `restic` command line can read, check and restore. The restic CLI is the
conformance oracle in the test suite, not a runtime dependency.

Contents

1. [System context](#1-system-context)
2. [Architectural principles](#2-architectural-principles)
3. [Module map and dependency rules](#3-module-map-and-dependency-rules)
4. [Runtime model: jobs and ticks on shared hosting](#4-runtime-model-jobs-and-ticks-on-shared-hosting)
5. [The restic repository format as implemented](#5-the-restic-repository-format-as-implemented)
6. [Backup pipeline](#6-backup-pipeline)
7. [Database backup and restore format](#7-database-backup-and-restore-format)
8. [Retention, integrity checks and maintenance](#8-retention-integrity-checks-and-maintenance)
9. [Restore](#9-restore)
10. [On-disk layout](#10-on-disk-layout)
11. [Settings model](#11-settings-model)
12. [Core interfaces](#12-core-interfaces)
13. [Surfaces: admin UI, REST API, WP-CLI, Site Health, hooks](#13-surfaces)
14. [Errors, logging and observability](#14-errors-logging-and-observability)
15. [Multisite and unusual layouts](#15-multisite-and-unusual-layouts)
16. [Performance and resource budget](#16-performance-and-resource-budget)
17. [Internationalisation and accessibility](#17-internationalisation-and-accessibility)
18. [Versioning, upgrades and uninstall](#18-versioning-upgrades-and-uninstall)

Appendix: [A · Optional restic-binary engine](appendix-a-binary-engine.md) (future option,
not part of 1.0).

---

## 1. System context

```
┌──────────────────────── shared or managed web hosting (PHP-FPM / mod_php) ───────────────────────┐
│                                                                                                  │
│  Admin browser ──HTTPS──▶ WordPress admin ──▶ REST API (backups-for-restic/v1) ──┐                  │
│                                                                               ▼                  │
│  WP-Cron (page loads, loopback chain, external ping of wp-cron.php) ──▶  Job runner ("tick")     │
│  WP-CLI where available ───────────────────────────────────────────▶  bounded PHP execution      │
│                                                                               │                  │
│                     ┌─────────────────────────────────────────────────────────┴──────────────┐   │
│                     │  Native engine (PHP): scan · chunk · hash · encrypt · pack · index ·    │   │
│                     │  trees · snapshots · locks · forget · prune-lite · check · restore      │   │
│                     └──────────────┬───────────────────────────────────────┬─────────────────┘   │
│                                    │ WordPress HTTP API (cURL, TLS verified)│ private directory   │
│                                    ▼                                       ▼                     │
│                     restic REST server over HTTPS              cache · checkpoints · scrypt ·    │
│                     self-hosted rest-server or BorgBase        staging · job state · logs        │
│                     (HTTP basic auth, optional append-only)                                      │
└──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

Participants:

- **WordPress** provides authentication, capabilities, settings storage, scheduling
  (WP-Cron), the HTTP client, REST infrastructure, Site Health and, where present, WP-CLI.
- **The plugin** does everything: it reads the site's files and database, produces restic
  blobs, packs, index files, trees and snapshots, and talks to the REST endpoint. There is
  no external process at any point.
- **The repository server** speaks the restic REST protocol over HTTPS
  ([ADR 0025](adr/0025-repository-servers.md)): a **self-hosted `rest-server`**, or a hosted
  service. The wizard has a preset for BorgBase, which according to its public documentation
  runs nginx in front of restic's `rest-server` with per-repository credentials, an
  append-only option and stale-backup alerts. In append-only mode deletions of data, index,
  key and snapshot files are answered with HTTP 403 while lock files stay deletable. This is
  verified in the rest-server source and asserted against a running rest-server by the
  conformance suite. BorgBase's behaviour is not yet measured: the live checks need an
  account (ADR 0025).
- **The restic CLI** is used by operators for disaster recovery and by the test suite as the
  reference implementation. It is never required on the web server.

## 2. Architectural principles

1. **We implement restic's format, not a backup format of our own.** Every byte the plugin
   writes must be accepted by `restic check --read-data`, and everything the plugin reads is
   produced by the same format rules. Where restic leaves freedom (JSON whitespace, pack
   composition) the plugin follows restic's choices so that behaviour stays predictable.
2. **No home-made cryptography.** AES-256-CTR and AES-128 come from OpenSSL, SHA-256 and
   PBKDF2 from PHP's hash extension. Three algorithms that PHP does not offer are written
   by hand as textbook code: Poly1305, because PHP does not expose libsodium's
   `crypto_onetimeauth` ([ADR 0027](adr/0027-poly1305-in-pure-php.md)); scrypt's
   ROMix/Salsa20/8 loop ([ADR 0020](adr/0020-unlocking-and-kdf.md)); and the Rabin chunker,
   which is not security critical. All three are pure integer arithmetic, verified against
   reference vectors (RFC 8439 and libsodium, RFC 7914, restic's chunker) and against
   repositories that restic wrote and checks.
3. **Everything streams, and memory does not grow with file sizes.** Files are read in
   512 KiB buffers and cut into chunks of at most 8 MiB, blobs are encrypted one at a time,
   packs are built in memory up to the pack size (16 MiB by default) and uploaded with one
   request each, index files follow restic's limit of 50 000 blobs. A file or a database
   table of any size therefore costs the same memory. What does grow is the index: every run
   holds the merged index of the whole repository, as records of 48 bytes in buckets, 60 to
   80 bytes per blob with the buckets' own cost (`Format\Index\IndexSet`,
   [ADR 0031](adr/0031-compact-index-and-memory-budget.md)): 33 MB for half a million
   blobs, 61 MB for a million. A job knows PHP's memory limit (`Engine\MemoryBudget`). The
   index stops loading with the error `index_too_large` when a backup with the smallest
   packs would no longer fit beside it, and a backup makes its packs smaller than
   configured, down to 4 MiB, when three of them do not fit. With a WordPress baseline of
   30 to 40 MiB, the measurements suggest capacity for roughly a million blobs under
   `memory_limit=128M`; full-job measurements at that count remain part of phase 0.9.
4. **Ticks, not long processes.** All work happens in bounded slices (default 20 s) driven
   by WP-Cron, loopback chaining, admin polling or WP-CLI. Every slice ends with a durable
   checkpoint; a killed request loses at most one slice of work.
5. **A thin WordPress shell around a WordPress-free core.** Only the namespace `WordPress`
   and the composition root `Plugin` call WordPress
   ([ADR 0029](adr/0029-wordpress-free-core.md)). Everything else (`Support`, `Exception`,
   `Settings`, `Secrets`, `Storage`, `Crypto`, `Format`, `Backend`, `Connection`, `Engine`,
   `Jobs`, `Backup`, `Database\Dump`, `Doctor`, `Scheduling`, `Notifications`) calls no
   WordPress function and is tested without WordPress.
6. **Secrets flow through one type** (`Secrets\Secret`, redacting, `reveal()` allow-listed).
7. **Fail closed.** A failed precondition (undecryptable secrets, a private directory that
   the HTTP probe could read, a MAC mismatch, an unsupported repository version) stops the
   operation with a precise message. One precondition can stay unverified without stopping
   it: where the site cannot request itself, the HTTP probe of the private directory is made
   by the administrator's browser instead, and until a browser has looked the Doctor
   reports `unverified` as a recommendation and backups go on (§13.4,
   [ADR 0007](adr/0007-private-directory.md)).
8. **Composition root, immutable value objects, `final` by default, `declare(strict_types=1)`.**

## 3. Module map and dependency rules

```
src/
├── Plugin.php                     composition root; boots the WordPress layer, refuses multisite
├── WordPress/                     the only place that calls WordPress (ADR 0029)
│   ├── Container.php              builds every service from constants, options and filters
│   ├── WpOptions, WpHttpClient, WpdbConnection, WpExposureCheck   adapters behind the core's ports
│   ├── Cron.php                   tick and heartbeat events, loopback chaining, loopback check
│   ├── RestRoutes.php             REST API backups-for-restic/v1 (§13.2)
│   ├── AdminPage.php              menu and page frame; assets/js/admin.js fills it
│   ├── SiteHealth.php, CliCommand.php, RecoveryKit.php, Messages.php (translated texts for core codes)
│   ├── Downloads.php, PasswordConfirmation.php   one-time downloads of staged files; the one check of the WordPress password where a route asks for it again
│   ├── JobEvents.php              the plugin's actions on job state changes; hands finished jobs to the notifier
│   ├── WpMailer.php               notification e-mails: translated plain text through wp_mail()
├── Support/                       GoJson (Go-compatible JSON), GoQuote, Rfc3339, Bytes (strict base64, base64url, integers), Decoded, RawJson, ByteSource (+ Stream, String, Resumable), Clock (+ SystemClock), Redactor, Throttle (gentle CPU mode)
├── Exception/                     PluginException and one subclass per failure the UI explains
├── Settings/                      Schema (every field once), Field, Settings (immutable), SettingsStore (option + wp-config.php overrides)
├── Secrets/                       Secret (value hidden in a closure), SecretName, SecretStore, EncryptedOptionStore (HKDF over the salts + secretbox), ConstantOverrideStore
├── Storage/                       Options port, PrivateDirectory, SiteState, SchemaVersion (layout number of the options, §18), Files, CacheDirectory, ExposureCheck port, BrowserProbe, ConfirmationThrottle (counts wrong WordPress passwords per user, §13.2), DownloadTokens (one-time tokens for downloads, hashed in a file; ADR 0038), Zip/ (ZipWriter: a stored zip of a directory tree in resumable calls; ZipRecords; Crc32)
├── Crypto/                        restic primitives
│   ├── Cipher.php                 seal()/open(): nonce ‖ AES-256-CTR ‖ Poly1305-AES tag
│   ├── Poly1305.php               pure-PHP Poly1305 (ADR 0027)
│   ├── MasterKey.php              value object: encrypt key (32), mac k (16), mac r (16)
│   ├── Scrypt.php                 pure-PHP scrypt: PBKDF2 (native) + ROMix/Salsa20/8, file-backed V, resumable; ScryptParameters
│   └── RandomSource.php           interface for random bytes; SystemRandom in production, a fixed source in tests
├── Format/                        restic on-disk/wire structures
│   ├── Config.php, KeyFile.php, Id.php, BlobType.php, Snapshot.php, SnapshotSummary.php, Lock.php
│   ├── Pack/                      PackWriter, PackReader, PackEntry, FinishedPack
│   ├── Index/                     IndexFile (JSON ⇄ entries, decoded pack by pack), IndexSet (lookup in buckets of binary records, export in pieces), BlobLocation
│   ├── Tree/                      Node, NodeType, FileMode, TreeCodec (ordering, Go quoting, mode bits)
│   ├── Unpacked.php               non-pack files: plain JSON, or zstd through Compression.php when `ext-zstd` is present
│   └── Chunker/                   Polynomial (GF(2) ops, irreducibility test), RabinChunker (restic-identical), ChunkReader
├── Backend/                       Backend interface, ObjectType, RestBackend (restic REST over an HttpClient), AppendOnlyProbe, AppendOnlyStatus, Http/ (HttpClient port, HttpResponse)
├── Connection/                    ServerProfile (URL rules), ConnectionTester, ConnectionReport, RepositoryAccess (backend + master key cache), RepositoryRecord (which repository the jobs open), Setup (wizard: save, create, connect)
├── Engine/                        the restic logic
│   ├── Repository.php, BlobStore.php, Locking.php, Identity.php
│   ├── KeyUnlock.php              resumable unlock across key files
│   ├── IndexCache.php             merged index cached locally in sealed pieces; fetches only new index files
│   ├── MemoryBudget.php           PHP's memory limit: how large the index may be, how large packs are (ADR 0031)
│   ├── Backup/                    BackupPlan, BackupRun (resumable, also inside virtual files), NodeFactory, VirtualFile
│   ├── Restore/                   RestoreRun (resumable, refuses paths that leave the target)
│   ├── Forget/                    Forget, RetentionPolicy, Duration: restic's ApplyPolicy semantics; ForgetRun: the resumable run
│   ├── Prune/                     PruneRun: prune-lite, resumable, under an exclusive lock
│   └── Check/                     CheckRun: structural check plus a sampled share of packs read
├── Jobs/                          Job, JobState, JobType, JobError (stable codes), JobStore, EventLog, RunnerLock, Runner, JobObserver, Step, ClosureStep, StepProvider, JobSteps, TickReport
│   ├── Backup/                    BackupSteps (preflight, unlocking, backing_up, recording, releasing, cleaning_up), BackupServices
│   ├── Maintenance/               MaintenanceSteps (detecting, forgetting, pruning, checking, recording), MaintenanceServices, RetentionGuard (ADR 0010), RetentionSettings (the policy of the settings, for the forget job and the preview)
│   └── Restore/                   RestoreSteps (preflight, planning, restoring, recording, releasing, cleaning_up), RestoreServices, RestoreRequest (ADR 0037)
├── Backup/                        PlanFactory (roots, excludes, spool/ with site manifest and dump), SitePaths
├── Database/Dump/                 Connection port, SharedConnection and DedicatedConnection (the dump's session settings and snapshots), Catalog, Table, KeyColumn, SqlFormatter, TableSource (resumable per page), DatabaseDump
├── Doctor/                        Environment, Check, Doctor (pure checks; texts in WordPress\Messages)
├── Scheduling/                    SchedulePolicy (slots in the site's time zone)
├── Restore/                       SnapshotBrowser (snapshot lists with the retention preview, details and directory pages as response data, §13.2); Staging (list, expiry and removal, ADR 0040), StagedRestore; (M3) Selection, Apply, database Importer
└── Notifications/                 Notifier (first failure, stale once, recovery once; outbox with retries), Notice, NoticeKind, Mailer port
```

The entries marked (M3) arrive with that milestone. Dependency rules:

- Only `WordPress\*` and `Plugin` call WordPress functions or use WordPress classes
  ([ADR 0029](adr/0029-wordpress-free-core.md)). The core receives paths, settings values,
  a `Clock`, a `RandomSource` and the ports `Options`, `HttpClient`, `Connection`,
  `ExposureCheck` and `Mailer` as constructor arguments.
  `tests/PHPStan/WordPressFreeCoreRule.php` enforces this in CI for every namespace except
  `WordPress`: it reports WordPress functions that are called and WordPress classes that are
  instantiated, called statically, read for constants or used with `instanceof`.
- `Engine` depends on `Format`, `Crypto`, `Backend`, `Support` and `Exception`, and on two
  helpers outside them: `Storage\Files` for atomic writes and `Secrets\Secret` for the
  password in `KeyUnlock`. It never depends on `Jobs` or any adapter. `Jobs\Backup` and
  `Jobs\Maintenance` orchestrate `Engine` operations in bounded slices.
- The layers below the engine depend in the same direction: `Exception` uses no other part
  of the plugin, `Support` uses `Exception`, `Crypto` uses those two, `Format` uses those
  three, and `Backend` may use those four. Two of them use the helper `Secrets\Secret` as
  well: `Support\Redactor`, which removes secrets from texts, and `Backend\RestBackend`,
  which builds the basic auth header. A helper is allowed for the whole layer, not only
  for the class that uses it today. The two helper classes are bound as well, so that
  nothing reaches the stack through them: `Secrets\Secret` uses nothing of the plugin and
  `Storage\Files` only `Exception`.
  `tests/PHPStan/EngineDependencyRule.php` enforces this and the rule above in CI. It reads
  the `use` statements and the names in the code of every file in these namespaces; a new
  dependency needs a change of its list.
- `Secret::reveal()` is allowed only in the classes listed in
  `tests/PHPStan/SecretRevealRule.php`.

## 4. Runtime model: jobs and ticks on shared hosting

### 4.1 Constraints the model is built for

Shared hosting means: PHP requests limited to 30–120 s, `set_time_limit()` sometimes
disabled, memory limits of 128–256 MB, CPU-second quotas, no shell, no system cron (or only
cPanel/Plesk cron that can fetch a URL), outbound HTTPS usually allowed, loopback requests
sometimes blocked, WP-Cron only when the site has visitors. The plugin therefore never needs
more than one bounded slice at a time and never relies on a particular trigger.

### 4.2 Jobs and ticks

A **job** (`Jobs\JobType`: backup, unlock, forget, prune, check or restore) is a JSON
document in the private directory
([ADR 0014](adr/0014-file-based-job-store.md)). A **tick** loads the active job, advances it
for at most the budget, writes a checkpoint and arranges the next tick. Every engine
operation is written as a **resumable step**: it can stop after any blob, any directory, any
HTTP request, and continue from the checkpoint in the next tick.

The budget comes from `Container::tick_budget()`:

1. Start with `advanced.tick_budget_seconds` (default 20, allowed 5 to 300).
2. In the gentle CPU mode (`advanced.cpu_throttle = gentle`) halve it.
3. If `max_execution_time` is greater than 0, cap it at `max(5 s, max_execution_time − 10 s)`.
   The floor of 5 s means that with a limit of 15 s or less the cap is 5 s, which is more
   than the limit minus 10 s.
4. A caller may ask for less: admin polling asks for 5 s.

The halving comes before the cap, so a setting above the cap is not halved in effect: with
`max_execution_time = 30` a setting of 60 gives 20 s in both modes, and the default setting
gives 20 s in normal and 10 s in gentle mode. `Cron::tick()` also calls
`set_time_limit( budget + 30 )` where the function exists, but never relies on it, and
`ignore_user_abort( true )`.

At the start of each tick `Cron::tick()` calls `wp_raise_memory_limit( 'backups_for_restic' )`.
WordPress then raises PHP's `memory_limit` to `WP_MAX_MEMORY_LIMIT` when that is higher and
the host allows the value to be changed; WordPress' default for the constant is 256M, and
the filter `backups_for_restic_memory_limit` can raise the limit further. On a host with
`memory_limit = 128M` and no `WP_MAX_MEMORY_LIMIT` in `wp-config.php` a tick therefore runs
with 256M, and the preflight of §6 sees that value, while the Doctor judges the limit of the
request that runs the check. The WP-CLI loops (`--wait`, `tick --until-idle`) call the runner
directly and keep the limit of the CLI process.

### 4.3 Who ticks

| Driver | Behaviour | Typical on shared hosting |
| --- | --- | --- |
| **Loopback chain** | At the end of a tick that advanced a job which still has work (`TickReport::should_chain()`), `Cron::chain()` schedules `backups_for_restic_tick` for *now*, writes WordPress' `doing_cron` transient itself and sends a non-blocking request to `wp-cron.php`. It does not call `spawn_cron()`, which does nothing inside a `wp-cron.php` request and spawns at most once a minute. The next tick starts within a second. The chain stops when the job finishes, when a tick finds the runner busy, and while a job waits out a back-off ([ADR 0011](adr/0011-scheduling.md)). A new job is started the same way: `POST /jobs`, `POST /repository/connect` and the WP-CLI commands without `--wait` call `Cron::chain()` after they created it. With `advanced.loopback_chaining: off`, `Cron::chain()` does nothing in either place: no tick event is scheduled and `wp-cron.php` is not requested. A job then gets one tick per heartbeat, every five minutes, plus the ticks of an open admin page. | Works on most hosts; some block loopback (Doctor detects it, like Site Health does) |
| **WP-Cron on traffic** | Due events run at the end of ordinary page loads. | Baseline; speed depends on visitors |
| **External cron ping** | cPanel/Plesk cron or an external cron service fetching `wp-cron.php?doing_wp_cron` every 1–5 minutes, with `DISABLE_WP_CRON` optionally set. No plugin-specific URL or token is involved. | Recommended; see [`guides/cron.md`](guides/cron.md) |
| **Admin polling** | While the admin page follows a running job it requests `GET /jobs/{id}` every 3 s; such a request runs a tick of at most 5 s, at most once per 5 s, and does not chain. "Keep this tab open to speed up the backup on slow hosts." | Always available |
| **Heartbeat** | Recurring `backups_for_restic_heartbeat` every 5 minutes (`Cron::heartbeat()`). Once setup is completed it probes for append-only mode once a day (hourly while the last answer was inconclusive, and at once when the repository of the settings has no probe result yet, for example after another server was saved) and creates the one job that is due: a scheduled backup first, then forget after a successful backup, then prune and check. If a job is active it then runs one tick, which takes up a job whose chain broke once its lease has expired or its back-off has passed. Then it lets the notifier send the stale notice when due and retry unsent ones. The probe and the notifier run under the runner lock and wait for the next heartbeat while a tick holds it. Last, for at most five seconds and without that lock, it removes restore staging that is due: what expired and what jobs that did not succeed left behind (§10, [ADR 0040](adr/0040-restore-staging-expiry.md)); what a large tree needs beyond that, the next heartbeats do. The heartbeat has no duties of its own for leases, repository locks or logs: leases and locks are handled inside the tick, and nothing rotates logs (§14). | Needs any of the above to fire |
| **WP-CLI** (`wp backups-for-restic …`) | `tick` runs one tick with the budget of §4.2; `tick --until-idle` repeats such ticks until no job is left. `backup`, `forget`, `prune` and `check` with `--wait` run the job in the same process in ticks of 300 s each, which do not pass through `Container::tick_budget()`: neither `max_execution_time` nor the gentle mode shortens them. Without `--wait` these commands create the job and return; the other drivers advance it. | Where the host offers WP-CLI |

Only one runner may advance a job at a time: `flock()` on `<private>/runner.lock`
(non-blocking) plus a **lease** in the job document (`lease_owner`, `lease_expires_at`),
which a tick holds for its budget plus 60 s. WordPress' own `doing_cron` lock additionally
serialises WP-Cron requests. After a job is created, only the holder of `runner.lock` writes
its `job.json`; a request that wants a running job to stop writes a file of its own (§4.4).

### 4.4 Job state machine

A job has a state (`Jobs\JobState`: `queued`, `running`, `succeeded`, `failed`, `cancelled`)
and, while it runs, a current step. The steps of each type come from `Jobs\JobType`:

| Type | Steps of the regular path | Cleanup steps |
| --- | --- | --- |
| `backup` | `preflight` → `unlocking` → `backing_up` → `recording` | `releasing` → `cleaning_up` |
| `unlock` | `unlocking` | `cleaning_up` |
| `forget` | `preflight` → `unlocking` → `detecting` → `forgetting` → `recording` | `releasing` → `cleaning_up` |
| `prune` | `preflight` → `unlocking` → `detecting` → `pruning` → `recording` | `releasing` → `cleaning_up` |
| `check` | `preflight` → `unlocking` → `checking` → `recording` | `releasing` → `cleaning_up` |
| `restore` | `preflight` → `unlocking` → `planning` → `restoring` → `recording` | `releasing` → `cleaning_up` |

```
 queued → running: regular steps → cleanup steps → succeeded
 a step fails ──▶ cleanup steps → failed          cancel ──▶ cleanup steps → cancelled
```

- The cleanup steps run on every path to the end: success, failure and cancellation.
- Independent actions within cleanup are all attempted before the first error is reported.
  A refused local removal cannot skip candidate-password deletion or the other cleanup
  actions. An append-only refusal switches retention to external before cleanup starts.
- `unlocking` is the KDF step; it does nothing when a cached master key opens the repository
  ([ADR 0020](adr/0020-unlocking-and-kdf.md)). The `unlock` job exists for the wizard when it
  connects an existing repository.
- No step of its own takes the repository lock, scans, uploads or writes the snapshot: the
  engine run inside `backing_up`, `forgetting`, `pruning`, `checking`, or `planning` and
  `restoring`, does all of that and keeps its own checkpoint (§4.5). `releasing` releases the lock of a run that did not
  finish, which rest-server allows even in append-only mode.
- `detecting` is the append-only probe of forget and prune jobs
  ([ADR 0010](adr/0010-retention-and-append-only.md)). Forget is a job of its own, started
  by the heartbeat after a successful backup, not a step of the backup job.
- An error with a retryable code (`repository_unreachable`, `repository_locked`,
  `database_restore_busy`, `database_exchange_sessions`, `database_dump_locked`) does not fail
  the job at once: the runner waits 30 s, then twice as long after each further failure in a
  row, and fails the job at the sixth.
- A **restore job** ([ADR 0037](adr/0037-restore-job.md)) carries its request in the job
  document from its creation: `data.snapshot`, the full ID, and `data.paths`, the selected
  snapshot paths (`Jobs\Restore\RestoreRequest`; an invalid request creates no job).
  `preflight` asks for a completed setup, a valid request, a private directory that is not
  served and a repository that answers; the free space is checked by the run once it knows
  how much the selection holds. `planning` and `restoring` drive one
  `Engine\Restore\RestoreRun` ([ADR 0036](adr/0036-selected-file-staging.md)): started once,
  resumed from its checkpoint in every later tick, with the throttle of the other jobs.
  `planning` ends when the selection is counted, `restoring` when every file is staged. The
  job's progress holds `phase`, `files`, `bytes`, `total_files`, `total_bytes`, `skipped`
  and `percent`. A run whose repository lock did not hold gives up its tick and is resumed
  by the next one, at most three times; a snapshot that the repository does not hold fails
  the job at once. `recording` moves the report of the entries beside
  the staged files, writes the job's result (`snapshot`, `files`, `bytes`, `skipped` for
  entries such as links that are recorded but not created, `staging` and `report` as names
  relative to the private directory) and the `last_restore` entry of the state.
  `releasing` aborts the run, which releases its repository lock, on every path; a run whose
  checkpoint already says that every file is staged stays complete when the release in the
  call that staged the last file fails, and `releasing` tries again.
  `cleaning_up` removes the run's working files, and after a failure or a cancellation the
  staging directory too: only a restore that succeeded leaves files behind. Once the last
  file is staged, `restoring` goes on with the database rehearsal and the archive where the
  request asks for them; a request to cancel that comes then ends only that work (the
  phases `database_import`, `database_cleanup` and `archiving`, which
  `JobType::cancels_before()` is given): the step discards an unfinished archive, stops the
  rehearsal and removes its tables, adds a warning, and the job records its staged files.
  The run takes the index cache of the jobs. Free space that the host does not report lets
  it go on with a warning; free space known to be too little ends the job with
  `staging_space`, and an archive that would not fit beside the staged files is not begun.
  While a restore
  is queued or running, no other job starts, scheduled ones included: the heartbeat leaves
  the schedule slot unhandled and tries again. The notifier does not report restore jobs;
  an administrator started the job and watches it.
- Exactly one job is active at a time; creating another is rejected (HTTP 409), cancel is
  always possible.
- **Creating a job** (`JobStore::create()`): the check for an active job and the write of
  the new `job.json` happen under an exclusive `flock()` on `<private>/jobs/create.lock`, so
  two requests at the same moment cannot both find no job and create one each. What a job
  needs from its caller, such as the share of packs that `check --read-data-subset` asks
  for, is passed to `create()` and is part of the first document; the caller does not write
  the job a second time. The setup takes the same lock (`JobStore::while_idle()`) around its
  check for an active job and what it then changes: the server address, a password, the
  cached key, a new repository, the unlock job. No job can start in between and find the
  address or the key changed under it.
- **Cancelling** (`Runner::cancel()`) never changes the document of a running job. It writes
  the marker `<private>/jobs/<id>/cancel-requested`. `JobStore::get()` reports the marker
  with the job, and a tick looks for it before every step: the job then leaves the regular
  steps and goes through its cleanup steps to `cancelled`, also while it waits out a
  back-off. A request that arrives during a tick can therefore neither be lost nor overwrite
  what the tick saves. When the step that was running wants another tick, the tick looks for
  the marker once more and starts the cleanup itself instead of leaving it to a tick that
  may not come. The tick's deadline holds back regular steps only: once a job is in its
  cleanup steps, the same tick runs them all. A request that arrives when the work is done
  (the snapshot written, the packs deleted) no longer stops the job: the step `recording`
  runs, in the tick that finds the request even if its deadline has passed, and the job ends as `succeeded` (`JobType::cancels_before()`). A job that is still `queued` is cancelled at once and without cleanup
  steps, because nothing ran: by `cancel()` itself if it gets the runner lock, otherwise by
  the next tick. `Runner::stop()` is both in one call, for the deactivation (§18): it asks
  the active job to cancel and runs one tick, which is the cleanup.

### 4.5 Checkpoints

A backup job keeps its working state in `<private>/jobs/<id>/`: `job.json` with step,
progress and results; `backup/` with the engine's checkpoint (the stack of directory frames,
one file of finished nodes per frame, the file being read with its offset and blob ids, or a
virtual file with its cursor); and `database.json` with the dump's table list and finished
tables. Forget, prune and check jobs keep the checkpoint of their engine run in
`maintenance/state.json`; the key derivation keeps its state and table in
`<private>/kdf/<id>/`. Every write is atomic or append-only, so a killed tick loses at most
the work since its last checkpoint. `job.json` and the journal of a live file apply are also
synchronised to disk before what follows relies on them, so that a power loss cannot bring
back an older document or record ([ADR 0041](adr/0041-guarded-file-changes.md)). Memory per
tick is bounded by the directory listings on the walk, one page of rows and one pack buffer
per blob type, plus the merged index, which grows with the number of blobs in the
repository (§2, principle 3).

Backup working files are created with mode `0600`; every write must finish and close before
the checkpoint can refer to its bytes. Resume refuses a failed truncation of an uncommitted
tail. Completing a directory requires reading its exact committed byte count, so an
unreadable node file cannot turn into an empty directory. These are local storage errors,
not evidence of repository damage ([ADR 0032](adr/0032-run-checkpoint-files-and-cleanup.md)).

The positive integer `pid` is a stable ownership field across engine checkpoint versions.
Cleanup reads it through `Engine\CheckpointLock` and releases authenticated locks belonging
to this host and that run number, without resuming the run or loading its index. The
checkpoint's recorded lock ID is not used for this decision. An unknown version may be
cleaned up even though it cannot be resumed; invalid ownership data authorizes no deletion.

## 5. The restic repository format as implemented

Everything below is verified against the restic source (version 0.19) and the restic
design document, and pinned by conformance tests ([ADR 0023](adr/0023-restic-cli-as-conformance-oracle.md)).

### 5.1 Layout and naming

Directories `config`, `data/<2 hex>/`, `index/`, `keys/`, `locks/`, `snapshots/`. Every
file name is the lowercase hex SHA-256 of the stored file content. Files are write-once.

### 5.2 Encryption of every object

```
ciphertext = nonce(16, random) ‖ AES-256-CTR(encrypt_key, nonce, plaintext) ‖ tag(16)
tag        = Poly1305(ciphertext, one_time_key = r ‖ AES-128-ECB(k, nonce))
```

- `encrypt_key` (32 B), `k` (16 B) and `r` (16 B) form the master key. `r` is clamped by
  the Poly1305 implementation (`Crypto\Poly1305`, pure PHP,
  [ADR 0027](adr/0027-poly1305-in-pure-php.md)).
- Decryption verifies the tag first (constant-time comparison with `hash_equals`) and fails
  closed.
- Nonces come from `random_bytes(16)` per object; nothing is ever derived deterministically.

### 5.3 Keys and unlocking

A key file `keys/<id>` is JSON:
`{"created","username","hostname","kdf":"scrypt","N","r","p","salt":base64(64 B),"data":base64}`.
The user key is `scrypt(password, salt, N, r, p, 64 B)`: bytes 0–31 become the AES key,
32–47 `k`, 48–63 `r`. `data` is the encrypted JSON master key
`{"mac":{"k":base64,"r":base64},"encrypt":base64}`. Repositories created by the plugin use
`N = 16384, r = 8, p = 1` (restic's own defaults when calibration is unavailable; restic
accepts any power-of-two `N > 1`). Repositories created by restic typically carry
`N = 32768…131072`; the plugin derives those too, once, with a file-backed ROMix table and
checkpointing, then caches the master key ([ADR 0020](adr/0020-unlocking-and-kdf.md)). Key
files come from the server, so their cost is capped (`Crypto\ScryptParameters`): `N` above
2^20, `r` above 32, `p` above 16 or a table of more than 1 GiB (`128 · N · r` bytes) is
refused with `repository_unsupported_version`.

### 5.4 Config and repository version

`config` is an encrypted JSON `{"version":1,"id":"<64 hex>","chunker_polynomial":"<hex>"}`.
Repositories **created by the plugin are format version 1** ([ADR 0019](adr/0019-repository-format-and-chunker.md)):
no compression anywhere, so every file the CLI or the plugin writes stays readable by both.
Connecting to an existing repository: version 1 is fully supported; version 2 is supported
only when the optional `zstd` PHP extension is present, because restic compresses index and
snapshot files in every version 2 repository, even with `--compression off`. Without the
extension the wizard still connects, because key files and `config` are never compressed;
the first job that reads an index or snapshot file then fails with
`repository_unsupported_version`, whose message names the missing extension. A check in the
wizard is not planned yet. In a version 2 repository the plugin writes
uncompressed blobs and plain-JSON unpacked files, which restic accepts.

### 5.5 Chunking and blob ids

restic's content-defined chunker is implemented exactly: Rabin fingerprint over a 64-byte
window with the repository's irreducible polynomial of degree 53 (`polShift = 45`), `out`
and `mod` tables of 256 entries, minimum chunk 512 KiB, maximum 8 MiB, cut when
`digest & ((1 << 20) − 1) == 0`, the first `MinSize − 64` bytes of every chunk only fill the
window. Files smaller than 512 KiB are one blob. The blob id is the SHA-256 of the plaintext
blob; identical chunks are stored once. The repository polynomial is generated at init with
the same GF(2) arithmetic and Ben-Or irreducibility test restic uses. The unit suite
compares the 17 boundaries of a 24 MiB stream with a vector recorded from restic's chunker
library (`tests/Fixtures/vectors/go-strings-and-chunker.json`). Parity with the CLI is tested
indirectly. The same files backed up using restic 0.20.0, 0.19.1, 0.18.1 or 0.14.0 add no
new data blobs when the plugin backs them up.

### 5.6 Packs

```
pack = blob_1 ‖ … ‖ blob_n ‖ encrypted_header ‖ uint32_le(header_length)
header entry (v1) = type(1: 0 = data, 1 = tree) ‖ uint32_le(encrypted blob length) ‖ blob id(32)
```

Pack id = SHA-256 of the whole file. Target pack size 16 MiB (restic: minimum 4, maximum
128), header at most 16 MiB. Data blobs and tree blobs never share a pack. Packs are
assembled in memory as ciphertext, uploaded with one `POST`, and only then recorded in the
checkpoint as unindexed; the index file follows in the same or a later tick.

### 5.7 Index

`index/<id>` is encrypted JSON `{"packs":[{"id":hex,"blobs":[{"id":hex,"type":"data"|"tree","offset":n,"length":n}]}]}`.
`packs` and `blobs` must be lists in the text of the file: like restic, the decoder refuses
an object in their place, also an empty one and one whose members are named `"0"`, `"1"` and
so on, which PHP decodes into the same array as a list. Where a name comes more than once,
only the last member with it is judged: restic also refuses a file in which an earlier one
holds an object, the decoder does not. No writer of the format repeats a name.
A backup does not write an index file in every tick. At the end of a tick it writes one when
50 000 blobs of uploaded packs wait for it or when 600 seconds have passed since the last
index file, and it writes the rest when it finishes (`BackupRun::INDEX_INTERVAL_SECONDS`,
`IndexFile::MAX_BLOBS`); like restic it starts a new file after 50 000 blobs. Until then
an uploaded pack is recorded only in the job's checkpoint. Reading merges all index files into an
`IndexSet`, pack by pack, so that an index file of 50 000 blobs is never decoded as a whole
(`IndexFile::packs()`); a per-repository **index cache** in the private directory stores the
merged set so later runs load one file instead of all index files. The cache file is a row
of pieces of about one megabyte, each sealed with the master key and carrying a mark of its
file and its number; reading and writing need one piece in memory besides the index, and a
file whose pieces do not belong together is discarded. The length before each piece is not
sealed: one that no piece can have (more than the names of the listed index files, or more
than `IndexSet::MAX_PIECE_BYTES` of index) discards the file as well, before the memory
budget is asked, so that a damaged length cannot end every job with `index_too_large`.
Prune-lite rewrites index files without the deleted packs.

### 5.8 Trees and nodes

A tree blob is JSON `{"nodes":[…]}` with nodes **strictly ordered by name (bytewise) and
unique**; restic rejects unordered trees. Node fields: `name` (Go `strconv.Quote` escaping
without the surrounding quotes), `type` (`file`, `dir`, `symlink`; devices and sockets are
skipped with a warning), `mode` (permission bits plus Go `FileMode` type bits: dir `1<<31`,
symlink `1<<27`, setuid `1<<23`, setgid `1<<22`, sticky `1<<20`), `mtime`, `atime`, `ctime`
(RFC 3339 in UTC, written with `Z`), `uid`, `gid`, `user`, `group`, `inode`, `size`, `links`,
`linktarget`, `content` (list of data blob ids for files, `null` for directories),
`subtree` (tree blob id for directories). JSON is emitted with Go-compatible escaping so that
identical directories produce identical tree blobs across implementations where possible;
parity is measured in tests but is not a correctness requirement.

Times come from PHP's `lstat()`, so they have whole seconds, and `atime` is a copy of `mtime`,
as restic records it by default, so that reading a file does not change its node
(`Engine\Backup\NodeFactory`). The plugin writes every time in UTC (`Support\Rfc3339`), the
snapshot's `time` included. restic's retention rules put a snapshot into the day, week or
month of its own offset, so for snapshots the plugin wrote these periods begin at midnight
UTC, not at the site's midnight. Order and uniqueness of the nodes are enforced both when a
tree is written (`TreeCodec::encode()`) and when it is read (`TreeCodec::decode()`). The
comparison uses the decoded name bytes, including non-UTF-8 names, rather than their JSON
or Go escapes. An unordered or duplicate name makes the whole tree unreadable; the
repository check reports it and restore refuses it before using its entries.

### 5.9 Snapshots and locks

Snapshot: `{"time","parent","tree","paths","hostname","username","uid","gid","excludes","tags","program_version","summary":{…}}`
with `hostname` set to the site host, tags `backups-for-restic`, `site:<12 hex>`,
`run:<job-id>`, and `program_version` `backups-for-restic/<version>`.

Lock: `{"time","exclusive","hostname","username","pid","uid","gid"}`. Backup and check take
a non-exclusive lock; forget and prune take an exclusive lock (as restic does). A lock
older than 30 minutes is stale for every restic client, so every tick of the job that holds
a lock **refreshes** it (write new, delete old) when it is older than 5 minutes; nothing
refreshes the lock of a job between its ticks. Every call of a run deals with its lock
first and loads the index after that ([ADR 0030](adr/0030-lock-continuity.md)). A run
trusts its lock for 25 minutes, five less than other clients honour it, because they judge
by their own clocks. A backup, check or forget that finds its lock older, or gone, takes it
again (`Locking::acquire()`), which fails with `repository_locked` while another client
holds a conflicting lock. Then it looks at what other clients did in the meantime:

- A **backup** keeps a record of the index files it has read its index from or written, and
  of the packs it uploaded that no index file lists yet, and compares it with the server.
  If all are still there, nothing it uploaded or reused was removed, and it continues. If
  one is gone, as after `restic unlock` and `restic prune` on another computer, the run is
  given up and the job starts a new one, at most three times; a snapshot built on the old
  run could refer to data that no longer exists. The backup looks at its lock not only at
  the start of a call but also in every checkpoint and at the end, before it writes an
  index file or the snapshot, so a lock lost in the middle of a call is noticed in time.
- A **check** compares the index files and snapshots it listed. If one is gone it starts
  over with a new listing, at most three times, instead of reporting as damage what a prune
  or forget removed. It looks once more before it ends.
- A **forget** needs no comparison: what others wrote in the meantime can only make its
  policy remove more.
- **Prune** compares the snapshots and the index files with those it marked and read. If
  they are the same it goes on; otherwise it starts its marking again, at most three times,
  because another client may have written snapshots or repacked
  ([ADR 0022](adr/0022-maintenance-scope.md)).

Every lock carries the number of the run that wrote it, and every run writes its checkpoint
with that number before it takes its first lock. A lock left by a request that died, at any
point, is therefore recognised and removed by the next request of the run or when the job
gives the run up. Locks are deletable even in append-only mode.

### 5.10 REST protocol

| Operation | Request |
| --- | --- |
| Create repository layout | `POST {path}?create=true` → 200 |
| Exists / size | `HEAD {path}/{type}/{name}` → 200 with `Content-Length`, or 404 |
| Load | `GET {path}/{type}/{name}`; partial: `Range: bytes=a-b` → 206 |
| Save | `POST {path}/{type}/{name}` with `Content-Type: binary/octet-stream`; the server verifies that SHA-256(body) equals the name (400 otherwise); 403 if the object exists |
| Delete | `DELETE {path}/{type}/{name}` → 200; 403 in append-only mode for all types except `locks` |
| List | `GET {path}/{type}/` with `Accept: application/vnd.x.restic.rest.v2` → `[{"name":…,"size":…}]` |

`{type}` ∈ `config`, `data`, `index`, `keys`, `locks`, `snapshots`; `data` objects are
addressed flat (`/data/<name>`), the server shards. `{path}` may contain user and
repository segments on a self-hosted rest-server, for example with `--private-repos`, and is
empty for BorgBase. The client sends HTTP basic
authentication in the `Authorization` header (never in the URL), verifies TLS against
WordPress' CA bundle or an uploaded CA certificate for a self-hosted server (never
disabled), disables redirects, sets a plugin User-Agent and times out after
`advanced.upload_timeout_seconds` (default 60 s) per request. Reads, listings and deletions
are tried up to 5 times, with exponential backoff, after a network error, a 429 or a 5xx
other than 507. An upload is tried again after a 429 or such a 5xx, but not after a network
error; the upload of `config` gets one attempt. If the server answers an upload with 403
because the object already exists with the same size, the upload counts as done. 401, 507
(quota exceeded) and 403 on delete are surfaced as dedicated errors
([ADR 0001](adr/0001-restic-rest-backend.md)).

## 6. Backup pipeline

Steps of a `backup` job (`Jobs\Backup\BackupSteps`):

1. **Preflight**: repository configured and password readable, private directory created,
   free disk space for two packs plus 64 MiB, `memory_limit` of at least 96 MB (the value
   after the raise of §4.2), no HTTP probe that found the private directory readable from
   the web (the answer is cached for a day; a probe that could not run does not stop the
   backup), and the repository's `config` present.
2. **Unlocking**: open the repository with the cached master key, which counts only if it
   decrypts the config; otherwise derive it with the resumable scrypt across ticks and cache
   it (ADR 0020). A cached key that no longer decrypts, as after a change of the salts, is
   forgotten and derived again. Every job comes through this step, so it is also where
   `Connection\RepositoryRecord` writes the repository's ID and format version into the
   settings and binds the remembered results to that repository (§10), and where a
   repository of format version 2 is refused with `repository_unsupported_version` when PHP
   lacks `ext-zstd` (ADR 0019).
3. **Backing up**: the engine's `BackupRun`, resumed every tick from its checkpoint. In
   every tick the run takes or refreshes the shared lock before it loads the index through
   the local cache (§5.9). The first tick also removes this site's stale locks. It chooses
   the parent, the newest snapshot of the same host with the same paths, before the run
   exists and so before the lock; a parent that another client removes in between is done
   without. It walks the roots from `Backup\PlanFactory` (distinct, non-nested set of
   `ABSPATH`, `WP_CONTENT_DIR`, the uploads directory and extra roots; excludes from the
   defaults, the settings and the `backups_for_restic_backup_excludes` filter; `CACHEDIR.TAG`
   and `.nobackup` markers). A file whose size, mtime, ctime and inode match the parent
   reuses its blobs; others are chunked, deduplicated, encrypted and packed. Inside the
   private directory only `spool/` is walked: `spool/manifest.json` and the database files
   of §7 are virtual. A virtual file whose source cannot continue after its cursor, such as
   a table whose definition changed, starts over, and the chunks of its earlier parts are
   dropped; a complete source hands its warnings to the run. Directories become tree blobs
   bottom-up, then the snapshot is written with `summary` and tags `backups-for-restic`,
   `site:<hash>` and `run:<job>`. The lock is refreshed every five minutes and released at
   the end.
4. **Recording**: last result in `backups_for_restic_state` for the dashboard and Site Health.
5. **Releasing** and **cleaning up** run on every path to the end: an unfinished run releases
   its lock, the scrypt files and the checkpoint are deleted, old jobs are pruned to
   `advanced.keep_jobs`, and index caches of other repositories are removed.

Retention is not a step of the backup job: in mode `plugin` the heartbeat starts a `forget`
job after every successful backup (`retention.run_forget_after_backup`, §8), and the notifier
hears of every finished job (§14).
Files that cannot be read (permission denied, vanished) are recorded as warnings and the
snapshot is still written, matching restic's exit code 3 behaviour.

## 7. Database backup and restore format

The dump is **streamed into the engine** as a set of virtual files; no plaintext dump is
written to disk during a backup ([ADR 0021](adr/0021-database-streaming.md)). Inside the
snapshot the database appears as a directory:

```
<private>/spool/database/000-header.sql          SET NAMES, FOREIGN_KEY_CHECKS, SQL_MODE
<private>/spool/database/010-wp_options.sql      one file per table: DROP, CREATE, INSERT batches, trailer
<private>/spool/database/010-wp_posts.sql
…
<private>/spool/database/900-views.sql           CREATE OR REPLACE VIEW …
<private>/spool/database/manifest.json           format id, server version, time zone, prefix, tables in order with file, rows, bytes and how they were read, views
<private>/spool/manifest.json                    site manifest (format id, home URL, paths, roots, excludes, prefix, plugin, WordPress and PHP versions, repository format)
```

Neither manifest carries hashes of files: restic authenticates every byte it restores, and a
running SHA-256 cannot be carried across ticks ([ADR 0021](adr/0021-database-streaming.md)).

Format `backups-for-restic-dump/1`: one SQL statement per line, escaped values, hex-encoded
binary, ≈ 1 MiB `INSERT` batches, and a trailer line `-- end table rows=<n> bytes=<n>` per
file ([ADR 0021](adr/0021-database-streaming.md)). Rows and definitions are read in
utf8mb4 and with the session time zone `+00:00`, whatever `DB_CHARSET` and the site's
session use; the files and the manifest say so. The importer reads bounded lines and
validates a constrained CREATE/INSERT grammar; it never executes an arbitrary SQL script
([ADR 0035](adr/0035-transactional-shadow-import.md)).

The importer reads statements up to 16 MiB, including the semicolon and excluding LF. The
writer does not refuse a larger one: a row too large to share a statement is written as one
of its own, whatever its size, as long as PHP can hold it, and the manifest records each
table's largest statement so that an importer can compare it with `max_allowed_packet`.
Binary hex encoding can roughly double the source row size. A row whose statement would not
fit into the PHP memory limit stops the dump with `database_dump_row_memory`. Tables with
values of any size fetch only as many rows per page as fit into the memory left, after the
server has reported their sizes, so a row that fits on its own is backed up and one that
does not stops the dump before it is fetched. Generated-only
tables use empty INSERT tuples to preserve their row counts without assigning generated
values. These files remain importable by a normal MySQL/MariaDB client.

Streaming and resumability: a table is produced page by page and fed to the chunker like a
file; chunk boundaries are content-defined, so unchanged parts of a table deduplicate against
yesterday's dump. When a tick's time is up, the table stops after the current page and the
next tick continues after it, so tables of any size finish. Pages follow the primary key, or
a unique key over NOT NULL columns, in full, with a row-value comparison that orders exactly
as `ORDER BY` for every column type: a row that exists for the whole dump of its table, and
whose values in that key do not change meanwhile, appears exactly once, whatever is inserted
or deleted meanwhile. A row whose key value changes between two ticks can be missing or
appear twice: once before and once after the change, if it moves from behind the cursor to
ahead of it, or not at all if it moves the other way.

Consistency is one snapshot per table and tick. The dump reads through a connection of its
own, opened with `$wpdb`'s settings and used only once it is verified to reach the same
server and database, encrypted if the site's connection is. Each table's part of a tick is
one read-only transaction there, which also holds the table's definition. The plugin never
opens a transaction on the site's connection. Without a verified connection of its own the
dump reads through `$wpdb`, with the same session settings around every query, and each
page is then its own read. A table without a usable key is read with one statement on the
dump's own connection; read in several parts, its rows can be missing or repeated, and the
manifest and a job warning say so. Across ticks, the pages of one table and the tables
themselves are read at different moments: a row deleted and inserted again with a new key
between two ticks can appear twice, and an import then stops with a duplicate key. The
manifest records for each table which key ordered its pages, whether all rows come from one
read view, how often it started over and its largest statement, unless the manifest would
then be larger than the 2 MiB that the plugin's restore reads. A table whose definition
changes during its dump starts over, at most three times, then the backup stops with
`database_dump_unstable` ([ADR 0021](adr/0021-database-streaming.md)). Every read waits at
most five seconds for a lock that another connection holds on a table; then the tick stops
with the retryable `database_dump_locked`, and the job continues the table later. Every read
also runs without a row limit, whatever `sql_select_limit` the session inherited.

Restore rehearsals stream verified SQL batches into private InnoDB tables. Imported
rows, cursor and content transcript commit in one transaction. The report compares
engine, charset, collation and rows with the current site and lists compatibility blockers.
Its digest and the original snapshot bind a subsequent live review to the same source.

A separately confirmed database apply reimports the source on supported MySQL sites,
compares its transcript and checks native table identities. After WordPress enters
maintenance, the job waits until the requests that started earlier have ended, for at most
a minute. One multi-table RENAME under WRITE locks then swaps every selected table and its
operation witness. The destination
keeps its site address, WordPress database version, WP-Cron lock, this plugin's options and
the confirming administrator with their user meta. Roles, active plugins and scheduled events
come from the backup, with this plugin added to its active plugins; under the backup's roles
the administrator must keep the capabilities of a database restore.
The previous generation remains available for undo for 24 hours. Undo retains the entire
post-apply generation for another 24 hours and supports manual recovery of later edits.
MariaDB sites and sites with unsupported grants or table dependencies can export the SQL
and import it into a separate database. [Database exchange](adr/0043-atomic-mysql-database-exchange.md), [manual recovery](06-disaster-recovery.md).

## 8. Retention, integrity checks and maintenance

- **Forget** implements restic's `ApplyPolicy` exactly: snapshots newest first; buckets
  `last`, `hourly` (year-month-day-hour), `daily`, `weekly` (ISO year-week), `monthly`,
  `yearly`, each keeping the first snapshot of a new bucket value while counts remain and
  always the oldest snapshot while a bucket has counts left; `--keep-within` relative to the
  newest snapshot that is not in the future, so that one snapshot from a wrong clock does
  not push the others out (restic's `findLatestTimestamp`); `--keep-tag`. The forget job
  selects the snapshots with this site's host name and the tags `backups-for-restic` and
  `site:<hash>` and applies the policy to them as one group (`--group-by host`); the tag of
  the job that wrote a snapshot never splits them. The list of this site's snapshots
  previews the decision (§13.2): `Restore\SnapshotBrowser` applies `Forget::apply()` to the
  same group with the policy that `Jobs\Maintenance\RetentionSettings` builds for the forget
  job too, so the two cannot decide differently. The preview only reads.
  Deletion is `DELETE snapshots/<id>`; HTTP 403 → `repository_append_only`
  ([ADR 0010](adr/0010-retention-and-append-only.md)).
- **Append-only detection** ([ADR 0010](adr/0010-retention-and-append-only.md)): a `DELETE`
  of a random, non-existent snapshot name changes nothing on any server. rest-server answers
  403 in append-only mode before it touches the filesystem and 200 otherwise; other servers
  may answer 404. The wizard probes once, the heartbeat daily (hourly while the last answer
  was inconclusive), and every forget or prune job before it starts. Detecting append-only
  switches retention to `external` immediately; detecting that deletions became possible
  only proposes the switch back to `plugin`, which needs an administrator's click.
  `RetentionGuard` stores each result with a fingerprint of the repository's address and
  REST user name. A result for another repository counts as none: it is not shown, and the
  heartbeat probes at its next run after the repository changed. The dashboard's append-only
  line shows the latest probe of the current repository that had an answer; an inconclusive
  probe does not replace it. Until such a probe exists, the line shows what the wizard's
  connection test found (`repository.append_only`).
- **Prune-lite** ([ADR 0022](adr/0022-maintenance-scope.md)): mark all blobs referenced by
  all snapshots (a walk of every snapshot's trees), then write the index files that list a
  pack with **no** referenced blob again without those packs, delete the old index files,
  and delete the packs last. Every state in between is a repository restic accepts.
  Partially used packs are not repacked; the dashboard shows the space they
  waste, which only a full `restic prune` from any computer reclaims, and the
  disaster-recovery guide explains it. The estimate counts trees exactly and data blobs once
  through a Bloom filter of about two bytes per indexed blob, so it fits small memory limits
  and can only be slightly high; the conformance suite compares it with restic's count of
  unused bytes. Prune takes an exclusive lock, and the maintenance jobs run it weekly by
  default (`schedule.prune.*`). It fails closed: an inconclusive append-only probe stops it
  before the lock; if marking meets a used blob or tree that the index does not lead to, or
  planning finds a used pack that no index file lists, it marks again once with the index as
  the server has it under the lock (another client may have changed the repository in
  between), and a second mismatch stops it without deleting.
  Marking starts over completely or not at all, so a failed request in the middle never
  leaves a tree unwalked. The checkpoint is written before the lock is taken, at every
  change of phase and at the end of every tick: a request that ends while it deletes is
  continued in that phase, and a lock it left behind is recognised by the run's number.
  Prune also deletes the packs on the server that no index file lists, which an
  interrupted backup leaves behind, as restic's prune does. No snapshot can use such a
  pack: marking ends with an error when a snapshot uses a blob the index does not lead to,
  and every used pack must be among the listed ones before anything is deleted.
  The server pack listing streams into `packs.json` in the private job directory. Prune
  validates bounded batches and deduplicates names in `packs.table` on disk; no index
  rewrite or deletion begins before the entire listing is valid and includes every live
  pack. Classification counts distinct live names across batches; a missing pack stops the
  run as repository damage, preserving unindexed packs that may hold recovery copies.
  Unindexed packs never enter the JSON checkpoint: classification and deletion resume by
  table slot. A stored answer that is not a valid listing, such as the error page of a
  proxy, sends the checkpoint back to the download before the error ends the request, so
  the retry asks the server again instead of parsing the same file; check does the same
  with its listing. The run counts the listings it discarded in its checkpoint, because
  the job counts only failures that follow each other and a request that only downloads
  ends normally between two of them. A run downloads again three times in a row at most;
  with the fourth invalid answer the checkpoint stays at the failing batch, every later
  request fails there at once, and the job gives up after its usual number of attempts.
  Both files
  have mode 0600 before content is written. This bounds the new listing path, while the
  existing tree/index sets still need the full-job memory work ([ADR 0033](adr/0033-prune-listing-on-disk.md)).
  If the lock was lost, or not refreshed for 25 minutes, prune takes it again and compares
  the snapshots with those it marked and the index files with those it read, apart from
  the ones it has written or deleted itself. If they are the same, nothing it found has
  changed and it goes on where it was, so a site whose ticks are far apart still finishes.
  Otherwise marking starts over, at most three times, then the job fails with
  `repository_changed`. Packs whose index entries a first plan had already removed are
  found by the next plan as packs that no index file lists, so none is left behind.
- **Check**: structural check (every indexed pack exists with the recorded size, every
  snapshot's tree walk resolves to indexed blobs, every index file can be read). A blob id
  that occurs twice is no finding: duplicates are legal in a restic repository, and the
  index keeps the first location; a whole pack listed in two index files is the warning
  `pack_in_several_indexes`. A pack that no index file lists, as a cancelled or failed
  backup leaves it behind, is the warning `pack_unindexed`; the next prune deletes such
  packs. Then comes **sampled data verification**:
  download `schedule.check.read_data_subset_percent` percent of the packs (default 2 %),
  drawn at random anew for every check without regard to earlier checks, and
  verify header, blob tags and SHA-256 ids. Packs are read in ranged pieces of 8 MiB that
  never split a blob, so memory does not grow with the pack size; a pack whose reading ends
  the request three times becomes the warning `pack_unverified` (ADR 0022). Monthly by
  default; errors raise a persistent critical notice and are reported in Site Health,
  warnings only appear with the result (ADR 0022). The check counts every finding and keeps
  the details of at most 50; when that list is full, an error takes the place of the newest
  warning. The alert and the record of the last check list up to ten findings, errors
  before warnings, each kind in the order found, so that warnings of an early phase cannot
  push an error off the list. Repairs are never attempted automatically; the guide points to
  `restic repair`.
- **Cache hygiene**: the merged index is cached per repository, encrypted, and only while it
  fits `advanced.cache_limit_mib` (default 256 MiB); a larger index is read from the
  repository in every tick instead, and a cache written under a higher limit is removed
  when it is read under a lower one. After each job the `cleaning_up` step removes the
  caches of every repository except the one whose master key is cached
  (`RepositoryAccess::cached_repository_id()`); without a cached key it removes nothing.
  Settings and Site Health show the size, and the cache can be cleared while no job runs
  (`DELETE /cache`). Prune and check read trees on demand and keep no tree cache.
- **Locks**: stale locks (older than 30 minutes) from this site are removed automatically
  before a job starts, as restic does. A stale lock of another host is ignored when the
  plugin looks for conflicts; it is neither reported nor removed, and `restic unlock` from
  any computer removes it.

## 9. Restore

A restore job reads selected paths from a snapshot under a shared repository lock, verifies
their content and writes them into a private staging directory. The admin page and WP-CLI
can start the job. The administrator can download staged files and reports, compare
changes against the receiving site, and separately confirm a live file or database
operation. Staging, review, application and undo each have their own lifetime. For offline
recovery the same snapshot can be opened with restic. [Recovery procedures](06-disaster-recovery.md).

The first reader is `Engine\Snapshot\Reader` ([ADR 0034](adr/0034-snapshot-reader.md)):
snapshot metadata with optional host/tag filters, one directory by snapshot path, and the
site manifest. Each operation takes a shared repository lock before reading and discards
its lazy index before releasing the lock. Longer reads refresh the lock; a lapsed lock
refuses the result and requires a fresh read. It preserves raw name bytes, rejects ambiguous
path components and never follows a link. The REST snapshot routes and the admin snapshot browser both use this reader.

New snapshots record their manifest's original snapshot path in an encrypted locator tag,
built from the resolved private directory (the lexically normalized path only where it
cannot be resolved), which is the path the archiver records;
old snapshots use a search for a unique `spool/manifest.json` that is bounded to 4096
directories and to five seconds per call, not counting the loading of the index. An incomplete or
ambiguous search requires an explicit snapshot path; one that ran out of time gets further
when it is repeated, because the tree cache keeps what it read. Manifests are limited to 1 MiB and
128 blobs, authenticated and checked against their declared file size and schema. Their
contents describe the old site; later apply/import planning must validate every use.

The reader caches tree ciphertext below `cache/<repo-id>/trees/`, verifying both its
authentication and tree ID on every hit. Index and published tree files share the configured
cache limit, with the index taking priority. Either writer evicts trees as needed; clearing
the cache removes both. An index too large for the limit is not cached and leaves the trees
in place. Trees over 4 MiB are read without caching. Temporary ciphertext
needs additional disk space during atomic publication; a temporary tree or index file that
a request left behind is removed once it is a minute old.

| Scenario | Flow |
| --- | --- |
| **Single file** | Verify and stage the selected bytes, then stream them on a single-use authenticated download. Staged contents expire after the configured interval (24 hours by default). |
| **Selected paths to staging** | same per file, directories recursively; staging is owner-only (`0600` files, `0700` directories) and gets the modification time only, the modes of the snapshot are in the report ([ADR 0036](adr/0036-selected-file-staging.md)) |
| **Apply to live site** | Paginated review, confirmed scope and a maintenance window. Live destinations and file identities are checked again. Startup files and configuration require offline recovery. A snapshot's database spool is never applied, and a replacement must keep the live file's owner, group and mode. Replaced files are retained for undo. The undo journal and its copies reach the disk before each live change ([ADR 0041](adr/0041-guarded-file-changes.md)). |
| **Database** | Verify and import staged SQL into private shadow tables. A separately reviewed operation can atomically exchange and undo compatible MySQL generations. Other installations use the manual SQL procedure. |
| **Full site (plugin works)** | files to staging → apply → database, guided |
| **Full site (server lost)** | plain restic CLI procedure, or the wizard's "connect to existing repository" path on a fresh installation followed by the full-site flow |

## 10. On-disk layout

```
<private>/                                 0700  (default WP_CONTENT_DIR/backups-for-restic/, better: BACKUPS_FOR_RESTIC_PRIVATE_DIR outside the web root)
├── .htaccess, web.config, index.php       deny rules / empty index
├── probe/<random>.txt                     HTTP exposure check, deleted after the request
├── runner.lock                            flock target
├── state.lock, notify.lock                flock targets for changes to the state and for sending notices
├── ca.pem                                 uploaded CA certificate of a self-hosted server, if any
├── cache/<repo-id>/index.bin              merged index in pieces, each sealed with the master key
├── cache/<repo-id>/trees/<tree-id>.bin    tree blobs for browsing, encrypted with the master key
├── kdf/<job-id>/                          scrypt table and checkpoint, 0600, deleted when the job ends
├── jobs/create.lock                       flock target while a job is created, and while the setup changes the server or a password (§4.4)
├── jobs/<job-id>/                         job.json (atomic and synchronised), events.log (redacted, capped at 1 MiB), cancel-requested (marker of a cancel request, §4.4), backup/ (backup checkpoint), database.json (dump state), maintenance/ (checkpoint of forget, prune or check), restore/ (checkpoint, local lock and growing report of a restore run; deleted when the job ends)
├── spool/, spool/database/                empty directories the backup walks into; their content is virtual
├── tmp/, logs/                            reserved, empty
├── restores/.htaccess, web.config, index.php   the deny files and empty index of the root, so that a server that lists directories does not list the job IDs
├── restores/<job-id>/                     staging of a restore job that succeeded (ADR 0037); a failed or cancelled job leaves none
│   ├── .htaccess, web.config, index.php   the same, written when the job creates the directory and by every later request that finds them missing
│   ├── files/<path in the snapshot>       the restored tree, each entry under its full snapshot path; owner-only (directories 0700, files 0600) whatever the snapshot recorded
│   └── entries.jsonl                      report: one line per selected entry with type, mode, time, size and link target; links are listed here, not created
└── restores/.removing-<job-id>/           a staging directory whose removal has begun: renamed first, so that no list shows it, then deleted (ADR 0040)
```

Staging does not stay ([ADR 0040](adr/0040-restore-staging-expiry.md), `Restore\Staging`).
A staging directory expires `restore.staging_expiry_hours` (default 24) after its job
finished, by `finished_at` of the job document. From then on it is not listed, and the
next heartbeat removes it. The heartbeat also removes leftovers at once: the directory of
a job that failed or was cancelled, and a directory that no job accounts for. It never
touches the directory of a job that has not ended, nor that of a job whose document this
version cannot read. A directory whose job document was pruned with the old jobs is kept
while it holds the report; its expiry then counts from the creation time in the job ID,
and its counts are unknown. An administrator removes one directory with
`DELETE /restores/{id}`. Every removal renames the directory to `.removing-<job-id>` before
it deletes anything, deletes until a deadline (five seconds in a heartbeat, ten in a
request) and is continued by the next heartbeat; symbolic links are removed, never
followed. The preview data of a file review and the undo workspace of an apply that are
due are removed first, each on its own: one that the file system refuses is counted and
tried again, and never keeps staging from expiring. An undo workspace loses its count of
attempted changes first, synchronised, so that a deletion that stops part way leaves the
window of its apply closed. While any stored job cannot be read
(`JobStore::unreadable()`), the heartbeat sets no staging aside, removes no sealed review and
deletes no undo journal: that job may be an apply within its recovery window whose undo needs
them, or an undo that still runs, and its document cannot say which. What was set aside
before is still deleted.

The plugin uses the private directory by its canonical path, every symbolic link on the way
resolved, and everything above is below that path ([ADR 0007](adr/0007-private-directory.md)).
The path as configured counts where its spelling matters: the checks for relative paths and
dot segments, the URLs that the exposure probe derives from the web roots, the containment
in the document root and the protected paths of a file apply.

No plaintext site data is written to the private directory except restore staging and
database import files, both explicit admin actions; the import arrives with Milestone 3. The
database holds five options:
`backups_for_restic_settings` (autoload yes, small), `backups_for_restic_secrets` (autoload no,
ciphertext including the cached master key), `backups_for_restic_state` (autoload no: the
last backup and the last forget, prune and check, the check alert, the exposure and
append-only probes, the retention switch and proposal, the handled schedule slots, the
repository that the first five belong to, the probe from the browser, the last twenty
deliveries of the recovery kit, the last twenty grants of restored files and archives for
download, the count of wrong WordPress passwords per user (§13.2), and the number and size
of the repository's packs as a backup or a prune last listed them),
`backups_for_restic_notifications` (autoload no: failure streaks, the stale marker, the
repository both are counted for, and the outbox of unsent notices; streaks and marker start
anew when the jobs turn to another repository), and `backups_for_restic_schema_version`
(autoload yes: the layout number of the other options, §18). State and notifications are
documents that several requests change: a job's tick, the heartbeat and a request of the
admin page. A change therefore reads the document from the database, past what the request
read before
(`Options::fresh()`), and holds `flock()` on `<private>/state.lock` until it is written
back (`Storage\FileLock`), so that no request stores a copy from before another one's
change. Notices are handed to the mailer by one request at a time (`<private>/notify.lock`,
not waited for); the state is not locked while a mail is on its way, and what is queued in
that time goes out with the next heartbeat.

## 11. Settings model

Declared once in `Settings\Schema` and stored flat by dotted path (`schedule.backup.hour`) in
one option; secrets live in the `SecretStore`. The block shows the defaults, except
`repository.url` and `repository.rest_username`, which show an example and are set by the
wizard.

```yaml
repository:
  server: rest-server                    # rest-server (default) | borgbase (ADR 0025)
  url: "https://backup.example.net:8000/mysite/wordpress/"   # https only; rest-server: any host, port, path; borgbase: *.repo.borgbase.com
  rest_username: "mysite"                # optional for rest-server without authentication
  ca_certificate: none                   # none | uploaded: whether ca.pem in the private directory is trusted for this server (self-signed TLS)
  append_only: unknown                   # what the wizard's connection test found: yes | no | unknown; the dashboard prefers the latest conclusive probe (§8, ADR 0010)
  format_version: 1                      # of the repository that the jobs opened last: written when the plugin creates it, and by the unlock step of every job
  id: ""                                 # repository id (64 hex), written like the format version; empty from the moment another address is saved until a job has opened what is there
schedule:
  backup: { frequency: daily, weekday: 0, hour: 3, minute: 17 }                  # manual | twice_daily | daily | weekly
  prune:  { frequency: weekly, weekday: 0, hour: 4 }                             # manual | weekly | monthly
  check:  { frequency: monthly, day: 1, hour: 5, read_data_subset_percent: 2 }   # manual | weekly | monthly
retention:
  mode: plugin                           # plugin | external
  keep: { last: 2, daily: 7, weekly: 4, monthly: 6, yearly: 0, within: "2d" }
  run_forget_after_backup: true
backup:
  extra_roots: []
  excludes: []
  exclude_caches: true
  database: { enabled: true, table_scope: prefix, exclude_tables: [] }
restore:
  staging_expiry_hours: 24               # 1–168; hours after the end of a restore job at which its staged files are removed (§10, ADR 0040)
notifications:
  email: { on_failure: true, on_stale: true, stale_after_hours: 36, recipients: [] }
advanced:
  tick_budget_seconds: 20                # 5–300; halved in gentle mode, then capped at max(5, max_execution_time − 10) (§4.2)
  pack_size_mib: 16                      # 4–64
  upload_timeout_seconds: 60             # 10–600; the timeout of every repository request
  cache_limit_mib: 256                   # 16–4096
  cpu_throttle: normal                   # normal | gentle: halves tick_budget_seconds before the cap and rests while working (§16), for CPU-quota hosts
  loopback_chaining: auto                # auto | off
  keep_jobs: 30                          # 5–500
setup:
  completed: false
  recovery_kit_acknowledged_at: ""       # time of the acknowledgement, written by POST /setup/complete
```

Schedules (`Scheduling\SchedulePolicy`) are slots in the site's time zone:

- A backup runs `manual` (never by itself), `twice_daily` (at `hour:minute` and twelve hours
  later), `daily`, or `weekly` on `schedule.backup.weekday` (0 is Sunday).
- Prune and check slots are on the full hour. Prune runs `manual`, `weekly` on
  `schedule.prune.weekday`, or `monthly` on the first such weekday of the month. The check
  runs `manual`, `monthly` on `schedule.check.day` (1 to 28), or `weekly` on the prune
  weekday: it has no weekday of its own, so that all maintenance shares one day.
- The heartbeat starts a job when the most recent slot is newer than the last one it
  handled, so a site that was offline catches up with one run, not with a backlog. The first
  slot of each schedule after setup is only recorded.

Constants override values: `BACKUPS_FOR_RESTIC_REPOSITORY_URL`, `BACKUPS_FOR_RESTIC_REST_USERNAME`,
`BACKUPS_FOR_RESTIC_REST_PASSWORD`, `BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD`,
`BACKUPS_FOR_RESTIC_PRIVATE_DIR`. The server never overwrites an overridden setting or
secret: `Settings::with()` rejects the change (`defined_in_wp_config`), the wizard's save
skips such fields, and `GET /settings` reports which ones are overridden. The admin UI does
not read those flags yet: the wizard shows empty, editable fields for them and the
constant's value wins. Read-only fields in the UI are planned for Milestone 3.

## 12. Core interfaces

The seams as they are in the code. There is no budget type: every resumable call takes a
deadline, a Unix time as `microtime( true )` returns it, works until it is done or the
deadline has passed, and says whether it finished. What it needs for the next tick it keeps
in its own checkpoint.

```php
namespace BackupsForRestic\Backend;

interface Backend {                                    // RestBackend in the plugin
    public function create(): void;                                        // repository layout on the server
    public function size( ObjectType $type, string $name ): ?int;          // null if the object does not exist
    public function load( ObjectType $type, string $name, int $offset = 0, ?int $length = null ): string;   // whole object or a range; throws NotFound
    public function save( ObjectType $type, string $name, string $body ): void;   // write-once; throws AlreadyExists
    public function delete( ObjectType $type, string $name ): void;        // throws AppendOnlyRefused; a missing object is fine
    /** @return array<string, int> sizes by name; -1 when the server reports names only */
    public function list( ObjectType $type ): array;                       // skips names that are not object ids
}

namespace BackupsForRestic\Crypto;

final class Cipher {
    public function __construct( MasterKey $key, RandomSource $random );
    public function seal( string $plaintext ): string;                     // nonce ‖ ct ‖ tag
    public function open( string $data ): string;                          // throws AuthenticationFailed
}

final class Scrypt {
    public function __construct( string $password, string $salt, ScryptParameters $parameters, int $length = 64, ?string $table_file = null, array $checkpoint = [] );
    public static function derive( string $password, string $salt, ScryptParameters $parameters, int $length = 64 ): string;   // in one go, table in memory
    public function run( float $deadline = INF ): bool;                    // true when finished
    public function result(): string;                                      // the derived key
    public function checkpoint(): array;                                   // state for the next request; needs $table_file
    public function progress(): float;                                     // 0 to 1
}

namespace BackupsForRestic\Format\Chunker;

final class RabinChunker {                             // restic's chunker for one polynomial
    public function __construct( int $polynomial );
    public function reset(): void;
    public function next_split_point( string $buffer, int $offset, int $length ): int;   // -1: no boundary in this piece
}

final class ChunkReader {                              // reads a ByteSource in 512 KiB pieces
    public function __construct( ByteSource $source, RabinChunker $chunker );
    public function next(): ?string;                                       // the next chunk, null at the end
}

namespace BackupsForRestic\Engine;

final class BlobStore {                                // deduplicating writer; reading is Repository::load_blob()
    public function __construct( Repository $repository, IndexSet $index, int $pack_size = self::DEFAULT_PACK_SIZE );
    public function save( BlobType $type, string $plaintext ): Id;         // dedup, encrypt, add to the open pack, upload a full pack
    public function has( BlobType $type, Id $id ): bool;                   // indexed or in an open pack
    public function flush(): void;                                         // upload the open packs
    public function write_index(): array;                                  // index files for uploaded packs; returns their ids
}

namespace BackupsForRestic\Jobs;

interface Step {                                       // one resumable step of a job
    public function advance( Job $job, EventLog $log, float $deadline ): bool;   // true when the step is done
}

interface StepProvider {                               // maps a job type and a step name to its code
    public function step( JobType $type, string $name ): Step;
}

interface JobObserver {                                // told when a job starts and when it ends
    public function state_changed( Job $job, JobState $previous ): void;
}
```

The engine runs follow one shape without sharing an interface. `BackupRun`, `ForgetRun`,
`PruneRun` and `CheckRun` have `start()`, `resume()`, `abort()` and
`advance( float $deadline, ?Throttle $throttle = null ): bool`. `RestoreRun` has `start()`,
`resume()` and `advance( float $deadline ): bool`. `KeyUnlock::run( float $deadline )`
returns the master key, or null when it needs another tick.
`Jobs\Runner::tick( float $budget ): TickReport` is what every driver calls.

Ports that the WordPress layer implements: `Storage\Options` (`WpOptions`),
`Backend\Http\HttpClient` (`WpHttpClient`), `Database\Dump\Connection` (`WpdbConnection`),
`Storage\ExposureCheck` (`WpExposureCheck`) and `Notifications\Mailer` (`WpMailer`). Time and
randomness come through `Support\Clock` and `Crypto\RandomSource`, secrets through
`Secrets\SecretStore`, and input for the chunker through `Support\ByteSource` and
`Support\ResumableByteSource`.

Test doubles in `tests/Support/`: `InMemoryBackend` (with an append-only switch),
`CountingBackend` and `HookedBackend` (wrap a backend to count loads or to act before a
request), `CurlHttpClient` (the `HttpClient` port on cURL, for the conformance suite and the
development tools), `InMemoryOptions`, `FrozenClock`, `FixedRandom`, `RecordingMailer` and
`MysqliConnection`. Steps are scripted in the tests that need them (`RunnerTest`). The real
`rest-server` and the restic CLI are driven by `RestServer` and `ResticCli` in the
conformance and integration suites.

## 13. Surfaces

### 13.1 Admin UI

Menu "Backups" (`manage_options`, filterable; multisite is refused in 1.0). One admin page
with six tabs (`AdminPage::TABS`): Dashboard (cards for the last backup, the next backup and
the repository host with its append-only status, the running job with progress and a "keep this
tab open" hint, recent jobs, Back up now; maintenance cards for old snapshots, unused data
with the space only a full prune reclaims, and the repository check, each with its last
result, next run and a button; notices that stay until their cause is gone: check findings,
a switch to retention mode `external`, and the proposal to switch back), Snapshots (the
snapshot browser described below), Restores (private staging, downloads, rehearsals,
live reviews and undo, and the discard of a database restore's binding whose private tables
are on another database server), Settings (Schedule, Old snapshots, Maintenance,
Notifications, What to back up, Advanced, and Repository with the address, the working
directory, the cache and the recovery kit; saving sends only changed fields, so an open
page cannot undo an automatic switch), Environment (the Doctor's checks) and Logs (the recent jobs and, for a chosen job,
its events and warnings on the page; there is no download). Until setup is completed the
page shows the setup wizard instead
(Environment → Repository server → Password, with the unlock progress when an existing
repository is connected → Recovery kit → Schedule → First backup). The repository step
offers an own restic REST server, which is preselected, and the BorgBase preset as the
second option, takes the credentials and, for an own server, an optional CA certificate,
tests authentication, listing and a ranged read, and detects append-only
([ADR 0010](adr/0010-retention-and-append-only.md)). The dashboard shows how much
the repository holds, as measured after the last backup or prune; how that grows over time
is not shown yet and is planned for Milestone 3.

The whole UI is one classic script, `assets/js/admin.js`, on core's `wp-api-fetch`,
`wp-i18n` and `wp-dom-ready`. The server renders only the frame of the page, and every view
and action goes through the REST API. Without JavaScript the page shows a notice and nothing
else: there are no server-rendered fallbacks
([ADR 0012](adr/0012-admin-ui-without-build-step.md)).

The wizard's environment step shows the Doctor's checks (§13.4), each with a label and a
hint. A critical finding keeps the Continue button away in the browser, and the server
enforces it at the end: `POST /setup/complete` checks the acknowledgement of the recovery
kit, that the cached key opens the repository, and that the Doctor has no critical finding
(409 `environment_blocked`). The routes before it do not ask the Doctor. A private
directory whose probe could not run is no critical finding and does not stop the setup
(§13.4). The step measures nothing about the site and shows no expected duration of the
first backup: an estimate needs a backup to measure against (§14).

### 13.2 REST API (`backups-for-restic/v1`)

| Route | Purpose |
| --- | --- |
| `GET /status` | dashboard: last backup, next slot, active and recent jobs, repository host and how much it holds (`usage`, from the listing of its packs after the last backup or prune; a job asks for that listing once, so a listing that outlasts the request cannot keep a finished job from ending), retention mode with switch and proposal, last and next maintenance runs, check alert with translated findings |
| `GET /environment?fresh=1` | Doctor results with translated texts, and `browser_probe`: whether the site's own probe of the private directory got no answer |
| `POST /environment/probe`, `POST /environment/probe/report` | the probe of the private directory from the administrator's browser (§13.4): a probe file's name and URL (URL null if the directory is outside the web root) and as `control` the URL of the plugin's stylesheet at the same origin (null if there is none); then that name with the status and body the browser got for both, the stylesheet's as `control_status` and `control_body`, answered with `exposed` true, false, or null for a report that says nothing (not the pending probe, older than five minutes, a failed request, or a refusal without the stylesheet) |
| `GET /jobs`, `POST /jobs` | recent jobs; start a backup, or with `type` a forget, prune or check job, or with `type: restore` a restore to staging: `snapshot` is the full 64-character ID, `paths` a list of at most 256 absolute paths as the snapshot holds them, the whole snapshot without it. Refused with 400 `invalid_job_type`, `invalid_snapshot` or `invalid_paths`, or with 409 and, in this order, `setup_incomplete` until the setup is completed, `configuration` without a repository address, `retention_external` for forget and prune in that mode, `job_conflict` while another job is active, `job_unreadable` or `job_inaccessible` with the job's ID as `job` while a stored job cannot be read (§18) |
| `GET /jobs/{id}` | one job; while it runs, the request advances it for up to 5 s (admin polling). 404 `not_found` only for an ID that no job has; a stored job whose document cannot be read answers as the refusal of a new job does (§18): 409 `job_inaccessible` or `job_unreadable`, with the job's ID as `job` in the error data |
| `POST /jobs/{id}/cancel`, `GET /jobs/{id}/log` | request to cancel, recorded as a marker file (§4.4); redacted events. Their answers for an ID that no job has, and for a stored job whose document cannot be read, are those of `GET /jobs/{id}` |
| `GET /jobs/{id}/kept-entries?offset=0` | one page of up to 100 entries that an ended file undo kept, with their reasons, and `total`, `offset` and `next_offset` ([ADR 0041](adr/0041-guarded-file-changes.md)); an ended undo that kept nothing has an empty list. 404 `not_found` only for an ID that no job has; a job that exists says why it has no list: 404 `not_an_undo` for a job of another type, 409 `undo_not_ended` for an undo that is queued or running, 409 `job_unreadable` for a document that this version cannot read and `job_inaccessible` for one that the PHP user cannot open, as for `GET /jobs/{id}` (before 2026-11-26 each of them was "The job does not exist."); 400 `invalid_plan_page` for an offset outside the list, 409 `kept_list_unavailable` for an undo that kept entries but could not seal their list (its log says why), or whose sealed list is missing a part or was changed since; the undo's counts stay in its result, and why it kept each entry is recorded nowhere else. A file undo reviews every entry on its own: `GET /undo` also lists partly undone applies with `state` and `kept`, and with `state` `unreadable` an apply within its window whose undo records cannot be looked up, which it keeps and whose undo it refuses with 409 `undo_records_unreadable` until they can be read, and an undo review has `totals` by action and, when it finds nothing left to revert and records the apply as undone, `undone: true`. A review that reverts nothing while it keeps an entry is not applicable and has no `review_token`: a client shows its totals and does not confirm it, and `POST /undo/{id}` without the token of an applicable review answers 409 `live_plan_changed`, as for any confirmation that was not reviewed. `undo_nothing_to_revert` is the refusal of the service behind the route for a confirmed review with nothing to revert, which WP-CLI reports after its own review; the route would pass it on as 409, but issues no token that leads there |
| `GET /snapshots?scope=site\|repository&fresh=1` | snapshots, newest first: with `scope=site` (the default) those of this site, with `scope=repository` all of the repository. Each list is cached for 5 minutes; `fresh=1` reads it again. Answers `scope` and `snapshots`, each with `id`, `short_id`, `time`, `hostname`, `tags`, `data_added`, `files`, `bytes` and `retention` (the retention preview described below) |
| `GET /snapshots/{id}` | one snapshot by its full ID: the fields of the list, `paths`, `tree`, and `manifest` with the site's manifest (`path`, `token`, `data`) or null. `manifest_state` is `found`, `none`, or `unreadable` for a manifest that cannot be located or is not valid |
| `GET /snapshots/{id}/tree?path=/&offset=0&limit=200` | one page of one directory of a snapshot; the entries, the paging and the path tokens are described below |
| `GET /restores` | what finished restores staged, newest first (§10, [ADR 0040](adr/0040-restore-staging-expiry.md)): for each `job`, `snapshot`, `finished`, `expires`, `files`, `bytes`, `staging` and `report`, the last two as names relative to the private directory. Snapshot, counts and `finished` come from the job document and are null once it was pruned. Also `expiry_hours`, the setting, and `removing`, the number of directories that are due or being deleted (while a stored job cannot be read, only those being deleted: what is due stays). Staging that expired and the directory of a restore that has not ended are not listed |
| `DELETE /restores/{id}` | removes the staging directory of one job, whatever became of the job. It leaves the list at once; `removed.complete` is false when deleting takes longer than ten seconds, and the heartbeat finishes it. The answer carries the list as `GET /restores` gives it. 400 `invalid_job_id` for a name that is no job ID, 404 `restore_not_found` if nothing is staged for the job, 409 `restore_active` while the job is a restore that has not ended (an active job of another type has no staging and answers 404), 409 `restore_in_use` while a file preview, apply, undo or database apply that uses the staging has not ended, 409 `restore_kept_for_undo` while the undo of an apply needs it, until the end of the apply's recovery window, which the message names and the error data carries as `until` (Unix time), 409 `job_unreadable` or `job_inaccessible` if its document cannot be read, with the job's ID as `job` in the error data |
| `POST /restores/{id}/download` | Issues a single-use browser POST form for a file, ZIP, restore report or database rehearsal report. The browser submits the grant to WordPress for streaming. A file (`kind: file` with `path`) or the ZIP (`archive`) is restored site content, possibly `wp-config.php` and the database dump: its grant needs the WordPress password of the current user as `user_password` in the JSON body (403 `wrong_user_password`, 429 `user_password_throttled`), and it is recorded before the token is issued. The two reports (`report`, `database_report`) hold names, counts and table metadata, the database report also the snapshot's role map and list of active plugins (`wordpress_state`, base64 of the stored values), and need the capability alone. Every other kind counts as content. 404 `download_not_found` for what the restore does not hold |
| `POST /restores/{id}/database/plan`, `POST /restores/{id}/database/apply` | Review a rehearsed database exchange and confirm the exact table generations. The review answers `entries` (each table with `replace`, `create` or `keep`), `blockers`, `missing_privileges`, `kept` (what the exchange keeps from the running site: `site_address`, `database_version`, `cron_lock`, `plugin_data`, `administrator`), `restored` (what comes with the backup: `roles`, `active_plugins`, `scheduled_events`), `plugin` (this plugin's path in `active_plugins` and whether the exchange adds it), `plugins` (`deactivated`, active now and not afterwards, and `activated`, the other way round, each entry with `plugin` and `file`, whether WordPress would find its file, and `readable`), `roles` (each role that only one generation has or whose stored capabilities differ, with `change` `added`, `removed` or `changed`, the capabilities granted only afterwards, `added`, and only now, `removed`, the denials stored only afterwards, `denials_added`, and only now, `denials_removed`, and `readable`) and `missing_capabilities`. `readable` is false where the running site's list or role map cannot be read: it is then not compared, everything of the other generation is listed, and the review does not refuse for it. Each list holds at most 50 `entries` and counts the rest in `more`. `digest` covers all of it, `applicable` is false with a blocker. A rehearsal from before the comparison of roles and plugins answers 409 `database_review_required`; one whose report says that the backup has no role map or list of active plugins (`null`) gets the blocker that names it. Without one of the live options, users and user meta tables the review answers the blocker `wordpress_tables` and compares nothing. |
| `GET /database-changes`, `POST /database-changes/{id}/undo-plan`, `POST /database-changes/{id}/undo` | Discover retained database generations, review an undo and queue it. The undo review has the fields of the database review; `restored` and the differences then concern the earlier generation that comes back. In every database review, a name that the database reports in bytes that are not UTF-8, such as a database name through a connection in latin1, has U+FFFD in place of each byte that is not part of a valid sequence, as the snapshot routes answer names; the digest is that of the bytes. |
| `GET /database-changes/foreign` | The finished database applies whose binding the connected server places on another database server or in another database, each with `job`, `restore`, `generation` and `retained_until`, and `complete`: false where a server could not be asked or identified; the list then holds the jobs that recorded `database_foreign` ([ADR 0043](adr/0043-atomic-mysql-database-exchange.md)). `discarded` lists the bindings discarded in the last 90 days whose tables are not marked as taken care of, newest first, each with `job`, `restore`, `at`, `via`, `database`, `server`, `tables` and `until`, the end of its listing; the job history keeps their jobs until then. `write_allowed` says whether the account may discard a binding and mark its tables as taken care of, `write_lacking` and `file_mods_disallowed` why not, as in the review of a discard; 500 `database_discard_storage` if the jobs could not be read. |
| `POST /database-changes/{id}/discard-review` | Reviews the discard of such a binding and changes nothing: `job`, `restore`, the database and server UUID of the private tables (`database`, `server`), those that the site uses now (`connected`), the private table names (`tables`), `generation`, `retained_until`, `undo_until` (the end of an undo that the discard ends, or null), `kept` (`live_database`, `private_tables`, `job_record`) and `digest`, with `site_url`, `write_allowed`, `review_token` and `reviewed_at` as in every review, and why an account may not confirm it: `write_lacking`, the capabilities of a database restore that it lacks, and `file_mods_disallowed`, whether the site's file modification policy (`DISALLOW_FILE_MODS` or the `file_mod_allowed` filter) is the reason, for which WordPress gives no account `update_core`. 409 `database_binding_missing` if no database apply with this ID holds a binding, `job_unreadable` or `job_inaccessible` with the ID of a stored job whose document cannot be read, this one's or any other's, `database_binding_discarded`, `database_binding_busy` while the job or an undo of it has not ended, `database_binding_verified` if the connected database holds the tables, `database_binding_unverified` for a server without a server UUID; 500 `database_binding_unreadable` for a damaged record, also one whose saved server UUID or database name is missing or malformed, `database_discard_failed` for a database failure, which the log of that job names, `database_discard_storage` if the jobs in the private directory could not be read, and `database_discard_unexpected` for any other failure, which nothing records. |
| `POST /database-changes/{id}/discard` | Forgets the binding as its review showed it, confirmed as an undo is: `reviewed` and `site_url` (400 `live_confirmation`), `digest` and `review_token` (409 `database_discard_changed`) and the WordPress password as `user_password` (403 `wrong_user_password`, 429 `user_password_throttled`). Under the runner lock (409 `database_discard_busy`) the review is made again: 409 `database_discard_changed` if the binding is no longer as it showed it, the codes of the review otherwise; 500 `database_discard_storage` if the jobs could not be read or the decision could not be recorded. Nothing is dropped; the answer names the database, the server UUID and the private table names to remove there, and `until`, the end of their listing (until 2026-11-26 the password alone confirmed it). |
| `POST /database-changes/{id}/discarded-done` | Ends the listing of the tables of a discarded binding: the administrator says that they are taken care of. Needs the capabilities of `database_writes_allowed`. The job records who did and when (`database_discarded.done`), and the job history then prunes it as any other. Answers `done`, the entry that is no longer listed; 409 `database_discarded_missing` if the job lists no such tables, 500 `database_discard_storage` if the jobs could not be read or the decision could not be recorded. |
| `POST /restores/{id}/apply-plan`, `POST /restores/{id}/apply` | Inspect live file changes, then queue the reviewed plan. The optional JSON boolean `include_config` includes configuration in the detailed review. Startup files, including wp-config.php, require offline recovery. A review has `actions`, its entries by action (`create`, `replace`, `mkdir`, `keep`, `skip`, `refuse`), on every page of a background review; null for one sealed by an earlier version. |
| `GET /undo`, `POST /undo/{id}/plan`, `POST /undo/{id}` | List reversible apply jobs, review the original files to recover, then queue the reviewed undo. A review or an undo of an apply whose document is stored but cannot be read is refused with 409 `job_inaccessible` or `job_unreadable` and the job's ID as `job`, as by `GET /jobs/{id}`, not with `undo_unavailable` (before 2026-11-26), which is left for an apply past its window, undone completely, or an ID that no job has; the list cannot show such an apply, since its document does not say that it is one |
| `GET /settings`, `PUT /settings` | settings; secrets only as set/overridden flags; PUT accepts schedule, retention, backup, restore, notifications and advanced fields; GET also reports the cache size, the latest five deliveries of the recovery kit (`kit_views`) and the latest five grants of restored content (`downloads`: time, login, job, kind and the path in Go's quoting) |
| `DELETE /cache` | clears the local cache (409 while a job runs) |
| `POST /repository/test`, `POST /repository/save` | connection test; test and save. For an own server both take an optional `ca_certificate` (PEM): the test uses it from a file of its own under `tmp/` in the private directory, removed after the test, `save` stores it as the server's certificate once the server is saved, and saving a server of the preset removes a stored one. A request without `password` uses the stored REST password, but only for the host, port and user name it was saved for; for another server or user name it is refused with 400 `password_required` before anything is sent. `save` changes nothing while a job is active (409 `job_conflict`). A save that fails half way, also because the options table refused a write without an error (`Options::set()` answers false, `SettingsStore::update()` and `SiteState::bind()` raise `StorageFailed`), tries to put back what it changed: the settings, the REST password, the cached key and the certificate. Each part is tried on its own; while the database keeps refusing writes, a part can stay as the failed save left it, and the request answers with the error either way. Where `wp-config.php` defines the address, the certificate of a tested address that is another one is not stored; saving another address empties `repository.id` and drops the results remembered for the old repository |
| `POST /repository/create`, `POST /repository/connect` | new repository, or the password of an existing one plus an unlock job. Both answer with the recovery kit: without further proof until the setup is completed, afterwards only if the request carries the administrator's WordPress password as `user_password`, else 403 `wrong_user_password`. `create` refuses a chosen repository password of fewer than 12 characters with 400 `password_too_short`; neither stores anything while a job is active (409 `job_conflict`). `create` stores the repository password before the repository exists and puts the earlier one back if the repository does not come to exist; `connect` keeps an entered password apart until the unlock job has opened the repository with it, and only then replaces the stored password and the cached key; a wrong password leaves both in place, and the kit in its answer names the entered one. `connect` without a password unlocks with the stored one. Both answer 409 `ca_certificate_missing` if the settings name an uploaded CA certificate that is not in the private directory |
| `POST`/`DELETE /repository/ca-certificate` | CA certificate of a self-hosted server |
| `POST /setup/complete` | finish the wizard after the recovery kit is acknowledged; 409 `environment_blocked` while the Doctor has a critical finding |
| `POST /recovery-kit` | the recovery kit, only with the administrator's WordPress password as `user_password` (403 `wrong_user_password`, 429 `user_password_throttled`); 409 `ca_certificate_missing` as above |

Every REST endpoint requires the configured backup capability (default `manage_options`).
WordPress authenticates cookie requests with its REST nonce and also accepts Application
Passwords. Download grants, review tokens and recovery kits carry `Cache-Control: no-store`.
A job summary names in `user` (`id` and `login`, the login empty for a deleted account) who
started a restore or confirmed a database change; other jobs have null there.

Every route that asks for the WordPress password asks through one check
(`WordPress\PasswordConfirmation`): the recovery kit, `create` and `connect` after the
setup, file and database apply and undo, and the download of restored content. The
password is read from the JSON body only, never from the address. Wrong passwords are
counted per user in the state option (`Storage\ConfirmationThrottle`), under its lock and
before the password is checked, so that requests sent together cannot share one try. The
first three in a row cost nothing; after the fourth the next attempt waits one minute, and
each further one doubles the wait up to 32 minutes; from the tenth on, each one locks the
confirmation of that user for an hour. While a user waits, no password of theirs is
checked, and the answer is 429 `user_password_throttled` with a `Retry-After` header and
`retry_after` in the error data. The right password forgets the count, and so does a day
without an attempt. An attempt without a password is refused and not counted, as core's
login does. Each wrong password fires core's `wp_login_failed` action with the user's login
and a `WP_Error` with the code `incorrect_password`, so that login limiters and fail2ban
rules count it.

A restore accepts JSON flags `archive` and `database_preview`. To import on another
installation, send `fresh_install: true`, `database_preview: true` and the full snapshot
ID. Omit `paths` so the worker selects content, uploads and the database spool from the
verified source manifest. The staging list reports `fresh_install`, `archive`, the sole
downloadable `file` when one was selected, and availability of the `database_report`.
The fresh-install file preview includes original source and mapped destination paths.
Both file preview routes may return HTTP 202 with a `file_plan` job. Poll that job as
usual. Once it succeeds, send the same preview request with its `plan_job` ID and
`offset: 0`. Each response contains at most 100 entries, `total`, `offset` and
`next_offset`; a null next offset indicates the final page. Confirm with the same
`plan_job` ID and digest. Completed previews expire after one hour; a page of one that
expired or is gone is refused with 409 `file_preview_expired`. Every review answer, of
files and of tables, has `reviewed_at`, the server's time of the review: a job that its
confirmation starts is created then or later, and the admin page looks for it by its type
and that time when the answer to the confirmation is lost or is not JSON. Other refusals
of the file routes: 409 `live_plan_changed` when a file changed after the review, 409
`undo_unavailable` for an apply past its recovery window or undone completely, 409
`undo_records_unreadable` for one whose undo records cannot be looked up, 400
`file_staging_gone` when the staged files expired or were deleted, and 500
`live_plan_failed` when a file changed while it was read or a private record of the
restore or apply could not be read or written.

Live file apply and undo also require `update_core` and permission to modify files under the
site's `wp_is_file_mod_allowed()` policy. Every tick of the job checks both again for the
confirming user. Preview reports list actions and their reasons.
Confirmation uses the preview digest, a review token bound to the authenticated session,
source job, site URL and configuration choice, explicit acknowledgment, the site's exact
home URL and the account's WordPress password. Send those fields in a JSON POST body.
The initiating response returns a short-lived `control_token` and sets it in an HttpOnly,
SameSite=Strict cookie. HTTPS adds Secure. During the job's maintenance window the
initiating client can poll or cancel the job using the cookie or the
`X-Backups-For-Restic-Control` header. The normal WordPress capability and nonce checks
also apply. Such a request cannot name a `rest_route` in a form body. It boots, like the
job's workers, with only this plugin and without the theme.

Live database apply and undo require the configured backup capability plus
`manage_options`, `edit_users`, `promote_users` and `update_core`. The worker checks the
confirming user again before exchanging the tables. Under the roles of the generation that
becomes live, the confirming user's own roles and capabilities must still give all five; the
review refuses otherwise with the blocker `database_admin_capabilities`, and the exchange
checks it again under its write locks, with the capabilities that the review checked: if the
site requires others by then, because the backup capability is configured differently, the
worker stops with `database_exchange_changed`. A record of the user's own capabilities that is
missing, stored more than once, longer than 64 KiB or not in WordPress's form is refused with
`database_admin_capabilities_unreadable`. A role map or a list of active plugins of the
generation that becomes live that is not stored the way WordPress writes it, or that the
rehearsal did not find, is refused with `database_roles_invalid` or
`database_plugins_invalid`, one longer than 1 MiB or 256 KiB with `database_roles_too_large`
or `database_plugins_too_large`, and so is a list that this plugin's entry would take beyond
256 KiB, before anything writes it
([ADR 0043](adr/0043-atomic-mysql-database-exchange.md)). These are job error codes as well;
for a database undo their texts point to a role on the running site and to the retained
tables, not to the staged SQL files. Database changes use a distinct
session-bound review token, the typed home URL and the user's WordPress password.
Staging, the reports and rehearsal use the configured backup capability; the download of a
restored file or ZIP also needs the WordPress password. A database preview can report
`applicable: false` with compatibility or privilege blockers.

The recovery kit is in the answer of three routes. During the setup `create` and `connect`
return it freely, which is how the wizard shows it once; from then on all three ask for the
WordPress password of the current user in every request, through the check described above. The stored
REST password leaves the site only for the server it was saved for
(`Connection\Setup::rest_password()`): the same host, compared without regard to case, the
same port and the same user name. A request that tests or saves another server or user name
has to carry the password. A REST password defined in `wp-config.php` goes to another
server only if the request carries that same password, and saving a server without a user
name deletes a stored REST password.

The Snapshots tab reads the three snapshot routes of §13.2. A switch chooses between this
site's snapshots and the whole repository, and a button reads the list again past the
cache. The list has time, id, host, tags, files, size and new data, and for this site's
snapshots what the next cleanup does with each: "Will be kept" or "Will be removed at the
next cleanup". Without a preview the tab says that this site removes no snapshots at the
moment. Opening a snapshot shows its details, then the site it holds as the manifest
describes it (site address, the versions of WordPress, PHP and the plugin, table prefix,
whether the database is included, the directories and the exclude patterns), or a sentence
that the snapshot has no manifest or that it cannot be read. Below that is the directory
browser: one directory per request, a button for the next page, and a path from the top
directory to the open one whose steps lead back up. An entry shows its name, type, size
and time; a link shows its target as text and leads nowhere. The script asks for a
directory by the token of its entry and never builds a path from names, and it marks a
name that is not exact. Every entry has a checkbox. The ticked paths are listed below the
browser with their count and live in `restoreSelection` of `assets/js/admin.js`: the full
ID of the snapshot and a map from the token of each entry to its shown path, whether that
text is exact, and its type. Opening another snapshot empties it. The restore controls use this selection to start the staging job. While a request runs, the
tab says what it loads inside the page's live region. A locked repository
(`repository_locked`) gets a sentence of its own and a button to try again, every other
error the message and hint of the route. Names, tags, link targets and manifest fields come
from the repository and enter the page as text nodes only, each in a `<bdi>` element that
keeps its writing direction apart from the text around it. One that holds a character that
Go's `strconv.IsPrint()` does not print, such as a control character, a zero-width
character or a right-to-left override, is shown in double quotes with Go's escapes, as
`ls` prints it (`printable()` in the script, `SnapshotBrowser::printable()` on the command
line); the file review shows the paths that the server quoted the same way. Table names and
database server versions of the database reviews are shown the same way. A selection of the
entire snapshot holds every entry: the browser shows each one as selected, and nothing in
it can be added.

The three snapshot routes read through `Restore\SnapshotBrowser`, which turns what
`Engine\Snapshot\Reader` returns into response data
([ADR 0034](adr/0034-snapshot-reader.md), [ADR 0039](adr/0039-snapshot-routes.md)). Every
read takes a shared repository lock of its own and releases it, and uses the index and tree
cache of the jobs within `advanced.cache_limit_mib`. A request knows PHP's memory limit as
a job does, so an index that does not fit is refused with `index_too_large`
([ADR 0031](adr/0031-compact-index-and-memory-budget.md)).

- **Scope.** The snapshots of this site are those with the site's host name and the two
  tags that the retention selects them by, `backups-for-restic` and `site:<hash>` (§8).
  `scope=repository` also lists the snapshots of other sites and of other restic clients
  that share the repository; all of them can be read and browsed. Both lists live in the
  one transient `backups_for_restic_snapshots`, each with the time it was read, so
  everything that deletes the transient (a finished backup, forget or prune, a saved,
  created or connected repository, a job that opens another repository, a saved retention
  setting, an automatic switch of the retention mode) drops both. A
  request that stores its list reads the transient again first, so it never writes back
  the other list from before such a deletion. Each list also names the retention settings
  it was read under (`RetentionSettings::fingerprint()`: the rules of the previewed policy,
  or `none`), and a list of other settings counts as missing. The deletion alone would miss
  a list that a request was still reading when the setting was saved, and a retention
  setting that `wp-config.php` overrides. The WP-CLI command `snapshots` lists every
  snapshot of the repository.
- **Retention preview.** In the site scope every snapshot has `retention`: `keep` if the
  next forget of this site keeps it, `remove` if that forget removes it. The decision is
  `Forget::apply()` over the listed snapshots as one group, which is the group of the
  forget job (§8), with the policy of `RetentionSettings::policy()` and the clock of the
  request. `retention` is null where no forget of this site decides: for every snapshot of
  the repository scope, in retention mode `external`, and with a stored policy that a
  forget job refuses (`configuration`, §14). The answer of one snapshot has no such field.
  The preview writes nothing and removes nothing; it is as old as the cached list, and a
  snapshot that it marks `keep` today can fall out of its day, week or month later.
- **Directories.** `GET /snapshots/{id}/tree` answers one directory per request and never
  follows a link. The answer has `snapshot`, `path`, `exact`, `token`, `parent` (the token
  of the directory above, null for the root), `total`, `offset`, `limit`, `next` (the
  offset of the next page, or null) and `entries` in the order the snapshot stores them.
  `limit` is 200 by default and at most 1000: a larger value is lowered to 1000, and the
  answer names the limit that applied. An entry has `name`, `exact`, `token`, `type`
  (`file`, `dir`, `symlink`, or `other` for devices, pipes, sockets and irregular files),
  `size` (bytes of a file, null otherwise), `mtime` (RFC 3339 as stored, null if the
  stored text is no timestamp), `mode` (permission bits with setuid, setgid and sticky as
  four octal digits), `target` (of a link, null otherwise) and `incomplete` (true if the
  backup recorded a read error for it).
- **Names and path tokens.** A name in a restic repository is a sequence of bytes, and
  JSON carries only UTF-8. `name` is therefore a text to show: the stored name if it is
  valid UTF-8 (`exact` is true), otherwise the name with U+FFFD in place of every byte
  that belongs to no valid sequence (`exact` is false). The same holds for `path` and for
  the `target` of a link. `exact` says no more than that: a valid name can still hold
  control, invisible and right-to-left characters that make it look like another one, and
  a client shows it escaped (see the Snapshots tab above). `token` names the entry's path byte for byte: `b64:` followed by
  the base64url form, without padding, of the absolute path's bytes. The `path` parameter
  takes either an absolute path starting with `/`, which reaches every entry whose path is
  valid UTF-8, or a token, which reaches every entry. A client walks a snapshot by sending
  back the tokens it was given and never builds a path from names that are not exact.
  Paths keep the limits of the reader: 4096 bytes and 256 components.
- **Errors.** A malformed ID or path is 400 (`invalid_snapshot_id`,
  `invalid_snapshot_path`), as is a scope, offset or limit that is not allowed
  (`invalid_snapshot_query`). An unknown snapshot is 404 (`snapshot_not_found`), and so is
  a path that does not exist in the snapshot, or that is a file or a link
  (`snapshot_path_not_found`). The two routes of one snapshot check the ID, the path and
  the paging before they open the repository, so these refusals do not depend on its
  state. A repository under an exclusive lock, as during a prune, is 409
  `repository_locked`. That holds for the list too: it is read under the shared lock of
  the reader like every other read, so the Snapshots tab shows this error instead of a
  list that a running prune may be changing. Without a cached key the routes answer 409
  `configuration`; every other error of the repository is 502 with its code of §14.

Errors of the routes carry the codes of §14 with the prefix `backups_for_restic_`.

### 13.3 WP-CLI (`wp backups-for-restic …`, optional surface)

Commands that exist (`WordPress\CliCommand`): `doctor`, `backup [--wait]`,
`forget [--wait]`, `prune [--wait]`, `check [--read-data-subset=<percent>] [--wait]`,
`tick [--until-idle]`, `status`, `snapshots`, `ls <snapshot> [<path>]`,
`settings list|get|set`, `cancel [<job>]` and `logs [<job>] [--lines=<number>]`. `ls` prints
one directory of a snapshot, `/` by default, through the same `Restore\SnapshotBrowser` as
the REST routes (§13.2): one line per entry with the type (`-`, `d`, `l`, or `?` for a
special file), the mode, the size, the modification time and the name, a `/` after a
directory and the target after a link. The snapshot is its full ID or the first 8 or more
characters, as `snapshots` prints them, if exactly one snapshot starts with them; the path
is an absolute path or a token. A name or target with control characters, a quote, a
backslash or invalid UTF-8 is printed in double quotes with Go's escapes (`\x1b`, `\xff`),
so that a stored name cannot send control sequences to the terminal. `snapshots` prints a
snapshot's host name the same way: another client of the repository chose it.
`settings set` changes what the settings page can change
(`Settings\Schema::editable()`), with the same validation; `cancel` asks the active or the
named job to stop and runs one tick for its cleanup; `logs` prints a job's event log,
which is redacted when it is written. The four commands that start a job end
with an error under the same conditions as `POST /jobs` (§13.2): before the setup is
completed, without a repository address, and `forget` and `prune` in retention mode
`external`. A command that ran its job, with `--wait` or as `apply` and `undo` do, and found
it failed ends with the message of the job's error code for that kind of job, as the admin
page shows it (§14). `status` prints the last backup and the active job only.

`restore <snapshot> [<path>…] [--wait]` restores a snapshot or selected paths into
private staging. `apply-plan <job> [--include-wp-config]` prints file actions as JSON
records. Configuration and startup files require offline recovery. `undo <apply-job>`
reviews and confirms reversal of live file changes, entry by entry, and lists the entries
it kept. `database-discard <apply-job> [--site-url=<url> --digest=<digest>]` prints the review of
the discard of a binding whose private tables are on another database server or in another
database, as the Restores tab shows it, with its digest, and forgets the binding once the site
address is typed at its prompt, which needs a terminal. A script passes `--site-url` together
with `--digest`, the digest of a review that an earlier run printed; one without the other, and
a digest without a value or an empty one, are refused. It drops nothing. A user selected with `--user` needs the capabilities of a
database restore. `--yes`, which skipped the only confirmation until 2026-11-26, is refused.
`database-discarded [<apply-job>] [--done]` prints the discarded bindings whose private tables
are still to be removed by hand, with their database, server UUID and table names, for 90 days
after each discard; `--done` with the ID of the job ends the listing of that binding, after
which the job history prunes its job as any other. The recovery guide includes the
`--skip-plugins` procedure for a site whose active plugin prevents normal startup.
While a file job holds WordPress maintenance, the plugin's own commands boot like its
workers, with only this plugin and without the theme. Every other WP-CLI command runs with
the site's plugins, as during core's maintenance ([ADR 0041](adr/0041-guarded-file-changes.md)).
Use the admin page to initiate live changes, configure a repository or review retention.
`recovery-kit` exports credentials on an interactive terminal after setup. The explicit
`--force` option permits a pipe or file. Every delivery is audited.

### 13.4 Site Health

Three tests (`WordPress\SiteHealth`): backup freshness; the environment, which is the
Doctor's result from cached probes; and the last check result. The Doctor (`Doctor\Doctor`)
checks the required extensions (OpenSSL, JSON, mbstring, hash), the memory limit, the time
limit, free disk space, the protection of the private directory, that the two passwords can
be read (a password defined in `wp-config.php` always can; the cached key does not count,
because jobs derive it again) and that all eight salt constants are defined, loopback requests and WP-Cron, and
`zstd` when `repository.format_version` is 2. A private directory inside the web root whose
HTTP probe could not run is reported as `unverified` with the status `recommended`; only a
probe that read the file back is `critical`. In that case the admin page lets the browser
make the request (`Storage\BrowserProbe`): it asks `POST /environment/probe` for a probe
file, requests the file's URL without credentials, waits at most ten seconds for the answer
and reports status and body under the probe's name to `POST /environment/probe/report`. A
report on another probe than the pending one, as from a second tab, changes nothing. A
report with the file's content is the finding
`exposed`. HTTP 403, 404 and 410 without the token count as protected only when the same
probe also got the plugin's stylesheet (`assets/css/admin.css`) back byte for byte from the
same scheme, host and port, with a random query past any cache: a firewall, a CDN
challenge or another virtual host refuses the probe as well, while this site's web server
may serve the directory. The site's own probe makes the same control request after a
refusal, and the browser requests the stylesheet beside the probe file. Other answers leave the result unknown. The site's own probe, where it gets a conclusive answer, comes first. Two more checks are hints that block nothing: `jit`, when PHP's JIT
compiler is on (with it PHP has crashed in backup ticks, §4.2), and `repository_server`,
when the repository server did not answer a request from this host. The latter is asked
only when the checks are run afresh, with one request that waits ten seconds at most, and
is cached for a day for the address it was asked of; any answer counts, also a refusal of
the credentials or an error status. The Doctor has no check of the PHP version, and before a
repository is configured none of outbound HTTPS: it asks no third party, and the wizard's
connection test is what shows that the repository server can be reached. Debug information:
plugin version, repository host and format version, append-only status, cache size, working
directory and the time of the last backup; it does not list the last jobs.

### 13.5 Hooks

Actions `backups_for_restic_job_state_changed( $job_id, $type, $state, $previous )`,
`backups_for_restic_backup_succeeded( $job_id, $snapshot_id )`,
`backups_for_restic_backup_failed( $job_id, $error_code )`,
`backups_for_restic_recovery_kit_shown( $user_id, $via )`,
`backups_for_restic_download_granted( $user_id, $job_id, $kind, $path )` (a grant of a
restored file or ZIP, the path in Go's quoting) and, from Milestone 3,
`backups_for_restic_restore_applied`; filters `backups_for_restic_capability`,
`backups_for_restic_backup_roots`, `backups_for_restic_backup_excludes`,
`backups_for_restic_database_tables_excluded`,
`backups_for_restic_notification_recipients( $recipients, $kind )`,
`backups_for_restic_private_dir`. No hook exposes secrets or raw repository data.

## 14. Errors, logging and observability

`Jobs\JobError::from_throwable()` turns an exception into a stable code, a technical message
for the log and a *retryable* flag; `WordPress\Messages::job_error()` turns the code into a
translated message and a hint. The codes:

| Code | Raised for |
| --- | --- |
| `wrong_password` | no key file accepts the repository password |
| `authentication_failed` | a MAC mismatch on an object (corruption or tampering), or a failure inside the cryptographic code (`CryptoException`); never retried |
| `repository_damaged` | an object that does not match its name or cannot be parsed (`FormatException`) |
| `repository_unsupported_version` | compressed data without `ext-zstd`, an unknown repository version, a key file that is not scrypt or beyond the limits of §5.3 |
| `repository_locked` | a conflicting lock of another client; retryable |
| `repository_changed` | another client removed what a backup or a check relied on while the job's lock did not hold, after a pause of more than 25 minutes or because the lock was removed, more than three times in one job (§5.9, [ADR 0030](adr/0030-lock-continuity.md)). The first three times the job starts the backup or the check over and logs a warning. A restore whose lock did not hold takes up its work again from its checkpoint, three times, and then fails with this code ([ADR 0037](adr/0037-restore-job.md)) |
| `restore_snapshot_missing` | the repository does not hold the snapshot of a restore when the restore takes its lock: the request named an unknown ID, or another client forgot the snapshot. The restore fails in the tick that finds out and keeps no files. Before 2026-11-26 it was `repository_changed`, whose text said that the job "started over three times" |
| `job_unreadable` | an existing job document cannot be read by this version; new jobs and repository changes are refused, and the document is retained (§18) |
| `job_inaccessible` | the PHP user cannot open a stored job: its directory under `jobs/` cannot be searched, its document cannot be read, or a link is in the place of the directory, or something other than a regular file in the place of the document. Refused and retained as `job_unreadable`; the texts name the job, whose ID is its directory's name, and ask for ownership and permissions to be put right (§18) |
| `repository_append_only` | the server refused a deletion |
| `index_too_large` | the repository's index does not fit into PHP's memory limit with room for a backup beside it, or a compressed index file cannot be decoded beside the memory in use ([ADR 0031](adr/0031-compact-index-and-memory-budget.md)) |
| `decompression_limit` | a compressed object that would decode to more than 128 MiB, or whose decoding does not fit into the memory PHP has left, such as a large data blob read beside a large index ([ADR 0019](adr/0019-repository-format-and-chunker.md)) |
| `repository_unreachable` | a network error or an unexpected status; retryable |
| `repository_missing`, `repository_exists` | HTTP 404; a `config` that is already there |
| `unauthorized` | HTTP 401, or 403 on anything but a deletion or an upload |
| `quota_exceeded`, `upload_rejected` | HTTP 507; HTTP 400 (hash mismatch) |
| `secrets_undecryptable` | the stored secrets do not decrypt, for example after a salt change; or a secret that was just stored does not read back unchanged (`EncryptedOptionStore::set()`) |
| `environment` | the private directory cannot be used, too little disk space or memory, an exposed private directory |
| `staging_space` | the free space where a restore stages is known to be too little for the selected files and a reserve ([ADR 0036](adr/0036-selected-file-staging.md)); free space that the host does not report is no refusal |
| `staging_write` | a file operation of a restore in the private directory failed (a write, flush, close, directory, permission or rename): a full disk, a quota or missing permissions |
| `restore_path_missing` | a path selected for a restore is not in the snapshot |
| `database_dump_failed` | a query of the dump failed |
| `database_dump_locked` | another connection kept a table locked for longer than the dump waits, five seconds; retryable ([ADR 0021](adr/0021-database-streaming.md)) |
| `configuration` | something a job or a request needs is not configured: no repository address, no repository password, no cached key for a request, or a stored retention policy without `keep-within` or below the floor (`Schema::retention_floor()`): a forget job that finds one fails with this code and deletes nothing. It is also the code of an `InvalidArgument` without a code of its own, such as a restore whose stored request is not valid or whose snapshot lacks the site manifest that a restore for a new installation needs |
| `key_missing` | the repository key that the job's unlocking step stored is gone when a later step of a backup, forget, prune, check or restore needs it: the plugin discards it when the stored secrets do not decrypt, when it no longer opens the repository, and when another repository address is saved. Before 2026-11-26 such a job ended with `configuration`, "The backup is not configured completely" |
| `setup_incomplete` | a backup, forget, prune, check or restore was asked for before the setup is completed, that is, before the recovery kit is acknowledged. It comes from `JobType::refusal()`, not from an exception: the job is not created. A restore whose preflight finds the setup incomplete ends with it as well (before 2026-11-26 with `configuration`) |
| `job_conflict` | another job is active |
| `no_snapshots`, `retention_external` | prune found no snapshots; forget or prune asked for in retention mode `external` |
| `file_changed_after_review` | a file preview, apply or undo found that a live or staged file, or the site's file policy, changed after the review: before the apply's first change or between two of its changes |
| `file_staging_gone`, `file_preview_expired`, `undo_unavailable` | the staged files of a review expired or were deleted; a preview older than an hour or gone; an apply past its recovery window or undone completely |
| `undo_records_unreadable` | a record of an apply's undo window, `undo/attempted` or `undo/reverted`, cannot be looked up, below a folder that the PHP user cannot search, or is something other than a regular file in a folder at its canonical path, such as a directory or a link (`Files::marker()` looks the name up and checks its file type, not who wrote the file or what it holds): the undo and its review are refused, and the apply keeps its staging, sealed review and journal until its window ends ([ADR 0041](adr/0041-guarded-file-changes.md)) |
| `file_folder_changing` | a background preview, or an apply's check before maintenance, began five readings of the names of a folder with more than 2,048 names, and the folder changed before each of them was complete; the job's log names the folder. An undo review keeps the folder's entries instead |
| `maintenance_busy`, `maintenance_failed`, `maintenance_stuck` | another update holds WordPress's maintenance mode, so the file job did not start; the job's marker could not be published or kept; the site could not leave maintenance mode |
| `file_preview_failed`, `file_apply_failed`, `file_undo_failed` | what else ends a file preview, apply or undo: a plugin exception without a code of its own, such as a damaged private record or a file operation that the file system refused. The codes that the exception classes give (`job_conflict`, `environment`, `configuration` and the repository codes) name failures of backups and of the repository, which these jobs do not have; their log has the technical message |
| `internal` | anything else |

`JobError::from_throwable()` takes the type of the job that the exception ended: for a
file preview, apply or undo it gives the job's own code where the exception carries none
in its context (`JobError::FILE_JOB_CODES`). Whether a code is retried still follows the
exception, so this changes no retry. A failed or cancelled apply records
`entries_completed` and `entries_attempted`, which the admin page shows with the error. A
cancelled job whose cleanup failed afterwards, for example because the site could not leave
maintenance mode, stays cancelled and has that failure as its error: the admin page shows the
error beside the state and the counts, and so does WP-CLI's report of a cancelled undo.

The texts of a job's error code are those of the kind of job that ended with it
(`Messages::job_failure()`; the kind is the job's type, and `undo_review` for the file preview
job that reviews an undo). Where the general texts would name the backup or an action that
the job does not have, the kind has texts of its own: `configuration`, `environment` and
`upload_rejected` for every job but a backup (for forget, prune and check, whose preflight is
the backup's, `environment` says that the server cannot run this job; for the others that
the job could not store or read its working data), `repository_exists` for every job, since
only the setup creates a repository, `decompression_limit` for every job but a restore,
`repository_changed` for a restore, `file_writes_refused`, `maintenance_opcache` and
`file_staging_gone` for an undo, `file_staging_gone` and `file_plan_too_large` for the review
of an undo, and `database_exchange_failed`, `performance_schema`, `database_exchange_foreign`
and `database_admin_collision` for a database undo, beside the five it had. The admin page,
the job list, WP-CLI and the failure e-mail show these; a request, such as a read of
snapshots, and a code without a job keep the general texts. Before 2026-11-26 every kind got
the backup's texts, among them "The backup is not configured completely" and "This server
cannot run the backup right now" for a restore, "The next backup tries again" for a prune and
"Changes already made can be undone" for an undo. `tests/Unit/WordPress/JobMessagesTest.php`
holds the codes that each kind of job can end with and the phrases that its texts must not
contain. The preflight that forget, prune and check share with backups logs its refusals
"for this job" instead of "for the backup".

Only `repository_unreachable`, `repository_locked`, `database_restore_busy`,
`database_exchange_sessions` and `database_dump_locked` are retryable (§4.4): a job tries
again at most five times, 30 seconds after the first failure and twice as long after each
further one, not in a cleanup step and not once it is asked to stop. Its error is recorded
only when it stops, so the hints of these codes say that the job has stopped and does not
try again by itself; a request, such as a read of snapshots or a connection test, has the
hints of `Messages::snapshot_error()`, which say to try again. A cancelled job
ends in the state `cancelled` without an error code, and there is no `timeout` code: a tick
that runs out of time simply continues in the next one. Codes for restore
(`database_import_failed`, `restore_validation_failed`) arrive with Milestone 3.

The REST API has codes of its own for requests it refuses: `not_found` (unknown job),
`not_an_undo` and `undo_not_ended` (the kept entries of a job that is no undo, and of an
undo that has not ended),
`not_editable` (a setting that `PUT /settings` does not accept), `invalid_job_type`,
`invalid_job_id`, `restore_not_found`, `restore_active`, `restore_in_use` and
`restore_kept_for_undo` (`DELETE /restores/{id}` with a name that is no job ID, for a job
without staging, for a restore that has not ended, for a staging that a file job at work
uses, and for one that the undo of an apply needs until its recovery window ends, a time
that the answer names), `live_plan_unavailable`, `live_plan_conflict`, `live_plan_changed`
and `live_plan_failed` (a file preview or confirmation that cannot be made: for this source,
in the current state, after a change since the review, or because a private record could
not be read or written; the file routes also pass on `file_space`, `file_writes_refused`,
`undo_nothing_to_revert`, `undo_unavailable`, `undo_records_unreadable`, `file_preview_expired`, `job_unreadable`,
`job_inaccessible`, `protected_path_invalid` and `file_staging_gone`),
`invalid_certificate`, `recovery_kit_not_acknowledged`, `wrong_user_password` (the recovery
kit, a live change or a download of restored content was asked for without the right
WordPress password), `user_password_throttled` (HTTP 429: too many wrong WordPress passwords
of this user came before, and the password is not checked until the wait is over),
`download_not_found`, `password_required` (a request
names a server or user name that the stored REST password is not for and carries no
password), `password_too_short` (a chosen repository password of fewer than 12 characters),
`ca_certificate_missing` (the settings name an uploaded CA certificate that is not there, so
a recovery kit would be incomplete), `environment_blocked` (the setup cannot be completed
while the Doctor has a critical finding), `invalid_settings` (with one message per field) and
`invalid_url` for the connection test, which also covers a server without ranged reads
(`server_incompatible`). The snapshot routes and the `ls` command add `invalid_snapshot_id`,
`invalid_snapshot_path`, `invalid_snapshot_query`, `snapshot_not_found` and
`snapshot_path_not_found` (§13.2); `Restore\SnapshotBrowser::failure()` assigns them and
their HTTP status. The messages of `invalid_settings` come from the validation codes
of the settings (`Settings::with()`), among them `retention_floor`: `retention.keep.within`
must be at least one day, or `retention.keep.last` at least 1. The rule is checked when one
of the two fields changes.

Logs per job: `events.log`, one JSON line per event with time, level, message and context,
redacted and capped at 1 MiB; once it is full a last line says so and later entries are
dropped (`Jobs\EventLog`). Requests to the repository are not logged one by one: there is no
`http.log`, and none is planned yet. Nothing rotates logs. Every job's `cleaning_up` step
deletes the oldest finished jobs with their logs and keeps `advanced.keep_jobs` (default 30).

Progress is a set of counters, not a share of a total. A backup reports the files and bytes
processed so far, new, changed and unmodified files and directories, the blobs and bytes
added, the current directory and the number of warnings; unlocking reports a percentage;
check and prune report their phase and counts. The plugin does not know the size of the site
in advance. From the second backup on it measures against the last one: the progress
carries `expected_bytes`, the bytes that the last backup of this repository went through,
and the admin page shows a bar and the minutes left at the speed so far. A site that grew
or shrank since then makes both wrong by that much; the first backup has neither.

Notifications (`Notifications\Notifier`, FR-09) go by e-mail to the configured recipients, or
to the site's administration address, through the `backups_for_restic_notification_recipients`
filter:

| Notice | When | Setting |
| --- | --- | --- |
| Failure | the first failed backup, forget, prune or check job of a streak, per job type | `on_failure` |
| Stale | once, when no backup succeeded for `stale_after_hours` (default 36), but at least one and a half backup intervals; never with manual backups only | `on_stale` |
| Recovery | once, when that job type succeeds again after a failure or stale notice; a check that finishes with errors sends the check-findings notice instead | follows the notice it answers |
| Check findings | every check that finds errors | `on_failure` |
| Retention external | when retention switches to `external`: the probe of a forget or prune job or of the heartbeat found the server append-only, or the server refused a deletion (ADR 0010) | `on_failure` |
| Clock | once, when the heartbeat finds a handled schedule slot or the time the stale notice counts from more than an hour after the current time: the server clock was ahead and has been set back. The slot is put back to the current slot of its schedule, and the age of the backups counts from the moment this was found, until a newer backup replaces the record that lay ahead ([ADR 0011](adr/0011-scheduling.md)) | `on_failure` |

An e-mail is built from codes, times and counts only, never from log lines, paths or
settings, so it cannot carry a secret. Notices wait in an outbox (`backups_for_restic_notifications`)
until `wp_mail()` accepts them. A notice that is refused is tried again after 15 minutes,
1 hour, 6 hours and 24 hours, each time at the first finished job or heartbeat after the
pause: five attempts in all. It is dropped after the fifth refusal, or when it is two days
old. The actions of §13.5 fire on the same state changes, after the job document is saved.

## 15. Multisite and unusual layouts

**Multisite is not supported in 1.0** ([ADR 0026](adr/0026-multisite-deferred.md)). The
activation hook refuses network-wide and per-site activation, and a runtime guard keeps the
plugin inert with a notice if it was activated by other means. Per-site activation is
refused on purpose: a site administrator must never back up or restore the whole network.
The 1.1 design uses network-only activation, site options, `manage_network_options` and
scheduling on the main site.

Roots are computed, not assumed; nested roots collapse; the private directory is excluded except the virtual
`spool/` paths. Bedrock-style layouts work because the snapshot stores absolute paths and
the manifest records the roots.

## 16. Performance and resource budget

Measured in Milestone 0 with `bin/bench.php` and the conformance suite on a 2.1 GHz cloud
vCPU, PHP 8.3 without OPcache or JIT as the baseline
([docs/benchmarks/2026-11-milestone-0.md](benchmarks/2026-11-milestone-0.md)). The gates are
release criteria.

| Operation | Measured | Gate |
| --- | --- | --- |
| Rabin chunker | 24 MB/s; 124 MB/s with JIT | ≥ 5 MB/s, passed; the fallback rule that [ADR 0019](adr/0019-repository-format-and-chunker.md) reserved for a slower chunker (chunk only files > 8 MiB and the database stream) was therefore never built |
| SHA-256 | 261 MB/s | — |
| Poly1305, pure PHP | 62 MB/s; 143 MB/s with JIT | — |
| Seal: AES-256-CTR plus Poly1305 | 52 MB/s | — |
| scrypt `N = 16384, r = 8, p = 1` (keys the plugin creates) | 1.4 s | ≤ 30 s, passed |
| scrypt `N = 32768, r = 8, p = 6` (a key restic calibrated) | 17.9 s in one go with the table in memory (`Scrypt::derive()`); the file-backed, resumable path that unlocking uses is not timed | UI shows progress |
| Backup of new data | 12 MB/s; 1.3 CPU minutes per GB, extrapolated from 256 MB backed up in one process into an in-memory repository (user CPU time, no network, no resume) | ≤ 15 CPU minutes per GB, passed for that measurement |
| Peak memory, 300 MB database stream | 38 MB for the whole test process | < 64 MB above the baseline, asserted by the conformance suite; tests run with `memory_limit = 128M`. The stream adds few blobs, so the index hardly counts here (§2, principle 3) |

Derived from those numbers, not yet measured end to end in WordPress:

| Operation | Estimate |
| --- | --- |
| One 20-s tick | up to about 250 MB of new data chunked and encrypted; the upload usually bounds it lower |
| First backup of 5 GB | about 7 CPU minutes; wall time is set by upload bandwidth and tick cadence: under an hour with loopback chaining, around two hours with a 5-minute cron |
| Daily backup, 200 MB changed | 1–3 ticks |

`cpu_throttle: gentle` halves `advanced.tick_budget_seconds` before the cap of §4.2 is
applied, so a setting above the cap is not shortened by it, and rests 0.1 s after every
0.25 s of work in backups (also between the chunks of one file), forget, prune and check
(`Support\Throttle`), for hosts with CPU-second quotas. The rests come between units of
work, so it is a pacing, not a hard limit: a tick keeps a core busy about 70 % of the time
when the units are short. A large pack in a check is read in pieces of 8 MiB with a rest
between them; a unit that cannot be split, such as one chunk of up to 8 MiB or one request
to the server, runs without a rest. The WP-CLI `--wait` ticks of 300 s are not halved but
rest in the same way. The one-time key derivation is not throttled. Uploads are sequential
in 1.0; parallel uploads via the bundled Requests library's `request_multiple` are a backlog
item.

## 17. Internationalisation and accessibility

Text domain `backups-for-restic`, English source strings, translations as language
packs from translate.wordpress.org. The PHP texts, all e-mails included, and the texts of
`assets/js/admin.js` pass through the gettext functions, and `wp_set_script_translations()`
loads the script's translations. Three things are shown in English as the core produces
them: the messages and context of the job log, the job warnings, and the byte units. The
repository holds no POT file, and the package ships none: translate.wordpress.org reads
the strings from the released code. CI builds a template from the package with
`wp i18n make-pot` on every pull request, to show that the strings of the PHP files and of
the script can be extracted, and ESLint checks the translation calls of the script
([development guide](04-development-and-quality.md) §3 and §5.8).

The admin page uses core admin classes for buttons, notices, tabs and tables plus a small
scoped stylesheet, labels on every field, an `aria-live` region for the views and their
progress, and ordinary links, buttons and inputs that work from the keyboard. Times on the
admin page are formatted by the browser; e-mails use the site's time zone and date format,
and schedules are slots in the site's time zone.

## 18. Versioning, upgrades and uninstall

- Semantic versioning. What carries a version today: the dump format id
  (`backups-for-restic-dump/1`), the site manifest (`backups-for-restic-site/1`), the engine
  checkpoints (`STATE_VERSION`, a checkpoint of another version is refused), the stored
  secrets (`v1.` prefix), the cached index (its magic line), the options and `job.json`.
- The options carry one layout number for settings, state and notifications in
  `backups_for_restic_schema_version` (`Storage\SchemaVersion`, 1 today), and every `job.json`
  carries its own in the field `schema` (`Jobs\Job::SCHEMA`, 3 since database exchange jobs began recording retained generations, with layouts 1 and 2 still readable). A number is raised with
  every change that a version without the change would misread. Options without a number
  and job documents without the field have the first layout; the plugin records the number
  on its first request. This first recording is an atomic insert that never changes an
  existing row or its autoload flag. After an insertion race the stored value is read
  again, including invalidation of WordPress' autoloaded and missing-option caches; only
  the supported number allows startup. If the database refuses that write, the plugin
  stays inert and shows administrators a notice. No conversion exists yet, because no
  layout has changed. Initialization does not serialize a future migration against
  requests already running under an older version; such a migration must stop those
  requests before changing the layout.
- A version that finds a higher number than its own was installed over a newer one. It
  stays inert: it registers no REST route, no cron callback, no admin page and no command,
  and it shows administrators a notice that asks them to update the plugin. It could misread
  the settings, and retention deletes snapshots by what it reads. Deactivation unschedules
  events but leaves jobs and their repository locks alone when the options have a newer
  or malformed layout number. A missing option is the only legacy case: zero, negative,
  empty and non-numeric values are unknown layouts, preserved without starting the plugin.
  So is a row whose value decodes to null, which `get_option()` cannot tell from a missing
  option: `Options::exists()` asks with a default that no stored value can be.
  `WpOptions::initialize()` uses a prepared insert with a no-op duplicate-key clause,
  because WordPress' `add_option()` can update an existing row after a stale existence
  check. This operation does not use the `add_option()` or `update_option()` hooks; normal
  option writes still do. A job document with a higher number, or with one that is not a
  number, is not loaded. Any existing job document that cannot be read blocks `create()`
  and `while_idle()` under the creation lock; its raw state is not trusted, and pruning job
  history leaves it alone. The refusal names the job (`job` in its context and in the REST
  error data) and says why: `job_unreadable` for a regular file in the job's own directory
  that this version reads but cannot parse, `job_inaccessible` for a document that the PHP
  user cannot look up or read, below a job directory that it may not search or as a file
  that it may not read, or for a link in the place of the job's directory or anything other
  than a regular file in the place of its document (`JobStore::unreadable_code()`). The first needs a
  version that can read the job; the second needs ownership and permissions put right, for
  example after a WP-CLI command that ran as root created the job's directory, which
  `reserve_id()` makes with mode 0700. A directory without a job document does not block
  changes.
  Unknown or invalid stored settings of the same layout fall back to their defaults field
  by field.
- Deactivation unschedules the two cron events (`Cron::unschedule()`) and ends the active
  job (`Cron::stop()`, `Runner::stop()`): it asks the job to cancel and runs its cleanup
  steps, which releases the repository lock. The budget of ten seconds holds back regular
  steps only; a cleanup step is not cut short by it and ends with its own requests' time
  limits. A job that another
  tick is working on at that moment is cancelled by that tick after its current step.
  Deactivation never fails over this. If the cleanup could not finish, for example because
  the server did not answer, the job stays in `jobs/` and is cleaned up by the first tick
  after reactivation, and its lock is stale for every restic client after 30 minutes. All
  data is kept. That includes the staged files of finished restores: without the heartbeat
  nothing expires, so they stay in `restores/` until the plugin is activated again, whose
  first heartbeat removes what is due, or uninstalled ([ADR 0040](adr/0040-restore-staging-expiry.md)).
- Uninstall (`uninstall.php`) deletes the plugin's options, its three transients, both cron
  events and what the plugin put into the private directory; it touches known names only, so
  a directory that also holds other files keeps them. The directory is found as at run time:
  the value of the constant `BACKUPS_FOR_RESTIC_PRIVATE_DIR`, which the filter
  `backups_for_restic_private_dir` may replace, and without either the default location.
  The location that applies without the filter is cleaned as well, in case the filter was
  added after the plugin had worked there. The plugin asks for no confirmation of its own.
  The repository on the server is untouched. On a PHP older than 8.3 the main file keeps
  the plugin inert and the uninstall cannot run its cleanup; it refuses with `wp_die()` and
  asks to switch back to PHP 8.3 or later first, because WordPress would otherwise delete
  the plugin and leave staged files, retained database tables and the encrypted secrets
  behind with nothing to expire them. `uninstall.php` therefore uses no syntax newer than
  PHP 7.2, the oldest PHP that WordPress 6.7 runs on.
- Plugin headers and `readme.txt` (`Requires at least: 6.7`, `Requires PHP: 8.3`,
  `Tested up to`, `Stable tag`) are WordPress.org metadata kept in sync by the release
  checklist.
