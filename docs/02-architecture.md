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
│                     BorgBase or self-hosted rest-server        staging · job state · logs        │
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
  ([ADR 0025](adr/0025-repository-servers.md)): **BorgBase**, which according to its public
  documentation runs nginx in front of restic's `rest-server` with per-repository
  credentials, an append-only option and stale-backup alerts, or a **self-hosted
  `rest-server`**. In append-only mode deletions of data, index, key and snapshot files are
  answered with HTTP 403 while lock files stay deletable. This is verified in the
  rest-server source; BorgBase's behaviour is verified by live smoke tests, not by vendor
  statements.
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
3. **Everything streams and everything is bounded.** Files are read in 1 MiB buffers, blobs
   are encrypted one at a time, packs are assembled in a temp file, uploads are one request
   each, index and tree sizes follow restic's own limits. Peak PHP memory is independent of
   site size.
4. **Ticks, not long processes.** All work happens in bounded slices (default 20 s) driven
   by WP-Cron, loopback chaining, admin polling or WP-CLI. Every slice ends with a durable
   checkpoint; a killed request loses at most one slice of work.
5. **A thin WordPress shell around a WordPress-free core.** `Engine`, `Format`, `Crypto`,
   `Jobs`, `Database\Dump`, `Retention`, `Support` call no WordPress functions and are unit
   tested without WordPress. `Admin`, `Rest`, `Cli`, `Scheduling`, `Http\WpHttpClient` are
   thin adapters.
