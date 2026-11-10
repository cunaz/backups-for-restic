# 01 · Goals and scope

## 1. Problem statement

Site owners on shared or managed hosting have no way to put a WordPress site into a
restic-based backup regime: restic needs a server to run on, and typical hosting offers no
shell and no way to install programs. WordPress backup plugins that do run everywhere keep
plaintext archives on the web server, depend on the vendor's cloud, or ship large amounts of
third-party code.

This plugin gives a WordPress site a **restic-native, encrypted, deduplicated backup on a
self-hosted restic REST server or a hosted one such as BorgBase**, managed from the WordPress
admin, running on plain shared hosting, with a restore path that works with nothing but the
`restic` command line on any computer.

## 2. Target users

| Persona | Needs | Implications |
| --- | --- | --- |
| **Site owner on shared or managed hosting** (primary) | Install from WordPress.org, set up in ten minutes, no server access, see at a glance that backups are fresh, restore a file or the whole site | Pure PHP engine, guided wizard, dashboard, Site Health, in-app restore, plain-language hosting guidance |
| **Agency / freelancer** running many sites | Repeatable configuration, notifications, one repository per site, `wp-config.php` constants | Constants for secrets and paths, WP-CLI where available, structured logs |
| **Self-hoster** running restic's `rest-server` | Back up WordPress to their own infrastructure over HTTPS | rest-server option in the wizard, CA certificate upload, path-based repositories ([ADR 0025](adr/0025-repository-servers.md)) |
| **Security-conscious operator** | Verify every claim, keep secrets out of the database, append-only storage, restore without trusting the plugin | Threat model, conformance to restic's format, constants, append-only aware retention, plain-restic recovery |
| **Contributor** | Understand the code quickly, change one thing safely | Small modules, ADRs, tests as specification, oracle-based conformance suite |

## 3. Goals (ranked)

1. **Correct and restorable backups.** Every snapshot must be restorable with the plain
   restic CLI from the recovery kit alone, and must pass `restic check --read-data`.
2. **Runs on shared hosting.** No binary, no shell, no cron requirement; bounded CPU, memory
   and disk; works with PHP time limits of 30 seconds.
3. **Security by default.** The safe path is the easy path; insecure configurations are
   refused, not warned about; no custom cryptographic primitives.
4. **Minimal dependencies.** PHP and WordPress only.
5. **Operational robustness.** Multi-gigabyte sites back up through many short slices;
   killed requests, flaky WP-Cron and host restarts cost at most one slice.
6. **Readable code.** A new contributor finds the place to change within minutes and can run
   the full test suite locally, including the restic conformance suite.
7. **Honest observability.** The dashboard shows what really happened. Progress shows the
   files and bytes processed so far; an estimate of how long the first backup will take on
   this host is planned for Milestone 3.

## 4. Non-goals

- Not a general-purpose backup product: **restic format over the restic REST protocol**, on
  a self-hosted `rest-server` or BorgBase ([ADR 0025](adr/0025-repository-servers.md)). Other
  backends (S3, SFTP, local) are out of scope.
- No compression in the native engine (repository format version 1); compression is a CLI
  matter on another computer ([ADR 0019](adr/0019-repository-format-and-chunker.md)).
- No full `prune` with repacking in 1.0; prune-lite reclaims fully unreferenced packs
  ([ADR 0022](adr/0022-maintenance-scope.md)).
- No migration or cloning features (domain change, search-replace).
- No multisite support in 1.0; activation on multisite is refused and network support is
  planned for 1.1 ([ADR 0026](adr/0026-multisite-deferred.md)).
- No real-time or file-watching backups.
- No bundled, downloaded or self-updated restic binary, ever
  ([ADR 0016](adr/0016-no-binary-provisioning.md)); an optional binary engine for VPS users
  is a possible later feature ([Appendix A](appendix-a-binary-engine.md)).
- No remote management service, telemetry or phone-home.
- Windows hosting is not tested in 1.0 (path and mode handling); it is not excluded by design.

## 5. Target environments

| Class | Example | Support |
| --- | --- | --- |
| Shared hosting (cPanel, Plesk, proprietary panels), `proc_open` disabled, no shell | the majority of WordPress sites | **Primary.** Needs outbound HTTPS; a control-panel cron fetching `wp-cron.php` is recommended |
| Managed WordPress hosting | mid-tier and premium hosts | **Primary.** Same requirements; hosts with aggressive CPU quotas use the gentle throttle |
| VPS / dedicated servers / containers | Debian, Ubuntu, Docker | **Supported.** Works unchanged; WP-CLI `--wait` runs a job in one process (the database dump stays consistent per page, as everywhere; a consistent snapshot is a later option, [ADR 0021](adr/0021-database-streaming.md)); a future binary engine would add speed and compression |
| Hosts blocking outbound HTTPS | rare, corporate | **Unsupported.** The wizard's connection test fails there; a check in the Doctor is planned for Milestone 3 |
| Windows / IIS | — | **Untested** in 1.0 |

