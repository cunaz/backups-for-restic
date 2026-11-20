# 07 · Plan for Milestone 3: restore (0.3.0)

Status: proposed on 2026-11-10 by the maintainer's assistant; the decisions in section 7 are
the owner's. The scope and acceptance criteria are those of the
[roadmap](05-roadmap.md#milestone-3--restore-030); this document says how the work is cut,
in which order it is built and tested, and what must be settled first.

## 1. Starting point on 2026-11-10

Milestones 0 to 2 are merged into `main` (six pull requests, 2026-11-10). An audit
of all code and documents against the plan (2026-11-10) found the engine and the job layer
in line with the architecture and the decision records, and it found defects that the tests
had not shown. They were fixed before this plan was written:

- Retention never removed a snapshot of a real backup job, because every snapshot was a
  group of its own ([ADR 0010](adr/0010-retention-and-append-only.md)).
- Prune could delete used packs when one run was tried again after a failed request, could
  not be resumed after a request ended while it deleted, and left an exclusive lock when
  its start was interrupted.
- A check lost its progress when a request failed, reported a repository that this PHP
  cannot read as damaged, and could hang for good on a pack that the host cannot read
  within one request.
- Backup, check and forget refreshed a lock that other clients no longer honoured.
- A cancel request was lost while a tick ran; the recovery kit and the stored REST password
  could be obtained without the re-authentication the security model requires; backups
  could start before the setup was completed.
- After a connection to an existing repository the cleanup removed the wrong index cache;
  notices were given up after 20 minutes; warnings could hide the errors of a check.
- The integration suite failed because PHP's tracing JIT, which the CI setup switches on,
  crashes PHP 8.3 in backup ticks. The test server now runs without the JIT.

What the audit found missing, and what is not a defect but was promised for an earlier
milestone, is carried into this milestone as phase 0. The pull requests were merged on the
owner's instruction on the day of the last engine fixes, so without the 24 hours of
[ADR 0024](adr/0024-governance-and-quality-assurance.md) between the last change to the
engine and the merge; every one of those fixes has a test that fails without it, and the
conformance, integration and mutation runs were green on each pull request.

## 2. Restore implementation at the start of the milestone

| Part | State |
|---|---|
| `Engine\Restore\RestoreRun` | Restores one subpath of a snapshot into a directory, resumable in the middle of a file, with verified content and safe names. It takes no repository lock, has no totals, no throttle and no free-space check, requests one blob at a time and does not require an empty target. |
| Database dump/import | The dump writes one file per table with completion counts ([ADR 0021](adr/0021-database-streaming.md)). `Database\Restore\TableImporter` prepares InnoDB shadow data with transactional progress ([ADR 0035](adr/0035-transactional-shadow-import.md)); the opt-in restore rehearsal verifies base tables and cleans owned shadows. Schema mapping and live apply remain open. |
| Jobs | Types backup, unlock, forget, prune, check. A job is created without parameters and gets them afterwards, so a tick can pick it up without them. One job is active at a time. |
| REST and UI | `GET /snapshots` lists the snapshots of this site or of the repository, `GET /snapshots/<id>` reads one with its manifest and `GET /snapshots/<id>/tree` one directory (package 1.2). The admin page shows the list only. Re-authentication with the WordPress password exists for the recovery kit. |
| Restore safety | Decided in [ADR 0013](adr/0013-restore-safety.md): staging first, apply as a separate job with a dry-run report, typed confirmation for the database. |

## 3. Principles for this milestone

1. **Nothing is written to the live site until an administrator has seen a report and
   confirmed it.** Browsing, restoring to staging and downloading never touch the site.
2. **Every destructive step can be undone until it is confirmed.** The database import
   keeps the replaced tables, the file apply keeps the replaced files, both for a limited
   time and both with a job that puts them back.
3. **The plugin never creates a symbolic link and never follows one.** A link in a snapshot
   is listed in the report and skipped.
4. **Content from the repository is data, not instructions.** The importer executes only
   the statement kinds a dump file of that table may contain. Paths are checked against the
   allowed roots with the resolved path of the destination's parent.
5. **A killed request loses at most one unit of work**, in every new job, and the kill
   tests of Milestone 1 are repeated for each of them.
6. **A restore must work when the administrator is logged out by it.** Jobs are driven by
   cron ticks, not by the browser session.

## 4. Work packages

Sizes: S is one small pull request, M one pull request with its tests, L two or three.
Every package ends with the checks of [04](04-development-and-quality.md) green and, for
changes to `Crypto`, `Format` or `Engine`, the cooling-off period of
[ADR 0024](adr/0024-governance-and-quality-assurance.md).

### Phase 0: close Milestones 0 to 2

| # | Package | Size | Notes |
|---|---|---|---|
| 0.1 | Merge pull requests 1 to 6 | done | Merged on 2026-11-10 on the owner's instruction. |
| 0.2 | Schema numbers | done | `JobStore::create()` takes the job's data since the audit fixes, which restore jobs depend on. The options carry a layout number in `backups_for_restic_schema_version` and every `job.json` one in `schema`; a version that finds a higher number stays inert, and a refused first write keeps the plugin inactive (2026-11-12). Pull request 35 (2026-11-16) keeps a number another request inserted and treats every malformed marker as an unknown layout; its follow-up counts a marker row that decodes to null as malformed too, not as missing. |
| 0.3 | Backups after a stale lock | done | Built as [ADR 0030](adr/0030-lock-continuity.md) (2026-11-10): a backup keeps a record of the index files and unindexed packs it relies on; a run that had to take its lock again, at the start of a call or before it writes an index file or the snapshot, checks that they still exist, and the job starts over if not. A check compares the index files and snapshots it listed and starts over if one is gone. The lock comes before the index in every call, a lock is trusted for 25 minutes, and backup, check and forget write their run number before they take a lock, as prune does. Tested against `restic unlock` and `restic prune` during a pause. |
| 0.4 | Prune removes packs that no index lists | done | Done on 2026-11-10: the plan phase of prune lists the packs on the server and deletes those that no index file lists with the unused ones, under the exclusive lock; `restic check` counts the same packs as additional files before and none after. After a time without a valid lock, prune compares the snapshots and index files with those it marked and read and goes on if they are the same, so a site with ticks far apart finishes; otherwise marking starts over, at most three times. Recorded in ADR 0022 ("As built"). Depends on 0.3. The server pack listing now streams to disk and is validated in bounded batches; unindexed IDs and their deletion cursor stay in a private disk table ([ADR 0033](adr/0033-prune-listing-on-disk.md)). Full-job memory remains part of 0.9. |
| 0.5 | Tests for the WordPress layer | L | **Done.** Also done here: repository requests keep TLS verification on against the site's filters. The HTTP suite gains: every route without and with a non-administrator, cancel during a tick, connect, recovery kit, an append-only rest-server (automatic switch to `external`, no deletion after it is turned off), schedules started by the heartbeat, WP-CLI commands, uninstall, the multisite refusal. The kill test asserts that it interrupted a running tick, and in which step. |
| 0.6 | Browser tests | M | **Done** (`tests/E2E`, the job "Admin page in a browser"). Playwright against the test site in CI: wizard, first backup, settings, cancel. The acceptance criterion of this milestone needs it. |
| 0.7 | Static gates | done | Done on 2026-11-10 with pull request 8: ESLint for `assets/js` with the WordPress rulesets; Plugin Check on the package that `bin/build-package.sh` builds; a check that every commit of a pull request is signed off; a POT file built in CI and not committed; the PHPStan rule for the dependencies of the engine and the layers below it (section 9). The mutation job skips pull requests that cannot change its result. Plugin Check reports 59 errors for native file functions in the WordPress-free core, which the workflow sets aside with the reason ([04](04-development-and-quality.md) §5.8); the answer for the reviewers belongs to 5.4. |
| 0.8 | Smaller promises and gaps | L | **Done** with pull requests 14 to 16 on 2026-11-11. Promised earlier: repository usage on the dashboard; Doctor hints for an enabled JIT and for outbound HTTPS; a settings form to enter the repository and REST passwords again and to upload the CA certificate; response size limits per object type; the recovery kit's validity of five minutes and its event; fields overridden by constants shown read-only; tree order checked on reading; totals and time estimates; WP-CLI commands for settings, cancel and logs. Found by the audit, **first part done**: `repository.format_version` and `repository.id` are written whenever a job opens the repository, and format 2 without `ext-zstd` is refused there; a cached key that no longer decrypts after a change of the salts is derived again; `POST /repository/save` and `create` change nothing while a job runs; the repository password is stored before the repository is created; the check alert and the last results are bound to the repository; deactivation ends the active job and releases its lock; `POST /setup/complete` refuses while the Doctor has a critical finding; the directory and file writes of the plugin layer are checked. Also done: totals and a time estimate for backups, measured against the last one; response size limits per kind of file; repository usage on the dashboard; the WP-CLI commands for settings, cancel and logs; the record of who was shown the recovery kit (its validity of five minutes is dropped: docs/03 §5.5); the Doctor's hints for an enabled JIT and for a repository server that does not answer; the settings form for both passwords and the CA certificate, with the values from `wp-config.php` marked; where the site cannot request itself, the administrator's browser probes the private directory (`Storage\BrowserProbe`). The backup now checks complete writes and reads of working files, including truncation on resume, and cleanup releases locks by the stable run number even when the checkpoint version is unknown (ADR 0032). Tree order and uniqueness are now checked on reading as well as writing, using decoded name bytes; invalid trees are rejected and reported by the repository check. |
| 0.9 | Memory with many blobs | in progress | Two parts are merged. Pull request 27 (2026-11-12) built the compact index, the sealed cache pieces and the memory budget as [ADR 0031](adr/0031-compact-index-and-memory-budget.md). Pull request 34 (2026-11-16) streams index examination/rewrite and check's pack listing, defers prune's cache until its lock holds, and budgets the next encrypted input before downloading it. [Full-engine measurements](benchmarks/2026-11-12-job-memory.md) cover 500k, 1M and 2M blobs, and a CI matrix runs complete WordPress jobs in fresh 128M processes. Its recorded run completes backup, check and prune at 500k blobs and refuses 1M and 2M with `index_too_large`; no job crashed or left a lock. That is the result for this fixture, not a capacity for every installation. A follow-up to the reread of the two pull requests makes check and prune download an invalid stored pack listing again instead of failing on the same file in every request, at most three times in a row so that a server that keeps answering so still ends the job, and treats a cache piece length that no piece can have as a damaged cache instead of a capacity refusal. Still open: the large tree/pack sets and JSON checkpoints that stay in memory, bounds for oversized decoded objects and decompression, and verification that the per-tick reports are published as artifacts since the upload was corrected. |

### Phase 1: read a snapshot

| # | Package | Size | Notes |
|---|---|---|---|
| 1.1 | `Engine\Snapshot\Reader` | implemented; CI pending | [ADR 0034](adr/0034-snapshot-reader.md): snapshots filtered by host and required site tags or the whole repository; one directory by snapshot path; validated manifests found by locator or a bounded legacy search. Each read holds a shared lock and never follows links. Authenticated tree ciphertext shares the private cache limit with the index. Routes and browser integration remain in 1.2 and 1.3. |
| 1.2 | Routes and commands | implemented; CI pending | `GET /snapshots` with the scope `site` (default) or `repository`, each list cached on its own; `GET /snapshots/<id>` with the manifest or null; `GET /snapshots/<id>/tree?path=` one directory per request in pages of at most 1000 entries; `wp backups-for-restic ls <snapshot> [<path>]`. `Restore\SnapshotBrowser` builds the answers and assigns the error codes and statuses; names that are not UTF-8 are shown lossy and addressed by a path token ([ADR 0039](adr/0039-snapshot-routes.md)). The admin page reads them in the browser of 1.3. |
| 1.3 | Snapshot browser | implemented; CI pending | The Snapshots tab: a switch between this site's snapshots and the whole repository; the list with tags and, for this site's snapshots, whether the next forget keeps or removes each one (`retention` of `GET /snapshots`, decided by `Forget::apply()` with the policy of `Jobs\Maintenance\RetentionSettings`, which the forget job uses too; null in the repository scope and while the site removes nothing); a snapshot's details and manifest, or a sentence that it has none or that it cannot be read; the directory browser with one directory per request, further pages, a path back up, links as text, a mark on names that are not exact, and a checkbox per entry. The ticked paths are kept in `restoreSelection` of the admin script for the restore controls of 2.5; an entry below a ticked directory is shown as ticked and is not kept on its own, as `Engine\Restore\Selection` would drop it, and the selection ends at its 256 paths. This completes phase 1: what the architecture promised as the retention preview for Milestone 2 exists, and nothing of this package writes to the repository. Starting a restore, progress, the staging list and downloads remain in 2.4 and 2.5. |

### Phase 2: restore files to staging

| # | Package | Size | Notes |
|---|---|---|---|
| 2.1 | `RestoreRun`, second version | Built | The version 2 engine and interruption tests are implemented and have run through WordPress and the restic CLI. [ADR 0036](adr/0036-selected-file-staging.md). Several selected paths; a first pass over the trees that counts files and bytes for the progress display and the free-space check; a shared repository lock kept as backups keep theirs; throttle; neighbouring blobs of one pack fetched with one ranged request; names that are valid on Windows too; the target is a directory the job created; links are recorded, not created. |
| 2.2 | Job type `restore` | M, built | Built ([ADR 0037](adr/0037-restore-job.md)): steps preflight, unlocking, planning, restoring, recording; cleanup releases the lock on every path and removes partial staging after a failure or a cancel. A third step provider beside backup and maintenance. `POST /jobs` starts a restore with `snapshot` and `paths`. A running restore keeps scheduled jobs waiting. The files of a finished job and their report stay in `<private>/restores/<job>/`; review and CI of the integration tests remain. |
| 2.3 | Staging | M, built | Built ([ADR 0040](adr/0040-restore-staging-expiry.md)): `Restore\Staging` lists what finished restores left in `<private>/restores/<job>/`, with snapshot, files and bytes from the job's result and the time of expiry. The setting `restore.staging_expiry_hours` (default 24, 1 to 168) counts from the end of the job. The heartbeat removes expired staging and the leftovers of jobs that did not succeed, within five seconds per run, and never the directory of a job that has not ended. `GET /restores` and `DELETE /restores/{id}`. A removal renames the directory before it deletes, so nothing half removed is listed. Review and CI of the integration tests remain; the list on a screen belongs to 2.5. |
| 2.4 | Download | implemented | The restore job builds an optional stored ZIP in resumable ticks. The download route issues single-use forms for individual staged files, the ZIP and reports. Staging expiry and file identity are checked again during redemption ([ADR 0038](adr/0038-download-zip-and-tokens.md)). |
| 2.5 | Screens and commands | M, built | Browser-selected staging, job progress, expiring file, ZIP and report downloads, confirmed deletion, and `wp backups-for-restic restore`. Covered by packaged Chromium acceptance tests. |

### Phase 3: database import

| # | Package | Size | Notes |
|---|---|---|---|
| 3.1 | Transactional base-table import | L, built for supported schemas | Restore rehearsals use owned InnoDB shadows, transactional row and content progress, and bounded cleanup. Kill tests exercise DDL and transaction boundaries. Mapped foreign keys and source views require a manual restore. |
| 3.2 | Report before the swap | S, built | Verified source row counts, live estimates, engines, charsets, collations, trigger information and compatibility blockers appear in the rehearsal. The final review binds the source transcript, account, live schema and physical identities. |
| 3.3 | Swap and state | M, built for compatible MySQL sites | One write-locked, atomic RENAME exchanges the verified generations and a recovery witness. The current address, active plugins, plugin state, cron and administrator account survive. MariaDB and incompatible sites use the manual procedure. |
| 3.4 | Undo | S, built | The previous generation is retained for 24 hours. A separately confirmed undo restores it and retains the replaced generation, including subsequent edits, for a fresh 24 hours. The heartbeat removes verified private objects in bounded batches. |
| 3.5 | Confirmation | S, built | The WordPress administrator confirms the current password and typed home URL against a session- and direction-bound review. The worker rechecks permissions, identity, live data structures and protected account state at commit. |

[Supported engines, permissions and recovery protocol](adr/0043-atomic-mysql-database-exchange.md).

The first version refuses a dump whose table prefix differs from the site's (decision D3).

### Phase 4: apply files to the live site

| # | Package | Size | Notes |
|---|---|---|---|
| 4.1 | Apply planner | M, built | WP-CLI streams the staged actions with `wp backups-for-restic apply-plan <restore-job-id>`. It checks the configured site roots, both chains of parent directories, aliases, file types and permissions. Special snapshot entries are reported as skipped. The writable preview binds source and live file hashes, parent identities and the reviewed options to the confirmation digest. Selections over 100 entries or 8 MiB of staged and existing content use a resumable background preview. Its pages hold up to 100 actions. |
| 4.2 | Job type `apply` | L, built | The worker repeats the confirmed preflight before writing. It keeps private copies and per-entry journals on the destination filesystem. A new regular file is linked exclusively, a replacement is renamed atomically, and a new directory is created exclusively. WordPress maintenance mode covers the mutation phase across ticks. The initiating administrator can poll and cancel the job. Only the worker plugin and its own cron events run on admitted control requests. Bootstrap-critical core, configuration, must-use plugins and drop-ins require offline restoration. A partial apply records its progress and retains its undo materials. |
| 4.3 | Undo | S, built | A second confirmed job reverses attempted changes in reverse order for 24 hours after an apply ends. It verifies installed identities and contents, including after cancellation or interruption. Changed files and a new directory containing unrelated entries block the undo. Staging required by an apply or its undo survives ordinary staging expiry and history pruning. Downloads still expire on schedule. |
| 4.4 | Screens | M, built | Paginated file actions, reviewed session-bound previews, site-address confirmation with the current administrator password, progress, and a separately confirmed undo. Packaged Chromium acceptance covers rejected confirmation, stale previews and exact live file contents before and after undo. |

### Phase 5: whole-site flows and release

| # | Package | Size | Notes |
|---|---|---|---|
| 5.1 | Import on a fresh installation | Built | The wizard connects an existing repository, selects the verified source manifest and maps content and uploads to the receiving installation. Staging rehearses the database before the administrator separately confirms file and database changes. Eligible MySQL sites can exchange and undo tables. The report identifies manual recovery steps for incompatible sites. |
| 5.2 | Recovery guide | S | Every command of [06](06-disaster-recovery.md) is run by the conformance suite against rest-server; the BorgBase column is run once by the owner. |
| 5.3 | Acceptance test | Built and run locally | A packaged Chromium test connects a second WordPress 7.1.5 installation with independent salts, paths, database and administrator. On MySQL 8.4.11 it verifies a real upload, paginated file review, live database exchange and undo, public posts in each generation, and preservation of the destination login, home URL and backup setting. |
| 5.4 | Submission package | Prepared locally | The builder produces a reproducible installable ZIP. Directory icons, banners and three screenshots live in `.wordpress-org/`. The readme explains direct file access. The release workflow checks the staged package and verifies the bytes after SVN deployment. Submission and the public release require the owner. |

## 5. Order and pull requests

```
0.1 ─ 0.2 ─┬─ 1.1 ─ 1.2 ─ 1.3
           ├─ 2.1 ─ 2.2 ─ 2.3 ─ 2.4 ─ 2.5 ─┬─ 4.1 ─ 4.2 ─ 4.3 ─ 4.4 ─┐
           └─ 3.1 ─ 3.2 ─ 3.3 ─ 3.4 ─ 3.5 ─┘                          ├─ 5.1 ─ 5.3 ─ 5.4
0.3 ─ 0.4      0.5 ─ 0.6 (needed by 5.3)      0.7 to 0.9 at any time     5.2 ┘
```

- One package is one pull request against `main`, merged before the next one of its line
  starts. Milestone 2 was built as a stack of six open pull requests; every fix low in the
  stack had to be merged up through all of them. That is not repeated.
- Phases 1, 2 and 3 do not depend on each other after 0.2 and can be reviewed in any order.
- Phase 0.3 changes the backup engine and is reviewed on its own, with the cooling-off
  period, before 0.4.

## 6. Tests

| Level | What is added |
|---|---|
| Unit | `RestoreRun` with several paths, interrupted after every unit; the apply planner against crafted trees (link to `/etc`, `../`, absolute names, a file where a directory is expected, names that differ only in case); the importer's statement filter against crafted dump files; the zip writer against `unzip -t`. |
| Database (MySQL and MariaDB in CI) | Import into shadow tables, swap, undo; identical checksums of every table after export and import; a kill between any two batches. |
| Conformance | The plugin restores what restic wrote and restic restores what the plugin wrote, for selections of paths; the guide's commands. |
| Integration (HTTP against WordPress) | Restore to staging, download, database import and apply through the REST API with `max_execution_time = 30` and `memory_limit = 128M`; a killed tick in each new job; the session of the administrator ends at the swap and the job still finishes. |
| Browser | The acceptance criterion; the confirmations. |
| Fuzzing and mutation | Fuzz targets for the tree path parameter and the dump statement splitter; the mutation gate extended to the apply planner and the importer's filter. |

## 7. Decisions

Taken with this plan, as engineering decisions (each gets a decision record with its
package, numbers from 0030):

- Staging holds files and directories only; links are reported.
- The database is imported into shadow tables and exchanged with one statement; the
  replaced tables and files are kept for an undo.
- Zip files are written by the plugin without an extension.
- A restore takes a shared repository lock, a restore job blocks other jobs.
- Each package is merged before the next one starts.

For the owner:

| # | Question | Proposed answer |
|---|---|---|
| D1 | May pull requests be merged by the assistant once CI is green and review findings are addressed, with the cooling-off period for engine changes? Should `main` get a ruleset with required checks? | Answered on 2026-11-10: yes, the assistant merges, also in future. Open: the ruleset. Proposal: yes, once merging through GitHub keeps the commit identity the project uses. |
| D2 | After a database import, should the administrator who started it keep the current account and stay able to log in? | Yes, as a preselected option. |
| D3 | A snapshot from a site with another table prefix: refuse, or rename tables and the known prefixed keys? | Refuse in 0.3.0 and say which prefix is needed; a renamer afterwards. |
| D4 | A snapshot from another site address: offer to keep this site's address, without rewriting addresses inside the content? | Yes; rewriting content is out of scope and said so in the report. |
| D5 | Should apply be able to delete files that are not in the snapshot? | No in 0.3.0. |
| D6 | Who provides icon, banner and screenshots for WordPress.org, and when is the plugin submitted? | Drafts by the assistant, approval and date by the owner. |
| D7 | BorgBase: two free repositories and credentials for the live checks, entered by the owner as CI secrets or run by the owner. | Run by the owner with a script from the repository, so that no credential leaves the owner's hands. |
| D8 | When does the 24-hour cooling-off period of ADR 0024 start? | With the last commit of the pull request that touches `Crypto`, `Format` or `Engine`; the diff is read again before merging. |
| D9 | The promises without a milestone in section 9: keep or drop each. | As proposed there. |

Still open from earlier: the WordPress.org user name for `readme.txt`, whether review
threads may be answered through the API, and the old revisions of pull request descriptions.

## 8. Risks

| Risk | Answer in this plan |
|---|---|
| Disk space: staging, shadow tables and kept files need the size of what is restored a second time. | Totals before the restore, a preflight that refuses, expiry of staging and undo data. |
| The database user may lack `CREATE`, `DROP` or `ALTER`. | A probe table in the preflight. |
| Character sets and collations the server does not know (for example from MySQL 8 to MariaDB). | Listed in the report; the import refuses instead of guessing. |
| Object caches keep the old database's values. | Flushed after the swap; the report names a persistent cache when one is active. |
| The administrator is logged out by the import. | Jobs do not depend on the session; decision D2. |
| Windows hosts: reserved names, renaming a file that is in use. | Names are checked when restoring; apply reports files it could not replace and can be run again. |
| PHP's JIT crashes PHP 8.3 in the plugin's hot loops. | Doctor hint (0.8); the cause is narrowed down and reported to PHP. |
| A restore of another PHP or WordPress version. | The manifest's versions are shown in the report. |
| Multisite. | Unsupported, as decided in [ADR 0026](adr/0026-multisite-deferred.md). |

## 9. Promises without a milestone

The documents promised these at some point and no milestone carries them. The audit moved
none of them silently; each needs a yes or no.

| Promise | Proposal |
|---|---|
| Hourly backups (FR-03) | Drop: twice a day is the most a shared host carries; say so in the scope. |
| Download of a job's log | Keep, with the restore screens of phase 2, which need downloads anyway. |
| Translation of log lines, job warnings and byte units | Keep for 1.0: log lines get codes that the admin page translates. |
| A log of every request to the server (`http.log`) | Drop: the event log names failed requests; a full log is a privacy and size risk. |
| The wizard refuses a format 2 repository when PHP lacks zstd | Done in phase 0.8: the unlock step refuses, and writes the format version whenever a job opens the repository. |
| Deriving the cached key again after a change of the salts without the administrator | Done in phase 0.8 for the case it can work in: passwords defined in `wp-config.php`. Stored passwords do not survive the salts by design; the settings form of 0.8 is the answer there. |
| Removing stale locks of other hosts from the plugin (`unlock --yes`) | Keep after 1.0; `restic unlock` exists. |
| A PHPStan rule for the engine's dependencies; a line length limit | Rule: done in phase 0.7 (`tests/PHPStan/EngineDependencyRule.php`). Line length: drop. |
| Citations of restic's design document in the `Format` classes | Keep, as a small pull request at any time. |
| Screenshots of hosting panels, a hosting matrix, a first-backup page | Drop the matrix; screenshots with the submission package (5.4). |
| A form-post fallback for the admin page | Drop: the page needs the REST API in any case (ADR 0012, as built). |
| An option to skip a run without changes | Drop: every run produces a snapshot, which hosted monitoring relies on (ADR 0009). |
| A donation link in `readme.txt` | Owner's choice; not before the first release. |
