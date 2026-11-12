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
   holds the merged index of the whole repository in one PHP array, about 100 bytes per blob
   (`Format\Index\IndexSet`). By that figure the 64 MB of NFR-03 are reached at roughly
   640 000 blobs; this is derived, not measured. The plugin does not limit or estimate it
   yet: measurements with large blob counts and a bound or a preflight estimate are planned
   for Milestone 3.
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
│   ├── JobEvents.php              the plugin's actions on job state changes; hands finished jobs to the notifier
│   ├── WpMailer.php               notification e-mails: translated plain text through wp_mail()
├── Support/                       GoJson (Go-compatible JSON), GoQuote, Rfc3339, Bytes (strict base64, base64url, integers), Decoded, RawJson, ByteSource (+ Stream, String, Resumable), Clock (+ SystemClock), Redactor, Throttle (gentle CPU mode)
├── Exception/                     PluginException and one subclass per failure the UI explains
├── Settings/                      Schema (every field once), Field, Settings (immutable), SettingsStore (option + wp-config.php overrides)
├── Secrets/                       Secret (value hidden in a closure), SecretName, SecretStore, EncryptedOptionStore (HKDF over the salts + secretbox), ConstantOverrideStore
├── Storage/                       Options port, PrivateDirectory, SiteState, Files, CacheDirectory, ExposureCheck port
├── Crypto/                        restic primitives
│   ├── Cipher.php                 seal()/open(): nonce ‖ AES-256-CTR ‖ Poly1305-AES tag
│   ├── Poly1305.php               pure-PHP Poly1305 (ADR 0027)
│   ├── MasterKey.php              value object: encrypt key (32), mac k (16), mac r (16)
│   ├── Scrypt.php                 pure-PHP scrypt: PBKDF2 (native) + ROMix/Salsa20/8, file-backed V, resumable; ScryptParameters
│   └── RandomSource.php           interface for random bytes; SystemRandom in production, a fixed source in tests
├── Format/                        restic on-disk/wire structures
│   ├── Config.php, KeyFile.php, Id.php, BlobType.php, Snapshot.php, SnapshotSummary.php, Lock.php
│   ├── Pack/                      PackWriter, PackReader, PackEntry, FinishedPack
│   ├── Index/                     IndexFile (JSON ⇄ entries), IndexSet (lookup, compact binary export), BlobLocation
│   ├── Tree/                      Node, NodeType, FileMode, TreeCodec (ordering, Go quoting, mode bits)
│   ├── Unpacked.php               non-pack files: plain JSON, or zstd through Compression.php when `ext-zstd` is present
│   └── Chunker/                   Polynomial (GF(2) ops, irreducibility test), RabinChunker (restic-identical), ChunkReader
├── Backend/                       Backend interface, ObjectType, RestBackend (restic REST over an HttpClient), AppendOnlyProbe, AppendOnlyStatus, Http/ (HttpClient port, HttpResponse)
├── Connection/                    ServerProfile (URL rules), ConnectionTester, ConnectionReport, RepositoryAccess (backend + master key cache), RepositoryRecord (which repository the jobs open), Setup (wizard: save, create, connect)
├── Engine/                        the restic logic
│   ├── Repository.php, BlobStore.php, Locking.php, Identity.php
│   ├── KeyUnlock.php              resumable unlock across key files
│   ├── IndexCache.php             merged index cached locally, encrypted; fetches only new index files
│   ├── Backup/                    BackupPlan, BackupRun (resumable, also inside virtual files), NodeFactory, VirtualFile
│   ├── Restore/                   RestoreRun (resumable, refuses paths that leave the target)
│   ├── Forget/                    Forget, RetentionPolicy, Duration: restic's ApplyPolicy semantics; ForgetRun: the resumable run
│   ├── Prune/                     PruneRun: prune-lite, resumable, under an exclusive lock
│   └── Check/                     CheckRun: structural check plus a sampled share of packs read
├── Jobs/                          Job, JobState, JobType, JobError (stable codes), JobStore, EventLog, RunnerLock, Runner, JobObserver, Step, ClosureStep, StepProvider, JobSteps, TickReport
│   ├── Backup/                    BackupSteps (preflight, unlocking, backing_up, recording, releasing, cleaning_up), BackupServices
│   └── Maintenance/               MaintenanceSteps (detecting, forgetting, pruning, checking, recording), MaintenanceServices, RetentionGuard (ADR 0010)
├── Backup/                        PlanFactory (roots, excludes, spool/ with site manifest and dump), SitePaths
├── Database/Dump/                 Connection port, Catalog, Table, SqlFormatter, TableSource (resumable per page), DatabaseDump
├── Doctor/                        Environment, Check, Doctor (pure checks; texts in WordPress\Messages)
├── Scheduling/                    SchedulePolicy (slots in the site's time zone)
├── Restore/                       (M3) Staging, Selection, Apply, database Importer
└── Notifications/                 Notifier (first failure, stale once, recovery once; outbox with retries), Notice, NoticeKind, Mailer port
```

The entry marked (M3) arrives with that milestone. Dependency rules:

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

A **job** (`Jobs\JobType`: backup, unlock, forget, prune or check; restore joins in
Milestone 3) is a JSON document in the private directory
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
| **Heartbeat** | Recurring `backups_for_restic_heartbeat` every 5 minutes (`Cron::heartbeat()`). Once setup is completed it probes for append-only mode once a day (hourly while the last answer was inconclusive, and at once when the repository of the settings has no probe result yet, for example after another server was saved) and creates the one job that is due: a scheduled backup first, then forget after a successful backup, then prune and check. If a job is active it then runs one tick, which takes up a job whose chain broke once its lease has expired or its back-off has passed. Last it lets the notifier send the stale notice when due and retry unsent ones. The probe and the notifier run under the runner lock and wait for the next heartbeat while a tick holds it. The heartbeat has no duties of its own for leases, repository locks or logs: leases and locks are handled inside the tick, and nothing rotates logs (§14). | Needs any of the above to fire |
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

```
 queued → running: regular steps → cleanup steps → succeeded
 a step fails ──▶ cleanup steps → failed          cancel ──▶ cleanup steps → cancelled
```

- The cleanup steps run on every path to the end: success, failure and cancellation.
- `unlocking` is the KDF step; it does nothing when a cached master key opens the repository
  ([ADR 0020](adr/0020-unlocking-and-kdf.md)). The `unlock` job exists for the wizard when it
  connects an existing repository.
- No step of its own takes the repository lock, scans, uploads or writes the snapshot: the
  engine run inside `backing_up`, `forgetting`, `pruning` or `checking` does all of that and
  keeps its own checkpoint (§4.5). `releasing` releases the lock of a run that did not
  finish, which rest-server allows even in append-only mode.
- `detecting` is the append-only probe of forget and prune jobs
  ([ADR 0010](adr/0010-retention-and-append-only.md)). Forget is a job of its own, started
  by the heartbeat after a successful backup, not a step of the backup job.
- An error with a retryable code (`repository_unreachable`, `repository_locked`) does not
  fail the job at once: the runner waits 30 s, then twice as long after each further failure
  in a row, and fails the job at the sixth.
- Restore jobs join in Milestone 3 with their own step list.
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
the work since its last checkpoint. Memory per tick is bounded by the directory listings on
the walk, one page of rows and one pack buffer per blob type, plus the merged index, which
grows with the number of blobs in the repository (§2, principle 3).

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
indirectly: when the plugin backs up files that restic 0.19.1 or 0.14.0 backed up before, it
adds no data blob.

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
A backup does not write an index file in every tick. At the end of a tick it writes one when
50 000 blobs of uploaded packs wait for it or when 600 seconds have passed since the last
index file, and it writes the rest when it finishes (`BackupRun::INDEX_INTERVAL_SECONDS`,
`IndexFile::MAX_BLOBS`); like restic it starts a new file after 50 000 blobs. Until then
an uploaded pack is recorded only in the job's checkpoint. Reading merges all index files into an
`IndexSet`; a per-repository **index cache** in the private directory stores the merged set
(encrypted with the master key) so later runs load one file instead of all index files.
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
refreshes the lock of a job between its ticks. A backup, check or forget that finds its
lock older than 30 minutes, or gone, takes it again (`Locking::acquire()`), which fails
with `repository_locked` while another client holds a conflicting lock. What another
client did during such a pause is not examined: a `restic prune` that ran entirely within
it can have removed data the paused backup refers to, which the next check reports;
verifying this before a snapshot is written is planned for Milestone 3. Prune is stricter:
if its lock was lost or not refreshed for 25 minutes it starts its marking again, because
another client may have written snapshots in the meantime
([ADR 0022](adr/0022-maintenance-scope.md)). Every lock carries the number of the run that
wrote it, so a lock left by a request that died is recognised and removed by that run. Only
a request that dies between taking the lock and writing the first checkpoint of a backup,
check or forget leaves a lock nothing recognises; it is stale after 30 minutes. Locks are
deletable even in append-only mode.

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
3. **Backing up**: the engine's `BackupRun`, resumed every tick from its checkpoint, with the
   index from the local cache. On its first tick it removes this site's stale locks, takes a
   shared lock and picks the parent: the newest snapshot of the same host with the same
   paths. It walks the roots from `Backup\PlanFactory` (distinct, non-nested set of
   `ABSPATH`, `WP_CONTENT_DIR`, the uploads directory and extra roots; excludes from the
   defaults, the settings and the `backups_for_restic_backup_excludes` filter; `CACHEDIR.TAG`
   and `.nobackup` markers). A file whose size, mtime, ctime and inode match the parent
   reuses its blobs; others are chunked, deduplicated, encrypted and packed. Inside the
   private directory only `spool/` is walked: `spool/manifest.json` and the database files
   of §7 are virtual. Directories become tree blobs bottom-up, then the snapshot is written
   with `summary` and tags `backups-for-restic`, `site:<hash>` and `run:<job>`. The lock is
   refreshed every five minutes and released at the end.
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
<private>/spool/database/manifest.json           format id, server version, time zone, prefix, tables in order with file, rows and bytes, views
<private>/spool/manifest.json                    site manifest (format id, home URL, paths, roots, excludes, prefix, plugin, WordPress and PHP versions, repository format)
```

Neither manifest carries hashes of files: restic authenticates every byte it restores, and a
running SHA-256 cannot be carried across ticks ([ADR 0021](adr/0021-database-streaming.md)).

Format `backups-for-restic-dump/1`: one SQL statement per line, escaped values, hex-encoded
binary, ≈ 1 MiB `INSERT` batches, and a trailer line `-- end table rows=<n> bytes=<n>` per
file ([ADR 0021](adr/0021-database-streaming.md)). The importer never needs an SQL parser.

Streaming and resumability: a table is produced page by page and fed to the chunker like a
file; chunk boundaries are content-defined, so unchanged parts of a table deduplicate against
yesterday's dump. When a tick's time is up, the table stops after the current page and the
next tick continues after it, so tables of any size finish. Pages follow an integer primary
key, otherwise an offset in primary-key order. Consistency is per page, as with a
non-transactional `mysqldump`: every row comes from exactly one read, but the pages of a
table and the tables themselves are read at different moments. That holds in every mode,
WP-CLI `--wait` included; the plugin opens no transaction. A consistent InnoDB snapshot
remains a later option ([ADR 0021](adr/0021-database-streaming.md)).

Import in the plugin arrives with restore in Milestone 3: files are restored to
`<private>/restores/<job>/database/`, each trailer verified, then executed statement by
statement in time-budgeted batches with maintenance mode on and the plugin's own options
preserved (`PluginStatePreserver`). The manual path with the restic
CLI exists today: `restic dump` of the directory as a tar, then `cat *.sql | mariadb …` in
file order ([`06-disaster-recovery.md`](06-disaster-recovery.md)).

## 8. Retention, integrity checks and maintenance

- **Forget** implements restic's `ApplyPolicy` exactly: snapshots newest first; buckets
  `last`, `hourly` (year-month-day-hour), `daily`, `weekly` (ISO year-week), `monthly`,
  `yearly`, each keeping the first snapshot of a new bucket value while counts remain and
  always the oldest snapshot while a bucket has counts left; `--keep-within` relative to the
  newest snapshot that is not in the future, so that one snapshot from a wrong clock does
  not push the others out (restic's `findLatestTimestamp`); `--keep-tag`. The forget job
  selects the snapshots with this site's host name and the tags `backups-for-restic` and
  `site:<hash>` and applies the policy to them as one group (`--group-by host`); the tag of
  the job that wrote a snapshot never splits them. A preview of what the policy would remove
  (a dry run) does not exist yet; it is planned with the snapshot browser of Milestone 3.
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
  That holds while the lock is continuous. If the lock was lost, or not refreshed for 25
  minutes, while prune was deleting, marking starts over, and the packs whose index entries
  were already removed are no longer found by any plan: they stay on the server as packs
  that no index lists. The check reports such packs as a warning, a full `restic prune`
  removes them, and so will prune itself once it deletes unlisted packs (Milestone 3).
- **Check**: structural check (every indexed pack exists with the recorded size, every
  snapshot's tree walk resolves to indexed blobs, every index file can be read). A blob id
  that occurs twice is no finding: duplicates are legal in a restic repository, and the
  index keeps the first location; a whole pack listed in two index files is the warning
  `pack_in_several_indexes`. A pack that no index file lists, as a cancelled or failed
  backup leaves it behind, is the warning `pack_unindexed`; prune-lite does not delete such
  packs yet, which is planned for Milestone 3. Then comes **sampled data verification**:
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

Restore in the plugin arrives with Milestone 3; this section is its plan. Today the engine
has `Engine\Restore\RestoreRun`, which restores a snapshot or a part of it into a directory
in resumable slices and validates every name from the repository. Only the unit and
conformance suites use it: the admin area, the REST API and WP-CLI have no restore yet, and
until then a restore is done with the recovery kit and the restic CLI
([`06-disaster-recovery.md`](06-disaster-recovery.md)).

Safety model in [ADR 0013](adr/0013-restore-safety.md). Restores will be jobs with progress.

| Scenario | Flow |
| --- | --- |
| **Single file** | tree walk → blob ids → ranged `GET`s (adjacent blobs of one pack merged into one request) → open → verify → write to `<private>/restores/<job>/`; served through a one-time token; deleted after download or 1 h |
| **Selected paths to staging** | same per file, directories recursively, metadata applied where the PHP user may |
| **Apply to live site** | guarded step: dry-run report, `realpath` containment, symlinks refused, plugin directory and `wp-config.php` skipped unless chosen, maintenance mode, rename-or-copy |
| **Database** | `database/` files to staging → trailers verified → import (§7) after a typed confirmation |
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
├── cache/<repo-id>/index.bin              merged index, encrypted with the master key
├── kdf/<job-id>/                          scrypt table and checkpoint, 0600, deleted when the job ends
├── jobs/create.lock                       flock target while a job is created, and while the setup changes the server or a password (§4.4)
├── jobs/<job-id>/                         job.json (atomic), events.log (redacted, capped at 1 MiB), cancel-requested (marker of a cancel request, §4.4), backup/ (backup checkpoint), database.json (dump state), maintenance/ (checkpoint of forget, prune or check)
├── spool/, spool/database/                empty directories the backup walks into; their content is virtual
├── tmp/, logs/                            reserved, empty
└── restores/<job-id>/                     (M3) staging, expires after 24 h; the directory exists empty today
```

No plaintext site data is written to the private directory except restore staging and
database import files, both explicit admin actions that arrive with Milestone 3. The
database holds four options:
`backups_for_restic_settings` (autoload yes, small), `backups_for_restic_secrets` (autoload no,
ciphertext including the cached master key), `backups_for_restic_state` (autoload no: the
last backup and the last forget, prune and check, the check alert, the exposure and
append-only probes, the retention switch and proposal, the handled schedule slots, the
repository that the first five belong to, the probe from the browser, the last twenty
deliveries of the recovery kit, and the number and size of the repository's packs as a
backup or a prune last listed them) and
`backups_for_restic_notifications` (autoload no: failure streaks, the stale marker, the
repository both are counted for, and the outbox of unsent notices; streaks and marker start
anew when the jobs turn to another repository). The last two are documents that several
requests change: a job's tick, the heartbeat and a request of the admin page. A change
therefore reads the document from the database, past what the request read before
(`Options::fresh()`), and holds `flock()` on `<private>/state.lock` until it is written
back (`Storage\FileLock`), so that no request stores a copy from before another one's
change. Notices are handed to the mailer by one request at a time (`<private>/notify.lock`,
not waited for); the state is not locked while a mail is on its way, and what is queued in
that time goes out with the next heartbeat. A fifth name, `backups_for_restic_schema_version`, is reserved:
nothing writes or reads it, and uninstall deletes it (§18).

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
with five tabs (`AdminPage::TABS`): Dashboard (cards for the last backup, the next backup and
the repository host with its append-only status, the running job with progress and a "keep this
tab open" hint, recent jobs, Back up now; maintenance cards for old snapshots, unused data
with the space only a full prune reclaims, and the repository check, each with its last
result, next run and a button; notices that stay until their cause is gone: check findings,
a switch to retention mode `external`, and the proposal to switch back), Snapshots (a list
with time, id, host, files, size and new data; browsing and restoring arrive with
Milestone 3), Settings (Schedule, Old snapshots, Maintenance, Notifications, What to back
up, Advanced, and Repository with the address, the working directory, the cache and the
recovery kit; saving sends only changed fields, so an open page cannot undo an automatic
switch), Environment (the Doctor's checks) and Logs (the recent jobs and, for a chosen job,
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
| `POST /environment/probe`, `POST /environment/probe/report` | the probe of the private directory from the administrator's browser (§13.4): a probe file's name and URL (URL null if the directory is outside the web root); then that name with the status and body the browser got, answered with `exposed` true, false, or null for a report that says nothing (not the pending probe, older than five minutes, or a failed request) |
| `GET /jobs`, `POST /jobs` | recent jobs; start a backup, or with `type` a forget, prune or check job. Refused with 400 `invalid_job_type`, or with 409 and, in this order, `setup_incomplete` until the setup is completed, `configuration` without a repository address, `retention_external` for forget and prune in that mode, `job_conflict` while another job is active |
| `GET /jobs/{id}` | one job; while it runs, the request advances it for up to 5 s (admin polling) |
| `POST /jobs/{id}/cancel`, `GET /jobs/{id}/log` | request to cancel, recorded as a marker file (§4.4); redacted events |
| `GET /snapshots` | snapshots of the repository, cached for 5 minutes |
| `GET /settings`, `PUT /settings` | settings; secrets only as set/overridden flags; PUT accepts schedule, retention, backup, notifications and advanced fields; GET also reports the cache size |
| `DELETE /cache` | clears the local cache (409 while a job runs) |
| `POST /repository/test`, `POST /repository/save` | connection test; test and save. For an own server both take an optional `ca_certificate` (PEM): the test uses it from a file of its own under `tmp/` in the private directory, removed after the test, `save` stores it as the server's certificate once the server is saved, and saving a server of the preset removes a stored one. A request without `password` uses the stored REST password, but only for the host, port and user name it was saved for; for another server or user name it is refused with 400 `password_required` before anything is sent. `save` changes nothing while a job is active (409 `job_conflict`). A save that fails half way, also because the options table refused a write without an error (`Options::set()` answers false, `SettingsStore::update()` and `SiteState::bind()` raise `StorageFailed`), tries to put back what it changed: the settings, the REST password, the cached key and the certificate. Each part is tried on its own; while the database keeps refusing writes, a part can stay as the failed save left it, and the request answers with the error either way. Where `wp-config.php` defines the address, the certificate of a tested address that is another one is not stored; saving another address empties `repository.id` and drops the results remembered for the old repository |
| `POST /repository/create`, `POST /repository/connect` | new repository, or the password of an existing one plus an unlock job. Both answer with the recovery kit: without further proof until the setup is completed, afterwards only if the request carries the administrator's WordPress password as `user_password`, else 403 `wrong_user_password`. `create` refuses a chosen repository password of fewer than 12 characters with 400 `password_too_short`; neither stores anything while a job is active (409 `job_conflict`). `create` stores the repository password before the repository exists and puts the earlier one back if the repository does not come to exist; `connect` keeps an entered password apart until the unlock job has opened the repository with it, and only then replaces the stored password and the cached key; a wrong password leaves both in place, and the kit in its answer names the entered one. `connect` without a password unlocks with the stored one. Both answer 409 `ca_certificate_missing` if the settings name an uploaded CA certificate that is not in the private directory |
| `POST`/`DELETE /repository/ca-certificate` | CA certificate of a self-hosted server |
| `POST /setup/complete` | finish the wizard after the recovery kit is acknowledged; 409 `environment_blocked` while the Doctor has a critical finding |
| `POST /recovery-kit` | the recovery kit, only with the administrator's WordPress password as `user_password` (403 `wrong_user_password`); 409 `ca_certificate_missing` as above |

All routes: capability check in `permission_callback` (`backups_for_restic_capability`,
`manage_options` by default); cookie + nonce or Application Passwords; no secrets in
responses except the recovery kit, which is sent with `Cache-Control: no-store`. Restore
routes arrive with Milestone 3.

The recovery kit is in the answer of three routes. During the setup `create` and `connect`
return it freely, which is how the wizard shows it once; from then on all three ask for the
WordPress password of the current user in every request (`wp_check_password`). The stored
REST password leaves the site only for the server it was saved for
(`Connection\Setup::rest_password()`): the same host, compared without regard to case, the
same port and the same user name. A request that tests or saves another server or user name
has to carry the password. A REST password defined in `wp-config.php` goes to another
server only if the request carries that same password, and saving a server without a user
name deletes a stored REST password.

`GET /snapshots` and the WP-CLI command `snapshots` list every snapshot in the repository,
also those of other hosts that share it. A list limited to this site is planned for
Milestone 3. Errors of the routes carry the codes of §14 with the prefix
`backups_for_restic_`.

### 13.3 WP-CLI (`wp backups-for-restic …`, optional surface)

Commands that exist (`WordPress\CliCommand`): `doctor`, `backup [--wait]`,
`forget [--wait]`, `prune [--wait]`, `check [--read-data-subset=<percent>] [--wait]`,
`tick [--until-idle]`, `status`, `snapshots`, `settings list|get|set`, `cancel [<job>]` and
`logs [<job>] [--lines=<number>]`. `settings set` changes what the settings page can change
(`Settings\Schema::editable()`), with the same validation; `cancel` asks the active or the
named job to stop and runs one tick for its cleanup; `logs` prints a job's event log,
which is redacted when it is written. The four commands that start a job end
with an error under the same conditions as `POST /jobs` (§13.2): before the setup is
completed, without a repository address, and `forget` and `prune` in retention mode
`external`. `status` prints the last backup and the active job only.

Not built yet: `forget --dry-run` (a retention preview is planned for Milestone 3);
`ls <snapshot> [<path>]`, `restore …` and `apply …`, which arrive with restore in
Milestone 3. `unlock --yes`, which would remove stale locks of other hosts, stays on the
list without a milestone. There is no command for the setup or for the recovery kit: both
are in the admin area, where the kit asks for the WordPress password. Nothing requires
WP-CLI.

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
`exposed`; any other answer counts as protected. The site's own probe, where it gets an
answer, comes first. Two more checks are hints that block nothing: `jit`, when PHP's JIT
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
`backups_for_restic_recovery_kit_shown( $user_id, $via )` and, from Milestone 3,
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
| `repository_append_only` | the server refused a deletion |
| `repository_unreachable` | a network error or an unexpected status; retryable |
| `repository_missing`, `repository_exists` | HTTP 404; a `config` that is already there |
| `unauthorized` | HTTP 401, or 403 on anything but a deletion or an upload |
| `quota_exceeded`, `upload_rejected` | HTTP 507; HTTP 400 (hash mismatch) |
| `secrets_undecryptable` | the stored secrets do not decrypt, for example after a salt change; or a secret that was just stored does not read back unchanged (`EncryptedOptionStore::set()`) |
| `environment` | the private directory cannot be used, too little disk space or memory, an exposed private directory |
| `database_dump_failed` | a query of the dump failed |
| `configuration` | something a job or a request needs is not configured: no repository address, no repository password, no cached key, or a stored retention policy without `keep-within` or below the floor (`Schema::retention_floor()`): a forget job that finds one fails with this code and deletes nothing |
| `setup_incomplete` | a backup, forget, prune or check was asked for before the setup is completed, that is, before the recovery kit is acknowledged. It comes from `JobType::refusal()`, not from an exception: the job is not created |
| `job_conflict` | another job is active |
| `no_snapshots`, `retention_external` | prune found no snapshots; forget or prune asked for in retention mode `external` |
| `internal` | anything else |

Only `repository_unreachable` and `repository_locked` are retryable (§4.4). A cancelled job
ends in the state `cancelled` without an error code, and there is no `timeout` code: a tick
that runs out of time simply continues in the next one. Codes for restore
(`database_import_failed`, `restore_validation_failed`) arrive with Milestone 3.

The REST API has codes of its own for requests it refuses: `not_found` (unknown job),
`not_editable` (a setting that `PUT /settings` does not accept), `invalid_job_type`,
`invalid_certificate`, `recovery_kit_not_acknowledged`, `wrong_user_password` (the recovery
kit was asked for without the right WordPress password), `password_required` (a request
names a server or user name that the stored REST password is not for and carries no
password), `password_too_short` (a chosen repository password of fewer than 12 characters),
`ca_certificate_missing` (the settings name an uploaded CA certificate that is not there, so
a recovery kit would be incomplete), `environment_blocked` (the setup cannot be completed
while the Doctor has a critical finding), `invalid_settings` (with one message per field) and
`invalid_url` for the connection test, which also covers a server without ranged reads
(`server_incompatible`). The messages of `invalid_settings` come from the validation codes
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
  secrets (`v1.` prefix) and the cached index (its magic line). Settings and `job.json` carry
  no version: `backups_for_restic_schema_version` is reserved but never written or read, and
  a settings schema version with migrations is planned for Milestone 3, before 1.0. Unknown
  or invalid stored settings fall back to their defaults field by field.
- Deactivation unschedules the two cron events (`Cron::unschedule()`) and ends the active
  job (`Cron::stop()`, `Runner::stop()`): it asks the job to cancel and runs its cleanup
  steps, which releases the repository lock. The budget of ten seconds holds back regular
  steps only; a cleanup step is not cut short by it and ends with its own requests' time
  limits. A job that another
  tick is working on at that moment is cancelled by that tick after its current step.
  Deactivation never fails over this. If the cleanup could not finish, for example because
  the server did not answer, the job stays in `jobs/` and is cleaned up by the first tick
  after reactivation, and its lock is stale for every restic client after 30 minutes. All
  data is kept.
- Uninstall (`uninstall.php`) deletes the plugin's options, its three transients, both cron
  events and what the plugin put into the private directory; it touches known names only, so
  a directory that also holds other files keeps them. The directory is found as at run time:
  the value of the constant `BACKUPS_FOR_RESTIC_PRIVATE_DIR`, which the filter
  `backups_for_restic_private_dir` may replace, and without either the default location.
  The location that applies without the filter is cleaned as well, in case the filter was
  added after the plugin had worked there. The plugin asks for no confirmation of its own.
  The repository on the server is untouched.
- Plugin headers and `readme.txt` (`Requires at least: 6.7`, `Requires PHP: 8.3`,
  `Tested up to`, `Stable tag`) are WordPress.org metadata kept in sync by the release
  checklist.
