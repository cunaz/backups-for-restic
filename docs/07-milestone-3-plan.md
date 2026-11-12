# 07 · Plan for Milestone 3: restore (0.3.0)

Status: proposed on 2026-11-10 by the maintainer's assistant; the decisions in section 7 are
the owner's. The scope and acceptance criteria are those of the
[roadmap](05-roadmap.md#milestone-3--restore-030); this document says how the work is cut,
in which order it is built and tested, and what must be settled first.

## 1. Where the project stands

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

## 2. What exists for restore, and what does not

| Part | State |
|---|---|
| `Engine\Restore\RestoreRun` | Restores one subpath of a snapshot into a directory, resumable in the middle of a file, with verified content and safe names. It takes no repository lock, has no totals, no throttle and no free-space check, requests one blob at a time and does not require an empty target. |
| Database dump (`Database\Dump`) | One file per table with a header and a trailer of rows and bytes ([ADR 0021](adr/0021-database-streaming.md)). There is **no importer** in `src/`; the tests import with a helper. |
| Jobs | Types backup, unlock, forget, prune, check. A job is created without parameters and gets them afterwards, so a tick can pick it up without them. One job is active at a time. |
| REST and UI | `GET /snapshots` lists every snapshot of the repository. No route reads a tree. Re-authentication with the WordPress password exists for the recovery kit. |
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
| 0.2 | Schema numbers | done | `JobStore::create()` takes the job's data since the audit fixes, which restore jobs depend on. The options carry a layout number in `backups_for_restic_schema_version` and every `job.json` one in `schema`; a version that finds a higher number stays inert, and a refused first write keeps the plugin inactive (2026-11-12). |
| 0.3 | Backups after a stale lock | done | Built as [ADR 0030](adr/0030-lock-continuity.md) (2026-11-10): a backup keeps a record of the index files and unindexed packs it relies on; a run that had to take its lock again, at the start of a call or before it writes an index file or the snapshot, checks that they still exist, and the job starts over if not. A check compares the index files and snapshots it listed and starts over if one is gone. The lock comes before the index in every call, a lock is trusted for 25 minutes, and backup, check and forget write their run number before they take a lock, as prune does. Tested against `restic unlock` and `restic prune` during a pause. |
| 0.4 | Prune removes packs that no index lists | M | Aborted backups leave such packs; the check reports them, nothing removes them. Under the exclusive lock they are deleted like unused packs, compared with restic's count of unreferenced packs. Depends on 0.3. |
| 0.5 | Tests for the WordPress layer | L | **Done.** Also done here: repository requests keep TLS verification on against the site's filters. The HTTP suite gains: every route without and with a non-administrator, cancel during a tick, connect, recovery kit, an append-only rest-server (automatic switch to `external`, no deletion after it is turned off), schedules started by the heartbeat, WP-CLI commands, uninstall, the multisite refusal. The kill test asserts that it interrupted a running tick, and in which step. |
| 0.6 | Browser tests | M | **Done** (`tests/E2E`, the job "Admin page in a browser"). Playwright against the test site in CI: wizard, first backup, settings, cancel. The acceptance criterion of this milestone needs it. |
| 0.7 | Static gates | done | Done on 2026-11-10 with pull request 8: ESLint for `assets/js` with the WordPress rulesets; Plugin Check on the package that `bin/build-package.sh` builds; a check that every commit of a pull request is signed off; a POT file built in CI and not committed; the PHPStan rule for the dependencies of the engine and the layers below it (section 9). The mutation job skips pull requests that cannot change its result. Plugin Check reports 59 errors for native file functions in the WordPress-free core, which the workflow sets aside with the reason ([04](04-development-and-quality.md) §5.8); the answer for the reviewers belongs to 5.4. |
| 0.8 | Smaller promises and gaps | L | **Done** with pull requests 14 to 16 on 2026-11-11. Promised earlier: repository usage on the dashboard; Doctor hints for an enabled JIT and for outbound HTTPS; a settings form to enter the repository and REST passwords again and to upload the CA certificate; response size limits per object type; the recovery kit's validity of five minutes and its event; fields overridden by constants shown read-only; tree order checked on reading; totals and time estimates; WP-CLI commands for settings, cancel and logs. Found by the audit, **first part done**: `repository.format_version` and `repository.id` are written whenever a job opens the repository, and format 2 without `ext-zstd` is refused there; a cached key that no longer decrypts after a change of the salts is derived again; `POST /repository/save` and `create` change nothing while a job runs; the repository password is stored before the repository is created; the check alert and the last results are bound to the repository; deactivation ends the active job and releases its lock; `POST /setup/complete` refuses while the Doctor has a critical finding; the directory and file writes of the plugin layer are checked. Also done: totals and a time estimate for backups, measured against the last one; response size limits per kind of file; repository usage on the dashboard; the WP-CLI commands for settings, cancel and logs; the record of who was shown the recovery kit (its validity of five minutes is dropped: docs/03 §5.5); the Doctor's hints for an enabled JIT and for a repository server that does not answer; the settings form for both passwords and the CA certificate, with the values from `wp-config.php` marked; where the site cannot request itself, the administrator's browser probes the private directory (`Storage\BrowserProbe`). The backup now checks complete writes and reads of working files, including truncation on resume, and cleanup releases locks by the stable run number even when the checkpoint version is unknown (ADR 0032). The tree-order check on reading remains a separate phase 0.8 change. |
| 0.9 | Memory with many blobs | M | The index is held in memory at about 100 bytes per blob, and prune encodes its filter into every checkpoint. Measure backup, prune and check with half a million, one million and two million blobs under 128M; then either a preflight estimate that refuses clearly, or a compact index. NFR-03 is worded for file sizes until then. |

### Phase 1: read a snapshot

| # | Package | Size | Notes |
|---|---|---|---|
| 1.1 | `Engine\Snapshot\Reader` | M | Snapshots of this site (host and site tag) or of the whole repository; one directory of a tree by path; the manifest of a snapshot. Tree blobs are cached in the private directory within the cache limit. |
| 1.2 | Routes and commands | S | `GET /snapshots` with a scope, `GET /snapshots/<id>` with the manifest, `GET /snapshots/<id>/tree?path=` one directory per request; `wp backups-for-restic ls`. |
| 1.3 | Snapshot browser | M | List, details with the manifest, directories, a selection of paths, and for each snapshot whether the retention policy would remove it at the next forget (the preview the architecture promised for Milestone 2). |

### Phase 2: restore files to staging

| # | Package | Size | Notes |
|---|---|---|---|
| 2.1 | `RestoreRun`, second version | L | Several selected paths; a first pass over the trees that counts files and bytes for the progress display and the free-space check; a shared repository lock kept as backups keep theirs; throttle; neighbouring blobs of one pack fetched with one ranged request; names that are valid on Windows too; the target is a directory the job created; links are recorded, not created. |
| 2.2 | Job type `restore` | M | Steps preflight, unlocking, planning, restoring, recording; cleanup releases the lock on every path. A third step provider beside backup and maintenance. A running restore keeps scheduled jobs waiting. |
| 2.3 | Staging | M | `<private>/restores/<job>/` with the files and a report; expiry after 24 hours by default; size shown; removal by the administrator or the heartbeat. |
| 2.4 | Download | M | One file through a route that streams it with a one-time token; a selection as a zip file written by the plugin itself (stored, not compressed, so it needs no extension and no memory), built by a resumable step. |
| 2.5 | Screens and commands | M | Start from the browser, progress, the staging list, download, delete; `wp backups-for-restic restore`. |

### Phase 3: database import

| # | Package | Size | Notes |
|---|---|---|---|
| 3.1 | `Database\Restore\Importer` | L | Reads the dump files of a staging directory, checks header and trailer, and executes only `CREATE TABLE` and `INSERT` for the table a file belongs to. It imports into **shadow tables** with short temporary names, resumable per table and row batch, while the site keeps running on its own tables. |
| 3.2 | Report before the swap | S | Tables that will be replaced, added or left, rows now and in the snapshot, prefix, character sets the server lacks, the site address in the snapshot. |
| 3.3 | Swap and state | M | One `RENAME TABLE` statement exchanges live and shadow tables; maintenance mode lasts for that statement. Before it, the plugin's own options and its cron events are copied into the shadow options table (`PluginStatePreserver`), and, if chosen, this site's address and the current administrator's account. Afterwards the object cache is flushed and the events are scheduled again. |
| 3.4 | Undo | S | The replaced tables stay under temporary names for 24 hours; one job swaps them back; the heartbeat removes them afterwards. |
| 3.5 | Confirmation | S | The swap needs the WordPress password and the typed site address. |

The first version refuses a dump whose table prefix differs from the site's (decision D3).

### Phase 4: apply files to the live site

| # | Package | Size | Notes |
|---|---|---|---|
| 4.1 | Apply planner | M | A pure function from a staging tree and the allowed roots to a list of actions with reasons. It refuses links, `..`, invalid names and every destination whose resolved parent lies outside the roots; it skips the plugin's own directory, the private directory and `wp-config.php` unless selected. The dry-run report is its output. This is the unit the second acceptance criterion tests. |
| 4.2 | Job type `apply` | L | Maintenance mode; each file is copied beside its destination and renamed over it; the replaced file is kept in the job's directory; resumable; the opcode cache is reset for replaced PHP files. Files that exist only on the site are never deleted (decision D5). |
| 4.3 | Undo | S | Puts the kept files back within 24 hours. |
| 4.4 | Screens | M | Report, typed confirmation with password, progress, result, undo. |

### Phase 5: whole-site flows and release

| # | Package | Size | Notes |
|---|---|---|---|
| 5.1 | Import on a fresh installation | M | A path in the wizard after "Connect to an existing repository": choose a snapshot, restore `wp-content` and the database, keep this installation's `wp-config.php` and the plugin's settings. |
| 5.2 | Recovery guide | S | Every command of [06](06-disaster-recovery.md) is run by the conformance suite against rest-server; the BorgBase column is run once by the owner. |
| 5.3 | Acceptance test | M | The browser test of the first criterion: full restore of the fixture site onto a fresh WordPress, plugin settings intact. |
| 5.4 | Submission package | M | `.wordpress-org/` assets, readme with an answer about direct file access for the reviewers, Plugin Check without errors, version 0.3.0. The build script and the CI job for Plugin Check exist since 0.7. |

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