6. **Secrets flow through one type** (`Secrets\Secret`, redacting, `reveal()` allow-listed).
7. **Fail closed.** Unverifiable preconditions (undecryptable secrets, web-readable private
   directory, MAC mismatch, unsupported repository version) stop the operation with a precise
   message.
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
├── Backend/                       Backend interface, RestBackend (restic REST over an HttpClient), AppendOnlyProbe, Http/ (HttpClient port, HttpResponse)
├── Connection/                    ServerProfile (URL rules), ConnectionTester, RepositoryAccess (backend + master key cache), Setup (wizard: save, create, connect)
├── Engine/                        the restic logic
│   ├── Repository.php, BlobStore.php, Locking.php, Identity.php
│   ├── KeyUnlock.php              resumable unlock across key files
│   ├── IndexCache.php             merged index cached locally, encrypted; fetches only new index files
│   ├── Backup/                    BackupPlan, BackupRun (resumable, also inside virtual files), NodeFactory, VirtualFile
│   ├── Restore/                   RestoreRun (resumable, refuses paths that leave the target)
│   ├── Forget/                    Forget, RetentionPolicy, Duration: restic's ApplyPolicy semantics
│   ├── Prune/                     PruneRun: prune-lite, resumable, under an exclusive lock
│   └── Check/                     CheckRun: structural check plus a sampled share of packs read
├── Jobs/                          Job, JobState, JobType, JobError (stable codes), JobStore, EventLog, RunnerLock, Runner, JobObserver, Step, StepProvider, TickReport
│   ├── Backup/                    BackupSteps (preflight, unlocking, backing_up, recording, releasing, cleaning_up), BackupServices
│   └── Maintenance/               MaintenanceSteps (detecting, forgetting, pruning, checking, recording), RetentionGuard (ADR 0010)
├── Backup/                        PlanFactory (roots, excludes, spool/ with site manifest and dump), SitePaths
├── Database/Dump/                 Connection port, Catalog, Table, SqlFormatter, TableSource (resumable per page), DatabaseDump
├── Doctor/                        Environment, Check, Doctor (pure checks; texts in WordPress\Messages)
├── Scheduling/                    SchedulePolicy (slots in the site's time zone)
├── Restore/                       (M3) Staging, Selection, Apply, database Importer
└── Notifications/                 Notifier (first failure, stale once, recovery once; outbox with retries), Notice, Mailer port
```

Entries marked (M2) or (M3) arrive with that milestone. Dependency rules:

- Only `WordPress\*` and `Plugin` call WordPress functions or use WordPress classes
  ([ADR 0029](adr/0029-wordpress-free-core.md)). The core receives paths, settings values,
  a `Clock` and the ports `Options`, `HttpClient`, `Connection` and `ExposureCheck` as
  constructor arguments. `tests/PHPStan/WordPressFreeCoreRule.php` enforces this in CI.
- `Engine` depends on `Format`, `Crypto`, `Backend`; never on `Jobs` or any adapter.
  `Jobs\Backup` orchestrates `Engine` operations in bounded slices.
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

A **job** (backup, forget, prune, check, restore) is a JSON document in the private
directory ([ADR 0014](adr/0014-file-based-job-store.md)). A **tick** loads the active job,
advances it for at most the budget, writes a checkpoint and arranges the next tick. The
budget is `min(advanced.tick_budget_seconds, max_execution_time − 10 s)`, default 20 s;
`set_time_limit()` is attempted but never relied upon. Every engine operation is written as a
**resumable step**: it can stop after any blob, any directory, any HTTP request, and continue
from the checkpoint in the next tick.

### 4.3 Who ticks

| Driver | Behaviour | Typical on shared hosting |
| --- | --- | --- |
| **Loopback chain** | At the end of a tick with remaining work the runner schedules `backups_for_restic_tick` for *now* and calls WordPress' `spawn_cron()`, which fires a non-blocking request to `wp-cron.php`. The next tick starts within a second. Chain stops when the job finishes. | Works on most hosts; some block loopback (Doctor detects it, like Site Health does) |
| **WP-Cron on traffic** | Due events run at the end of ordinary page loads. | Baseline; speed depends on visitors |
| **External cron ping** | cPanel/Plesk cron or an external cron service fetching `wp-cron.php?doing_wp_cron` every 1–5 minutes, with `DISABLE_WP_CRON` optionally set. No plugin-specific URL or token is involved. | Recommended; documented with screenshots |
| **Admin polling** | While the dashboard shows a running job it polls every 3 s; each poll performs a short tick (5 s budget, rate-limited to one per 5 s). "Keep this tab open to speed up the first backup." | Always available |
| **Heartbeat** | Recurring `backups_for_restic_heartbeat` every 5 minutes: probes for append-only mode once a day, creates due jobs from the schedule, detects expired leases, refreshes repository locks, rotates logs, sends the stale notification and retries unsent ones. | Needs any of the above to fire |
| **WP-CLI** (`wp backups-for-restic tick`, `backup --wait`) | Runs ticks in a loop until the job ends; `--wait` uses an unbounded budget. | Where the host offers WP-CLI |

Only one runner may advance a job at a time: `flock()` on `<private>/runner.lock`
(non-blocking) plus a **lease** in the job document (`owner`, `expires_at`). WordPress'
own `doing_cron` lock additionally serialises WP-Cron requests.

### 4.4 Job state machine

```
 queued → preflight → unlocking → locking → scanning → uploading → building_trees
       → writing_snapshot → recording → retention? → releasing → cleaning_up → succeeded
 any step ──error──▶ releasing → cleaning_up → failed          cancel ──▶ releasing → cleaning_up → cancelled
```

- `unlocking` is the KDF step; it is skipped when a cached master key exists
  ([ADR 0020](adr/0020-unlocking-and-kdf.md)).
- `locking`/`releasing` manage the repository lock; locks are always released on every
  terminal path, which rest-server allows even in append-only mode.
- `forget`, `prune`, `check` and `restore` jobs reuse the machine with their own step lists.
- Exactly one job is active at a time; creating another is rejected (HTTP 409), cancel is
  always possible.

### 4.5 Checkpoints

A backup job keeps its working state in `<private>/jobs/<id>/`: `job.json` with step,
progress and results; `backup/` with the engine's checkpoint (the stack of directory frames,
one file of finished nodes per frame, the file being read with its offset and blob ids, or a
virtual file with its cursor); and `database.json` with the dump's table list and finished
tables. Every write is atomic or append-only, so a killed tick loses at most the work since
its last checkpoint. Memory per tick is bounded by one directory listing, one page of rows
and one pack buffer.

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
checkpointing, then caches the master key ([ADR 0020](adr/0020-unlocking-and-kdf.md)).

### 5.4 Config and repository version

`config` is an encrypted JSON `{"version":1,"id":"<64 hex>","chunker_polynomial":"<hex>"}`.
Repositories **created by the plugin are format version 1** ([ADR 0019](adr/0019-repository-format-and-chunker.md)):
no compression anywhere, so every file the CLI or the plugin writes stays readable by both.
Connecting to an existing repository: version 1 is fully supported; version 2 is supported
only when the optional `zstd` PHP extension is present, because restic compresses index and
snapshot files in every version 2 repository, even with `--compression off`. Otherwise the
wizard refuses with a clear explanation. In a version 2 repository the plugin writes
uncompressed blobs and plain-JSON unpacked files, which restic accepts.

### 5.5 Chunking and blob ids

restic's content-defined chunker is implemented exactly: Rabin fingerprint over a 64-byte
window with the repository's irreducible polynomial of degree 53 (`polShift = 45`), `out`
and `mod` tables of 256 entries, minimum chunk 512 KiB, maximum 8 MiB, cut when
`digest & ((1 << 20) − 1) == 0`, the first `MinSize − 64` bytes of every chunk only fill the
window. Files smaller than 512 KiB are one blob. The blob id is the SHA-256 of the plaintext
blob; identical chunks are stored once. The repository polynomial is generated at init with
the same GF(2) arithmetic and Ben-Or irreducibility test restic uses. Conformance tests
compare chunk boundaries with those produced by `restic backup` on fixture files.

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
The plugin writes an index file after every pack batch and at the end of every tick, with at
most 50 000 blobs per file (restic's limit). Reading merges all index files into an
`IndexSet`; a per-repository **index cache** in the private directory stores the merged set
(encrypted with the master key) so later runs load one file instead of all index files.
Prune-lite rewrites index files without the deleted packs.

### 5.8 Trees and nodes

A tree blob is JSON `{"nodes":[…]}` with nodes **strictly ordered by name (bytewise) and
unique**; restic rejects unordered trees. Node fields: `name` (Go `strconv.Quote` escaping
without the surrounding quotes), `type` (`file`, `dir`, `symlink`; devices and sockets are
skipped with a warning), `mode` (permission bits plus Go `FileMode` type bits: dir `1<<31`,
symlink `1<<27`, setuid `1<<23`, setgid `1<<22`, sticky `1<<20`), `mtime`, `atime`, `ctime`
(RFC 3339 with the site's offset), `uid`, `gid`, `user`, `group`, `inode`, `size`, `links`,
`linktarget`, `content` (list of data blob ids for files, `null` for directories),
`subtree` (tree blob id for directories). JSON is emitted with Go-compatible escaping so that
identical directories produce identical tree blobs across implementations where possible;
parity is measured in tests but is not a correctness requirement.

### 5.9 Snapshots and locks

Snapshot: `{"time","parent","tree","paths","hostname","username","uid","gid","excludes","tags","program_version","summary":{…}}`
with `hostname` set to the site host, tags `backups-for-restic`, `site:<12 hex>`,
`run:<job-id>`, and `program_version` `backups-for-restic/<version>`.

Lock: `{"time","exclusive","hostname","username","pid","uid","gid"}`. Backup and check take
a non-exclusive lock; forget and prune take an exclusive lock (as restic does). A lock
older than 30 minutes is stale for every restic client, so the heartbeat and every tick
**refresh** the lock (write new, delete old) when it is older than 5 minutes. If no tick ran
for more than 30 minutes the job re-acquires its lock before continuing; prune instead starts
its marking again, because another client may have written snapshots in the meantime
([ADR 0022](adr/0022-maintenance-scope.md)). Locks are deletable even in append-only mode.

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
addressed flat (`/data/<name>`), the server shards. `{path}` is empty for BorgBase and
may contain user and repository segments on a self-hosted rest-server, for example with
`--private-repos`. The client sends HTTP basic
authentication in the `Authorization` header (never in the URL), verifies TLS against
WordPress' CA bundle or an uploaded CA certificate for a self-hosted server (never
disabled), disables redirects, sets a plugin User-Agent, times out after 60 s per
request, and retries idempotent requests on network errors and 5xx with exponential backoff
(at most 5 attempts). 507 (quota exceeded) and 403 on delete are surfaced as dedicated
errors ([ADR 0001](adr/0001-restic-rest-backend.md)).

## 6. Backup pipeline

Steps of a `backup` job (`Jobs\Backup\BackupSteps`):

1. **Preflight**: repository configured and password readable, private directory created
   and not reachable over HTTP (probe cached for a day), free disk space for two packs plus
   64 MiB, `memory_limit` of at least 96 MB, and the repository's `config` present.
2. **Unlocking**: open the repository with the cached master key, which counts only if it
   decrypts the config; otherwise derive it with the resumable scrypt across ticks and cache
   it (ADR 0020).
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
   its lock, the scrypt files and the checkpoint are deleted, old jobs are pruned.

Retention (`forget` after a backup in mode `plugin`) and notifications join in Milestone 2.
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
<private>/spool/database/manifest.json           table list in order, rows, bytes, sha256 per file, format id
<private>/spool/manifest.json                    site manifest (versions, roots, excludes, prefix)
```

Format `backups-for-restic-dump/1`: one SQL statement per line, escaped values, hex-encoded
binary, ≈ 1 MiB `INSERT` batches, and a trailer line `-- end table rows=<n> bytes=<n>` per
file ([ADR 0021](adr/0021-database-streaming.md)). The importer never needs an SQL parser.

Streaming and resumability: a table is produced page by page and fed to the chunker like a
file; chunk boundaries are content-defined, so unchanged parts of a table deduplicate against
yesterday's dump. When a tick's time is up, the table stops after the current page and the
next tick continues after it, so tables of any size finish. Pages follow an integer primary
key, otherwise an offset in primary-key order. Consistency is per table in tick mode; in WP-CLI `--wait`
mode the whole dump runs inside `START TRANSACTION WITH CONSISTENT SNAPSHOT` when all tables
are InnoDB.

Import: files are restored to `<private>/restores/<job>/database/`, each trailer verified,
then executed statement by statement in time-budgeted batches with maintenance mode on and
the plugin's own options preserved (`PluginStatePreserver`). The manual path with the restic
CLI is `restic dump` of the directory as a tar, then `cat *.sql | mariadb …` in file order
([`06-disaster-recovery.md`](06-disaster-recovery.md)).

## 8. Retention, integrity checks and maintenance

- **Forget** implements restic's `ApplyPolicy` exactly: snapshots newest first; buckets
  `last`, `hourly` (year-month-day-hour), `daily`, `weekly` (ISO year-week), `monthly`,
  `yearly`, each keeping the first snapshot of a new bucket value while counts remain and
  always the oldest snapshot while a bucket has counts left; `--keep-within` relative to the
  latest snapshot; `--keep-tag`. Scoped to the plugin's host and tags; a dry run is shown in
  the UI before the first automatic run. Deletion is `DELETE snapshots/<id>`; HTTP 403 →
  `repository_append_only` ([ADR 0010](adr/0010-retention-and-append-only.md)).
- **Append-only detection** ([ADR 0010](adr/0010-retention-and-append-only.md)): a `DELETE`
  of a random, non-existent snapshot name changes nothing on any server. rest-server answers
  403 in append-only mode before it touches the filesystem and 200 otherwise; other servers
  may answer 404. The wizard probes once, the heartbeat daily, and every forget or prune job
  before it starts. Detecting append-only switches retention to `external` immediately;
  detecting that deletions became possible only proposes the switch back to `plugin`, which
  needs an administrator's click.
- **Prune-lite** ([ADR 0022](adr/0022-maintenance-scope.md)): mark all blobs referenced by
  all snapshots (tree walk through the cached tree packs), delete packs with **no**
  referenced blob, rewrite index files without them (new index files first, then delete the
  old ones). Partially used packs are not repacked; the dashboard shows the space they
  waste, which only a full `restic prune` from any computer reclaims, and the
  disaster-recovery guide explains it. The estimate counts trees exactly and data blobs once
  through a Bloom filter of about two bytes per indexed blob, so it fits small memory limits
  and can only be slightly high; the conformance suite compares it with restic's count of
  unused bytes. Prune takes an exclusive lock and runs weekly by default.
- **Check**: structural check (every indexed pack exists with the recorded size, every
  snapshot's tree walk resolves to indexed blobs, no duplicate blob ids) plus **sampled
  data verification**: download `check.read_data_subset` percent of packs (default 2 %),
  verify header, blob tags and SHA-256 ids. Monthly by default; findings raise a persistent
  critical notice and are reported in Site Health. Repairs are never attempted
  automatically; the guide points to `restic repair`.
- **Cache hygiene**: the merged index is cached per repository, encrypted, and only while it
  fits `advanced.cache_limit_mib` (default 256 MiB); a larger index is read from the
  repository in every tick instead. Caches of other repositories are removed after each job.
  Settings and Site Health show the size, and the cache can be cleared while no job runs
  (`DELETE /cache`). Prune and check read trees on demand and keep no tree cache.
- **Locks**: stale locks (older than 30 minutes) from this site are removed automatically
  before a job starts, as restic does; foreign stale locks are reported and removable by an
  explicit admin action with a warning.

## 9. Restore

Safety model in [ADR 0013](adr/0013-restore-safety.md). Restores are jobs with progress.

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
├── ca.pem                                 uploaded CA certificate of a self-hosted server, if any
├── cache/<repo-id>/index.bin              merged index, encrypted with the master key
├── kdf/<job-id>/                          scrypt table and checkpoint, 0600, deleted when the job ends
├── jobs/<job-id>/                         job.json (atomic), events.log (redacted, capped), backup/ (engine checkpoint), database.json
├── spool/, spool/database/                empty directories the backup walks into; their content is virtual
├── tmp/, logs/                            reserved
└── restores/<job-id>/                     (M3) staging, expires after 24 h
```

No plaintext site data is written to the private directory except restore staging and
database import files, both explicit admin actions. The database holds four options:
`backups_for_restic_settings` (autoload yes, small), `backups_for_restic_secrets` (autoload no,
ciphertext including the cached master key), `backups_for_restic_state` (last backup, exposure
probe, schedule slot) and `backups_for_restic_schema_version`.

## 11. Settings model

Declared once in `Settings\Schema`; secrets live in the `SecretStore`.

```yaml
repository:
  server: borgbase                       # borgbase | rest-server (ADR 0025)
  url: "https://abc123.repo.borgbase.com/"   # https only; borgbase: *.repo.borgbase.com; rest-server: any host, port, path
  rest_username: "abc123"                # optional for rest-server without authentication
  ca_certificate: ""                     # rest-server only: uploaded PEM in the private directory, for self-signed TLS
  append_only: unknown                   # detected: yes | no | unknown (ADR 0010)
  format_version: 1                      # informational, read from config after connecting
schedule:
  backup: { frequency: daily, hour: 3, minute: 17 }
  prune:  { frequency: weekly, weekday: 0, hour: 4 }
  check:  { frequency: monthly, day: 1, hour: 5, read_data_subset_percent: 2 }
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
  tick_budget_seconds: 20                # capped by max_execution_time − 10
  pack_size_mib: 16                      # 4–64
  upload_timeout_seconds: 60
  cache_limit_mib: 256
  cpu_throttle: normal                   # normal | gentle (half the tick budget, short rests while working) for CPU-quota hosts
  loopback_chaining: auto                # auto | off
  keep_jobs: 30
setup:
  completed: false
  recovery_kit_acknowledged_at: null
```

Constants override values: `BACKUPS_FOR_RESTIC_REPOSITORY_URL`, `BACKUPS_FOR_RESTIC_REST_USERNAME`,
`BACKUPS_FOR_RESTIC_REST_PASSWORD`, `BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD`,
`BACKUPS_FOR_RESTIC_PRIVATE_DIR`. Overridden fields are read-only in the UI.

## 12. Core interfaces

```php
namespace BackupsForRestic\Backend;

interface Backend {
    public function exists(ObjectType $type, string $name): ?int;              // size or null
    public function load(ObjectType $type, string $name, ?Range $range = null): string;
    public function loadToFile(ObjectType $type, string $name, string $path): void;
    public function save(ObjectType $type, string $name, string|StreamSource $body): void;
    public function delete(ObjectType $type, string $name): void;              // throws AppendOnlyRefused on 403
    /** @return list<ObjectInfo> name + size */
    public function list(ObjectType $type): array;
    public function createLayout(): void;
}

namespace BackupsForRestic\Crypto;

final class Cipher {
    public function __construct(private MasterKey $key) {}
    public function seal(string $plaintext): string;                 // nonce ‖ ct ‖ tag
    public function open(string $ciphertext): string;                // throws AuthenticationFailed
}

interface Scrypt {
    /** Runs until done or until $budget is exhausted; returns null when it must be resumed. */
    public function derive(ScryptJob $job, Budget $budget): ?string;
}

namespace BackupsForRestic\Format\Chunker;

interface Chunker { /** @return iterable<Chunk> */ public function chunks(StreamSource $source): iterable; }

namespace BackupsForRestic\Engine;

interface BlobStore {
    public function has(BlobId $id, BlobType $type): bool;
    public function put(BlobType $type, string $plaintext, Budget $budget): BlobId;   // dedup, encrypt, pack, upload, index
    public function get(BlobId $id, BlobType $type): string;
    public function flush(Budget $budget): void;                                     // finish pack + index
}

interface ResumableStep {
    public function advance(Job $job, Context $context, Budget $budget): StepResult;  // Continue | Waiting | Done | Failed | Cancelled
}
```

Test doubles: `InMemoryBackend` (with append-only switch), `FakeHttpClient`,
`InMemorySecretStore`, `FrozenClock`, `ScriptedBudget`. The real `rest-server` and the
restic CLI are used in integration and conformance tests.

## 13. Surfaces

### 13.1 Admin UI

Menu "Backups" (`manage_options`; multisite is refused in 1.0). Pages: Dashboard
(freshness, repository usage from the index set, running job with progress and a "keep this
tab open" hint, recent jobs, Back up now; maintenance cards for old snapshots, unused data
with the space only a full prune reclaims, and the repository check, each with its last
result, next run and a button; notices that stay until their cause is gone: check findings,
a switch to retention mode `external`, and the proposal to switch back), Snapshots,
Restore, Settings (Repository, Schedule, Old snapshots, Maintenance, What to back up,
Notifications, Advanced; saving sends only changed fields, so an open page cannot undo an
automatic switch), Logs, Setup wizard
(Environment → Repository server → Password → Unlock progress → Recovery kit → Schedule →
First backup). The repository step offers BorgBase or an own rest-server, takes the
credentials and an optional CA certificate, tests Range reads and listing, and detects
append-only ([ADR 0010](adr/0010-retention-and-append-only.md)). Plain JavaScript as classic
scripts on core's `wp-api-fetch` and `wp-i18n`, server-rendered fallbacks
([ADR 0012](adr/0012-admin-ui-without-build-step.md)).

The wizard's environment page explains the hosting situation in plain words: loopback
status, cron recommendation, memory and time limits, expected duration of the first backup
for the measured site size.

### 13.2 REST API (`backups-for-restic/v1`)

| Route | Purpose |
| --- | --- |
| `GET /status` | dashboard: last backup, next slot, active and recent jobs, repository host, retention mode with switch and proposal, last and next maintenance runs, check alert with translated findings |
| `GET /environment?fresh=1` | Doctor results with translated texts |
| `GET /jobs`, `POST /jobs` | recent jobs; start a backup, or with `type` a forget, prune or check job (409 while another job runs, or for forget and prune in retention mode `external`) |
| `GET /jobs/{id}` | one job; while it runs, the request advances it for up to 5 s (admin polling) |
| `POST /jobs/{id}/cancel`, `GET /jobs/{id}/log` | cancel; redacted events |
| `GET /snapshots` | snapshots of the repository, cached for 5 minutes |
| `GET /settings`, `PUT /settings` | settings; secrets only as set/overridden flags; PUT accepts schedule, retention, backup, notifications and advanced fields; GET also reports the cache size |
| `DELETE /cache` | clears the local cache (409 while a job runs) |
| `POST /repository/test`, `POST /repository/save` | wizard: connection test; test and save |
| `POST /repository/create`, `POST /repository/connect` | wizard: new repository, or the password of an existing one plus an unlock job; both return the recovery kit once |
| `POST`/`DELETE /repository/ca-certificate` | CA certificate of a self-hosted server |
| `POST /setup/complete` | finish the wizard after the recovery kit is acknowledged |
| `POST /recovery-kit` | the recovery kit, after the administrator re-enters the WordPress password |

All routes: capability check in `permission_callback` (`backups_for_restic_capability`,
`manage_options` by default); cookie + nonce or Application Passwords; no secrets in
responses except the recovery kit, which is sent with `Cache-Control: no-store`. Restore
routes arrive with Milestone 3.

### 13.3 WP-CLI (`wp backups-for-restic …`, optional surface)

Milestone 1: `doctor`, `backup [--wait]`, `tick [--until-idle]`, `status`, `snapshots`.
Later: `jobs …`, `ls <snapshot> [<path>]`, `restore …`, `apply …`, `check`,
`forget [--dry-run]`, `prune`, `unlock --yes`, `recovery-kit`, `settings get|set`. Feature
parity with the UI where a terminal makes sense; nothing requires it.

### 13.4 Site Health

Tests: backup freshness; environment (OpenSSL, mbstring, memory, time limit, outbound
HTTPS, loopback/cron); private directory protection; secrets readable; last check
result. Debug information: plugin version, repository host and format version, cache size,
last jobs.

### 13.5 Hooks

Actions `backups_for_restic_job_state_changed( $job_id, $type, $state, $previous )`,
`backups_for_restic_backup_succeeded( $job_id, $snapshot_id )`,
`backups_for_restic_backup_failed( $job_id, $error_code )`, (M3)
`backups_for_restic_restore_applied`; filters `backups_for_restic_capability`,
`backups_for_restic_backup_roots`, `backups_for_restic_backup_excludes`,
`backups_for_restic_database_tables_excluded`,
`backups_for_restic_notification_recipients( $recipients, $kind )`,
`backups_for_restic_private_dir`. No hook exposes secrets or raw repository data.

## 14. Errors, logging and observability

Error codes (`Jobs\JobError`): `environment`, `configuration`, `repository_unreachable`,
`repository_missing`, `repository_unsupported_version`, `repository_compressed_unsupported`,
`repository_locked`, `repository_append_only`, `wrong_password`, `authentication_failed`
(MAC mismatch on an object → data corruption or tampering, never retried silently),
`quota_exceeded` (HTTP 507), `upload_rejected` (HTTP 400 hash mismatch), `server_incompatible` (no Range reads), `timeout`,
`cancelled`, `database_dump_failed`, `database_import_failed`, `restore_validation_failed`,
`internal`. Each has a stable code, a human message, a *retryable* flag and a hint.

Logs per job: `events.log` (steps, decisions, warnings), `http.log` (method, type, name,
status, duration — never headers or bodies), both redacted and capped. Progress:
`files_done/total`, `bytes_done/total`, current directory, packs uploaded, estimated
remaining time from the moving average of the last ticks.

Notifications (`Notifications\Notifier`, FR-09) go by e-mail to the configured recipients, or
to the site's administration address, through the `backups_for_restic_notification_recipients`
filter:

| Notice | When | Setting |
| --- | --- | --- |
| Failure | the first failed backup, forget, prune or check job of a streak, per job type | `on_failure` |
| Stale | once, when no backup succeeded for `stale_after_hours` (default 36), but at least one and a half backup intervals; never with manual backups only | `on_stale` |
| Recovery | once, when that job type succeeds again after a failure or stale notice | follows the notice it answers |
| Check findings | every check that finds errors | `on_failure` |
| Retention external | when a refused deletion switches retention to `external` (ADR 0010) | `on_failure` |

An e-mail is built from codes, times and counts only, never from log lines, paths or
settings, so it cannot carry a secret. Notices wait in an outbox (`backups_for_restic_notifications`)
until `wp_mail()` accepts them, at most five attempts in two days. The actions of §13.5 fire
on the same state changes, after the job document is saved.

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
| Rabin chunker | 24 MB/s; 124 MB/s with JIT | ≥ 5 MB/s, passed; the fallback rule (chunk only files > 8 MiB and the database stream) stays off |
| SHA-256 | 261 MB/s | — |
| Poly1305, pure PHP | 62 MB/s; 143 MB/s with JIT | — |
| Seal: AES-256-CTR plus Poly1305 | 52 MB/s | — |
| scrypt `N = 16384, r = 8, p = 1` (keys the plugin creates) | 1.4 s | ≤ 30 s, passed |
| scrypt `N = 32768, r = 8, p = 6` (a key restic calibrated) | 17.9 s, resumable and file-backed | UI shows progress |
| Backup of new data | 12 MB/s, 1.3 CPU minutes per GB | ≤ 15 CPU minutes per GB, passed |
| Peak memory, 300 MB database stream | 38 MB for the whole test process | < 64 MB above WordPress' baseline; tests run with `memory_limit = 128M` |

Derived from those numbers, not yet measured end to end in WordPress:

| Operation | Estimate |
| --- | --- |
| One 20-s tick | up to about 250 MB of new data chunked and encrypted; the upload usually bounds it lower |
| First backup of 5 GB | about 7 CPU minutes; wall time is set by upload bandwidth and tick cadence: under an hour with loopback chaining, around two hours with a 5-minute cron |
| Daily backup, 200 MB changed | 1–3 ticks |

`cpu_throttle: gentle` halves the tick budget and rests 0.1 s after every 0.25 s of work in
backups, forget, prune and check (`Support\Throttle`), so a tick keeps a core busy about
70 % of the time, for hosts with CPU-second quotas; the one-time key derivation is not
throttled. Uploads are sequential in 1.0; parallel uploads via the bundled Requests
library's `request_multiple` are a backlog item.

## 17. Internationalisation and accessibility

Text domain `backups-for-restic`, English source strings, translations as language
packs from translate.wordpress.org. Admin markup with core components, labels, `aria-live`
progress, keyboard-navigable wizard, admin colour schemes, site timezone and date format.

## 18. Versioning, upgrades and uninstall

- Semantic versioning; `backups_for_restic_schema_version` for settings, `job.schema`, dump format id,
  manifest schema, index cache format version. Readers accept older versions, writers emit
  the current one, migrations are forward-only and idempotent.
- Deactivation: unschedule events, cancel the active job gracefully (release the repository
  lock), keep data. Uninstall: delete options, secrets, cron events and the private
  directory after confirmation; the repository on BorgBase is untouched.
- Plugin headers and `readme.txt` (`Requires at least: 6.7`, `Requires PHP: 8.3`,
  `Tested up to`, `Stable tag`) are WordPress.org metadata kept in sync by the release
  checklist.