Multisite networks are **not supported in 1.0**: activation is refused with a clear message
([ADR 0026](adr/0026-multisite-deferred.md)). Unusual directory layouts (Bedrock,
`WP_CONTENT_DIR` outside `ABSPATH`, custom `UPLOADS`) are supported by backing up the set of
distinct roots.

## 6. Functional requirements

What 1.0 must do. Where the code does not meet a requirement yet, the row says so and names
the milestone.

| ID | Requirement |
| --- | --- |
| FR-01 | Guided first-run setup: environment check with hosting guidance, choice of repository server (own rest-server or BorgBase), credentials, connection test, append-only detection, repository creation or connection, unlock with progress, recovery kit acknowledgement, schedule, first backup |
| FR-02 | Back up the site's files (all distinct roots) and the database into one restic snapshot |
| FR-03 | Scheduled backups in the site's time zone: daily by default, configurable as twice daily, daily or weekly, or manual only. Hourly backups are not offered and not planned yet |
| FR-04 | Manual backup from the admin UI (and WP-CLI where available) with live progress. Progress shows files and bytes so far; totals and time estimates are planned for Milestone 3 |
| FR-05 | Retention with restic's policy semantics, or marked as externally managed for append-only repositories |
| FR-06 | Periodic integrity checks: structural plus a configurable sample of data |
| FR-07 | Snapshot browser: list snapshots, browse directories, view the manifest. The list exists; browsing and the manifest view arrive with Milestone 3 |
| FR-08 | Restore: single file download, selected paths to staging, database restore, guarded apply to the live site (Milestone 3; until then with the restic CLI and the recovery kit) |
| FR-09 | Notifications on failure and stale backups by e-mail; webhook later |
| FR-10 | Site Health tests and debug information |
| FR-11 | WP-CLI commands with parity where a terminal makes sense; nothing requires WP-CLI. Today: `doctor`, starting backup, forget, prune and check, `tick`, `status` and `snapshots`; settings, cancelling, logs and the recovery kit are planned for Milestone 3 |
| FR-12 | Redacted logs per job. They are shown in the admin area (Logs tab); a download does not exist and is not planned yet |
| FR-13 | Recovery kit: everything needed to restore with the restic CLI, shown once and after re-authentication |
| FR-14 | Include/exclude configuration with safe defaults |
| FR-15 | Uninstall removes all local plugin data; the repository on the server is untouched |
| FR-16 | Repositories created by the plugin are readable, checkable and restorable by the restic CLI; repositories created by restic can be connected: format 1 always, format 2 when PHP has `ext-zstd` |

## 7. Non-functional requirements

| ID | Requirement |
| --- | --- |
| NFR-01 | Every operation runs in slices that fit a 30-second PHP request; no step assumes a long-lived process except WP-CLI `--wait` |
| NFR-02 | A killed request, a lost WP-Cron run or a host restart loses at most one slice of work and never leaves an inconsistent repository |
| NFR-03 | Peak memory of the plugin's code does not grow with the size of files or tables. It does grow with the number of blobs in the repository: the index takes 60 to 80 bytes per blob, 33 MB for half a million and 61 MB for a million (measured, [benchmark](benchmarks/2026-11-index-memory.md)), and a backup needs about 20 MiB and three packs besides it. Under a `memory_limit` of 128 MB backups work up to roughly a million blobs, with packs smaller than configured near that end; beyond it the job ends on its first tick with the error `index_too_large` instead of running out of memory ([ADR 0031](adr/0031-compact-index-and-memory-budget.md)). The checkpoints of prune and check still grow by about 150 bytes per tree and pack |
| NFR-04 | No secret ever appears in process arguments (there are none), URLs, logs, notices, e-mails, HTML or REST responses |
| NFR-05 | All admin actions require `manage_options` and a nonce or REST authentication |
| NFR-06 | 100 % of strings translatable; English source strings, German translation maintained on translate.wordpress.org. Not met yet for three kinds of text that the admin page shows in English: job log lines, job warnings and byte units; closing that gap is not planned yet |
| NFR-07 | Admin UI accessible (keyboard, screen readers, admin colour schemes) |
| NFR-08 | PHPStan at maximum level and WordPress Coding Standards pass in CI; Plugin Check joins them in Milestone 3; unit tests run without WordPress or a database; the conformance suite passes against the restic CLI; the mutation gate holds for `Crypto`, `Format` and `Engine\Forget`, and every parser of server data is fuzzed |
| NFR-09 | A release is reproducible from a tagged commit by CI and deployed to WordPress.org by CI only |
| NFR-10 | Throughput gates measured on PHP 8.3 without JIT (see architecture §16) hold on every release |

## 8. Constraints

- WordPress plugin conventions: GPL-compatible license, text domain equals slug, `readme.txt`
  as the listing's source of truth, PHP 7.4 is WordPress' floor but the plugin chooses PHP
  8.3 ([ADR 0003](adr/0003-version-floors.md)).
- **WordPress.org is the distribution channel** ([ADR 0017](adr/0017-distribution-via-wordpress-org.md)):
  no downloading, bundling or self-updating of executable code; unique prefixes of at least
  four characters; disclosure of the external service (the repository server the user
  configures, with BorgBase named as the preset) in `readme.txt`;
  human-readable code; graceful failure when requirements are missing; translations through
  translate.wordpress.org; a clean Plugin Check run.
- **Shared hosting** ([ADR 0018](adr/0018-native-php-engine.md)): no processes, bounded
  slices, outbound HTTPS only, disk quotas respected.
- **restic compatibility**: the plugin writes only what `restic check --read-data` accepts
  and uses repository format version 1 ([ADR 0019](adr/0019-repository-format-and-chunker.md)).
- Trademarks and independence: "BorgBase" and "restic" belong to third parties. The name
  must not begin with either ([ADR 0015](adr/0015-naming-and-license.md)), and the
  plugin is never presented together with BorgBase: it simply works with it
  ([ADR 0025](adr/0025-repository-servers.md)).

## 9. Naming

The plugin is called **Backups for restic** ([ADR 0015](adr/0015-naming-and-license.md)).

| Item | Value |
| --- | --- |
| Display name | Backups for restic |
| Slug / text domain / directory / GitHub repository | `backups-for-restic` |
| PHP namespace | `BackupsForRestic\` |
| Prefix for hooks, options, transients, cron events | `backups_for_restic_` |
| WP-CLI command | `wp backups-for-restic` |
| Constants | `BACKUPS_FOR_RESTIC_*` |
| REST namespace | `backups-for-restic/v1` |
| Private directory | `backups-for-restic` ([ADR 0007](adr/0007-private-directory.md)) |

## 10. Glossary

| Term | Meaning |
| --- | --- |
| **Repository** | The restic repository on the repository server (a self-hosted rest-server or BorgBase); encrypted with the *repository password* |
| **Repository password** | The restic password; the single secret nobody can recover |
| **Master key** | The repository's data key, derived once from the password via the key file and cached encrypted |
| **REST credentials** | The HTTP username and password of the repository server (an `.htpasswd` entry on rest-server; per repository on BorgBase) |
| **Engine** | The plugin's PHP implementation of the restic format and operations |
| **Snapshot** | One restic snapshot = one backup run (files + database directory + manifest) |
| **Blob, pack, index, tree** | restic's building blocks: encrypted chunks, files that bundle them, their directory, and directory listings |
| **Job** | A unit of work managed by the plugin: backup, unlock, forget, prune, check; restore from Milestone 3 |
| **Tick** | One bounded slice of the job runner, triggered by WP-Cron, loopback chaining, an admin poll or WP-CLI |
| **Checkpoint** | What a job keeps on disk between ticks so that the next one continues: for a backup the stack of directories on the walk with their finished entries and the file being read. It replaced the separate ledger of the first design ([ADR 0014](adr/0014-file-based-job-store.md)) |
| **Private directory** | The plugin's working directory for the index cache, job state with checkpoints and logs, the scrypt table during an unlock and, from Milestone 3, restore staging. Packs are built in memory, not there |
| **Recovery kit** | Repository URL, REST credentials, repository password, format version, a restore recipe and, for a server with its own certificate authority, the CA certificate |
| **Spool** | A virtual path prefix in the snapshot under which the database files and the manifest appear; on disk there are only two empty directories for the backup to walk into |
| **Append-only** | A server setting (rest-server `--append-only`, BorgBase repository option) that rejects deletions except lock files; detected automatically |
| **Prune-lite** | Deleting packs that no snapshot references, without repacking partially used packs |
