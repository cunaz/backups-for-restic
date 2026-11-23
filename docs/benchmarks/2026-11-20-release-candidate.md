# Recovery acceptance for 0.3.0-rc.1

These checks used disposable WordPress installations and repositories on 2026-11-20. Most were
run by hand on a development machine. Each row below names the package it was observed on and
how it can be reproduced. The record was corrected on 2026-11-22 against the CI runs of
`acaecdc`, the commit of the release candidate on `main`: numbers that CI contradicts are
corrected, claims that cannot be checked are marked unverified, and no result was removed.

## Packages

| Name here | Files | Bytes | SHA-256 | Source |
| --- | ---: | ---: | --- | --- |
| P1, called "the final ZIP" below | 242 | 591,899 | `2416473f698ec9ff3a07494a934bce217abe522efec4db18140bf1964e09fde2` | every commit from `5b9dbea` to `eec2a46`; rebuilt from both on 2026-11-22 |
| P0, "the earlier package" | not recorded | not recorded | `f13aee02252dfa5729f117d4575bf45e961a917f23a535bd13d8e754eaaa4fd1` | not recorded; not reproduced |
| P2, the package of `main` | 244 | 593,062 | `66f722c743ad37b520a5c1b1d37960ed3390f0c779c039e4a026c4af95be315c` | `acaecdc`; rebuilt on 2026-11-22 |

P2 differs from P1 in `CHANGELOG.md`, `src/Engine/Snapshot/Reader.php` (45 lines added, 21
removed), `src/Restore/SnapshotBrowser.php`, `src/Support/Rfc3339.php` and two new exception
classes (commits `e4f903e` and `656a873`). The snapshot detail endpoint now distinguishes a
damaged repository tree from an unreadable optional site manifest. The CLI listing preserves
invalid UTF-8 link-target bytes and rejects a timestamp with a trailing newline. On P2 only the
reader, browser and timestamp suites were run by hand, 106 cases with 646 assertions; every
other manual result below applies to P1 or to an earlier package. The CI runs of `acaecdc`
cover P2's source. The hosted tests used two dedicated BorgBase repositories with normal and
append-only access; the other storage fixtures used local private repositories.

"Reproduced by" names one of four kinds of evidence:

- **CI, every change**: a job that runs on every pull request and push to `main` (for
  `conformance.yml`, on those that change its paths).
- **CI, scheduled or on request**: a workflow that runs nightly, weekly or when dispatched.
- **Script**: a script of the repository that someone runs by hand.
- **Manual, one-off**: nothing in the repository reproduces it.

## CI on `acaecdc` (P2's source)

| Workflow and run | Date | Result |
| --- | --- | --- |
| `ci.yml`, push, run 44761331480 | 2026-11-20 | Unit: 2,147 tests on PHP 8.3.37, 8.4.28, 8.5.13 and 8.6.0 with 27,301, 27,365, 27,309 and 27,309 assertions and 4 skips each, the cases that need ext-zstd: no unit job had it. Database: 38 tests and 352 assertions on MySQL 8.4.13; 38 tests, 252 assertions and 8 skips by design on MariaDB 11.4.13. Core mutation: 4,210 mutants, 3,775 killed, 65 timed out, 370 escaped, covered MSI 91.21 %, without ext-zstd. Planner mutation: 468 mutants, 457 killed, 1 syntax error, 10 escaped, 97.86 %. Plugin Check 2.2.0 passed. |
| `conformance.yml`, push run 44761331478 and nightly runs 44898596742 and 45018253602 | 2026-11-20 to 22 | 36 tests, 1,134 or 1,135 assertions, 1 skip (the case that needs ext-zstd); with ext-zstd, 2 tests, 103 assertions, 1 skip. Benchmarks on PHP 8.3.37: chunker 32.6 MB/s (gate 5), a new key in 1.2 s (gate 30), a backup extrapolated to 1.0 minute per GB (gate 15). |
| `integration.yml`, push, run 44761331577 | 2026-11-20 | 7 combinations of 155 tests: 1,085 tests, 9,071 assertions, no skip, with 64 MB of files and 32 MB of database. Memory at 500,000 blobs: backup, check and prune succeeded with peaks of 102.05, 107.48 and 87.87 MiB; at 1,000,000 and 2,000,000 blobs each job ended with `index_too_large`. Browser: 10 passed. |
| `integration.yml`, nightly, run 44905891656 | 2026-11-21 | The same 1,085 tests (13,664 assertions); the three large combinations (7.1.5 on PHP 8.3, 7.0.9 on PHP 8.5, 6.7.12 on PHP 8.3) at the acceptance size, 2,048 MB of files and 300 MB of database. |
| `fuzz.yml`, nightly, runs 44893134543 and 45011522170 | 2026-11-21 and 22 | 26 targets for 120 s each, 747,331,822 and 703,300,980 inputs, no finding. |
| `codeql.yml`, push, run 44761331604 | 2026-11-20 | CodeQL 2.27.5 with javascript-queries 2.5.1: 87 rules, 0 results. |
| `live-servers.yml` | | No run: it runs on Mondays and on request, and the hosted job needs the owner's settings. |

## Scenarios

| Scenario | Observed on | Evidence | Reproduced by |
| --- | --- | --- | --- |
| PHP throughput | P1's source, PHP 8.3.36, locally | With OPcache and JIT disabled under concurrent test load, chunking reached 7.9 MB/s (gate 5), deriving a new key took 2.9 seconds (gate 30), and a 256 MB backup plus 2,000 small files extrapolated to 2.4 minutes per GB (gate 15). | CI, every change and nightly: `bin/bench.php --slow` in "Benchmarks with gates"; see the table above for `acaecdc`. |
| Native integer width | P1, Alpine x86 PHP 8.3.36, WordPress 7.1.5 | With four-byte integers the plugin refused activation before writing settings or scheduling a heartbeat. A simulated host change kept a stored secret intact, showed the administrator notice and registered no workers. Deactivation cleared both scheduled events and uninstall refused to remove the local data. | Manual, one-off. No test runs a 32-bit PHP. |
| PHP 8.6: syntax | P1 | All 236 packaged PHP files passed syntax checking under PHP 8.6.0 with every diagnostic enabled. | CI, every change: the WordPress 7.1.5 on PHP 8.6 job lints every packaged PHP file (238 in P2). |
| PHP 8.6: route registration | P0, and a corrected package whose hash was not recorded | P0 produced a deprecation notice when registering the REST routes. The corrected package registered the same 33 route and method sets without a notice. | CI, every change, in part: `AccessTest` compares the registered routes, and since 2026-11-22 a notice or deprecation from the plugin's files fails the job (`bin/test-site.sh check-logs`). The comparison with P0: manual, one-off. |
| PHP 8.6: secret store without native sodium | P1 | With native sodium disabled, WordPress's bundled compatibility library encrypted and recovered an option through the packaged secret store. | Manual, one-off. |
| PHP 8.6: database tests | P1's source, MySQL 8.4.11, locally | All 38 database tests passed (388 assertions). Unverified: CI runs the database suite on PHP 8.3 only (352 assertions on MySQL 8.4.13 for `acaecdc`), and a local run on PHP 8.3 against MySQL 8.4.11 on 2026-11-22 counted 358 assertions; the number of assertions depends on the server. | Manual for PHP 8.6; CI, every change, for PHP 8.3. |
| Web access to private storage | P1, Apache 2.4.69 and nginx 1.30.6, locally | WordPress 7.1.5 ran the packaged HTTP probe. It permitted plaintext staging under Apache's generated deny rule and nginx's explicit deny. It detected a readable directory with Apache overrides disabled and with nginx serving default static files, refused staging in both cases and removed every temporary probe. | CI, every change: `tests/Hosting/private-directory.sh` in the WordPress 7.1.5 on PHP 8.3 job, with the runner's Apache 2.4.58 and nginx 1.24.0. |
| WordPress compatibility | P1 | Seven combinations of WordPress 6.7.12, 7.0.9 and 7.1.5 passed "1,084 integration cases with 9,011 assertions" and zero skips. Corrected: 1,084 is not a multiple of the 155 tests per combination; CI ran 1,085 tests with 9,071 assertions on `acaecdc`. All three versions ran on PHP 8.3 and 8.5, and WordPress 7.1.5 also on PHP 8.6. The 6.7.12/PHP 8.5 row used 96 MiB and the other six used 128 MiB. Single-site and multisite installations matched all 242 files of P1, which no job compares. The PHP 8.6 run included the full 1 GiB CLI cancellation case. | CI, every change: the `integration.yml` matrix. The comparison of installed files: manual, one-off. |
| PHP 8.3 without zstd | an earlier source than P1, by its counts | "The full unit suite ran 2,133 cases and 28,023 assertions on PHP 8.3.36 in 19 minutes, peaking at 82 MiB; four cases require the native zstd extension and were skipped." Corrected: P1's source has 2,145 unit tests (the two commits after it add two, and CI ran 2,147 with 27,301 assertions and 4 skips on PHP 8.3.37 for `acaecdc`), so the 2,133 cases belong to an earlier source. The additional frame-boundary cases passed independently on PHP 8.3 and 8.6. | CI, every change. |
| restic interoperability | P1's source | The conformance suite passed using restic 0.20.0, 0.19.1, 0.18.1 and 0.14.0. Each version read plugin-written backups, wrote a repository for the plugin to restore and deduplicate, and verified restored bytes. Testing with and without zstd covered both format 2 behaviours. Corrected: the record said 36 tests with 1,480 assertions; CI counts 36 tests with 1,134 assertions and 1 skip, plus 2 tests with 103 assertions and 1 skip with ext-zstd, for `acaecdc`. Where 1,480 came from cannot be established. | CI, every change under its paths and nightly. |
| WordPress index memory | P1, PHP 8.3.36, locally | On WordPress 7.1.5 limited to 128 MiB per worker, backup, check and prune completed at 500,000 blobs with peaks of 100.61, 107.48 and 93.13 MiB. Check traversed 42,754 trees with no errors. At one and two million blobs, each job reported `index_too_large` and released its lock. Independent restic checks read all stored data before and after every size. | CI, every change: the "WordPress memory" matrix; for `acaecdc` 102.05, 107.48 and 87.87 MiB, and `index_too_large` at both larger sizes. |
| Parser fuzzing | a local run; the source was not recorded | The two-minute run for each of the 26 targets processed 253,492,676 inputs without a finding (seed `6961317253957537550`, which is not a CI run ID). | CI, nightly: `fuzz.yml`; on `acaecdc` twice without a finding (table above). |
| Admin JavaScript analysis | P1 | CodeQL 2.27.5 ran "all 89 standard JavaScript queries" from pack 2.5.1 on the shipped admin script, with zero alerts. Corrected: CI's analysis of `acaecdc` with the same CodeQL and pack names 87 rules and 0 results; 89 is unverified. The extracted script matched the ZIP byte for byte, which no job compares. | CI, every push to `main` since 2026-11-22, and weekly. The byte comparison: manual, one-off. |
| Starting a job with full storage | P1, WordPress 7.1.5 on PHP 8.6.0 | WP-CLI ran on a private 512 KiB filesystem. Tests exhausted inodes at directory and lock creation, exhausted blocks when writing the first job document, and remounted the fixture read-only. Failed starts left no job reservation and succeeded after space or write access returned. Failure to append the first log entry preserved an already committed job. With all PHP diagnostics enabled, the libraries bundled with WP-CLI 2.12.0 emitted deprecation notices on PHP 8.5 and 8.6. | CI, every change, in part: `JobDocumentTest` and `BackupWorkFilesTest` limit file sizes, and `CliTest` starts jobs. The full filesystem: manual, one-off. |
| Storage failure during a WordPress heartbeat | P1 | The REST job endpoint returned its structured environment error. The heartbeat preserved the due slot, continued other callbacks and started that backup once storage was writable. When the configured directory was entirely unavailable, other WordPress callbacks still ran after both the heartbeat and the tick. | CI, every change, in part: `ScheduleTest::test_unavailable_storage_leaves_other_cron_callbacks_running`. The rest: manual, one-off. |
| Core mutation testing | P1's source, locally with ext-zstd | Infection 0.35.10 passed the configured 90 % covered mutation score with 90.39 % in one full run. Of 4,285 mutations, PHPUnit killed 3,811 directly (88.94 %), 62 timed out at 60 seconds and 412 survived. There were zero errors, skipped mutations or uncovered generated mutants, which held by construction: Infection then mutated covered lines only. The preceding full run scored 88.81 %. The repeat included new checks for zstd frame boundaries and the import transcript across a resumed SQL table. These counts differ from CI because CI's job had no ext-zstd: 4,210 mutants and 91.21 % for `acaecdc`. A local run on 2026-11-22 reproduced 4,285 covered mutants and 90.39 % with ext-zstd. | CI, every change that can alter the result: the core gate, with ext-zstd and counting uncovered code since 2026-11-22 (development guide §5.6). |
| File-planner mutation testing | P1's source | Infection 0.35.10 measured a covered mutation score of 97.86 % across 468 mutations. PHPUnit killed 455, one was invalid, ten survived and two timed out. CI's run of `acaecdc` scored the same with 457 killed and no timeout. Corrected: these runs, like every mutation run before 2026-11-23, ran the tests under Infection's own file wrapper, which failed tests of unreadable files and of links whatever the mutation ([development guide](../04-development-and-quality.md) §5.6). With PHP's own wrapper the planner gate killed 88.80 % of its covered mutants on 2026-11-23, on a later source with more tests. | CI, every change. |
| WordPress package and dependencies | P1, WordPress 7.1.4 | Official WordPress Plugin Check 2.2.0 inspected the installed ZIP using the repository's CI settings. Its strict JSON report was an empty array and the process returned status 0. All 242 installed files matched the ZIP. Composer and npm reported zero dependency advisories. | Plugin Check: CI, every change (passed for `acaecdc`). The comparison of installed files and the advisories: manual, one-off; no job audits the dependencies, and Composer reported no advisory for the lock file on 2026-11-22. |
| Files and database on a fresh MySQL site | P1, MySQL 8.4.11 | All ten browser tests passed in 8.7 minutes. Two WordPress 7.1.5 installations used separate databases, salts and administrators. Chromium checked the incoming and original posts before and after undo. The destination login, session, site URL and backup settings survived the exchange. Stale review and an incorrect confirmation password were refused. | CI, every change: "Admin page in a browser", on the runner's MySQL; 10 passed for `acaecdc`. |
| Fresh MariaDB site | P1, MariaDB 11.4.13 | All ten Chromium tests passed on WordPress 7.1.5 in 7.6 minutes. Each of the four browser test installations across both database engines matched all 242 files of P1. The database rehearsal showed the manual SQL recovery procedure. File restore, cancellation, undo and downloads remained available. | Manual, one-off: the browser job runs on MySQL only. |
| In-place upgrade | from commit `9bb0902` to P1 | WordPress's plugin updater replaced the installed package. Existing settings, encrypted secrets, kit audit, completed job and log survived. The old snapshot restored its original bytes, a new backup completed, and restic 0.19.1 passed `check --read-data`. Activation with a deliberately newer stored schema preserved the data and displayed an administrator notice. | Manual, one-off. |
| Browser accessibility | P1, axe-core 4.14.0 | axe-core checked the plugin's wizard, dashboard, browsable snapshot and live-change confirmation in Chromium using WCAG A/AA rules through WCAG 2.2. It reported zero violations and zero incomplete checks across these four states. Keyboard activation, focus and recovery after a failed pagination request were also exercised. | Manual, one-off: no test runs axe-core. |
| Interrupted MySQL exchange | P1 | Child processes were stopped around the real table rename. A new worker recognised the committed generation or resumed the pending exchange. Tests checked physical InnoDB table IDs, administrator and configuration preservation, cleanup and undo. | CI, every change: `DatabaseExchangeTest` and `DatabaseImportTest` on MySQL. |
| Database privilege boundaries | P1 | A restricted account rehearsed with database-local grants. The exchange required the InnoDB metadata privilege. A database containing views also needed visibility of those views. Revoking a privilege after review prevented changes to the live generation. | CI, every change: the grant and view cases of `DatabaseExchangeTest` on MySQL. |
| Bedrock path mapping | P1 | Two independent sites placed WordPress in `web/wp`, content in `web/app` and uploads in `web/media`. The 699-file fixture covered 16.6 MB and 12 database tables. Review mapped the content and uploads, preserved live bootstrap files and configuration, and resumed the same undo job following a database outage. | CI, every change, in part: the `SiteLayout` unit tests. The two sites: manual, one-off. |
| Wide folder and large replacement | P1 | A real WordPress fixture with 20,009 review rows and 20,000 unchanged siblings replaced a 128 MiB file and recovered its 96 MiB preimage. Separate WP-CLI workers had 128 MiB each. Preview took 15 ticks, revalidation and application nine, undo review one and undo two. The longest measured tick was 5.10 seconds with a five-second work budget. Peak memory was 61.4 MiB. Both file digests matched. | Manual, one-off. |
| Disk exhaustion before publication | P1 | A real limited filesystem held a sparse 1,292,345,344-byte original and a nine-byte replacement. The preview required 1,292,349,440 additional bytes. Allocating 16 MiB after confirmation caused the worker to stop before live mutation. The original hash, inode and allocated blocks were unchanged. | CI, every change, in part: `FileChangesTest` injects the free space. The real filesystem: manual, one-off. |
| Maintenance and offline undo | P1 | The ordinary public request returned HTTP 503 during a live change. The authorised controller kept working, unrelated cron work stayed out of the maintenance window, and ordinary requests resumed after lease expiry. WP-CLI recovered a site on which a restored plugin broke normal startup. | CI, every change, in part: the browser tests check the 503 and the undo. The WP-CLI recovery: manual, one-off. |
| Recovery kit | P1 | A real terminal and an explicitly requested private file received the kit. A redirected command without `--force` and a subscriber using `--force` were refused. Successful deliveries recorded the time, account and route, with no credentials in the audit. | CI, every change, in part: `CliTest` and `JobControlTest`. The real terminal: manual, one-off. |
| Release archive and credentials | P1 and a checkout of 543 files | Zero matches for the actual BorgBase test HTTP and repository passwords, including URL-encoded and Base64 representations. | Manual, one-off. |
| Diagnostic logs | P1 | The integration suite checks the repository password and HTTP credential against job files and REST responses. A separate comparison of 64 server logs and job records (1,701,260 bytes) found zero matches for the fixture's application, administrator and HTTP credentials in raw, URL-encoded and Base64 forms. | CI, every change, for the integration suite's checks. The comparison of 64 logs: manual, one-off. |
| Hosted BorgBase smoke | P1, by the time of the run; not recorded | "The plugin initialized two repositories and backed up and restored a random 32 KiB file on each, including v2 listings and ranged reads. The smoke test removed its own objects from the normal repository before the large WordPress run. The append-only repository refused deletion. A second backup and restore on that repository kept the first snapshot and produced a second. Independent restic 0.20.0 runs verified the decrypted IDs, read all data in both repositories and verified both append-only snapshots. No locks remained." Unverified: this is not what the repository's hosted smoke does. `tests/Live/run.php --hosted` refuses to initialize a repository and runs no restic CLI, so the run used steps that are not in the repository. | Manual, one-off. The hosted job of `live-servers.yml` (weekly and on request) runs the repository's smoke; it has not run yet. |
| Private TLS repositories | P1 | The operator smoke command backed up and restored against two disposable rest-server instances with separate credentials and private repositories. It checked both ordinary and append-only behaviour, including an append-only repository with prior history. | CI, weekly and on request: the local job of `live-servers.yml` (`tests/Live/run.php --local`); it has not run yet. |
| Release deployment rehearsal | P1 | An isolated SVN repository and disposable signing keys exercised the signed-tag check, the initial release, retries, an upgrade and byte-for-byte export validation. Conflicting tags, downgrades, unsigned tags and unauthorised signing keys were refused. | CI, every change: `tests/Release/test-wordpress-org.sh` in the Plugin Check job, extended on 2026-11-22. |

## PHP unit matrix

On the source of P1, locally, with native CLI OPcache and JIT disabled. The record said each
PHP version ran all 2,145 cases; the conditional cases exercise operation with and without
zstd, and the other configurations cover the skipped branch. The 2,145 cases match P1's source; the
2,133 of the scenario above, also on PHP 8.3.36, came from an earlier source that was not
recorded. CI ran 2,147 tests on every version for `acaecdc`, all without
ext-zstd; since 2026-11-22 it also runs PHP 8.3 with ext-zstd.

| PHP | Native zstd | Assertions | Conditional skips | Peak memory |
| --- | --- | ---: | ---: | ---: |
| 8.3.36 | yes | 27,675 | 3 | 74 MiB |
| 8.4.27 | no | 27,576 | 4 | 78 MiB |
| 8.5.12 | yes | 27,648 | 3 | 78 MiB |
| 8.6.0 | no | 27,686 | 4 | 78 MiB |

Two earlier runs exceeded the fuzzer’s one-second per-input limit in different
file-backed tests. Both deterministic cases passed separately. The successful full
repeats retained the original time limit and input set. PHP 8.6 ran at normal process
priority. PHP 8.3 used a private temporary filesystem.

## Large WordPress backups

Manual, one-off runs on P1. CI repeats the local part every night at the same sizes against a
local rest-server: for `acaecdc` on 2026-11-21, three combinations with 2,048 MB of files and
300 MB of database passed (table above). Nothing in the repository repeats the run on BorgBase;
the procedure is in [`tests/Live/README.md`](../../tests/Live/README.md#full-wordpress-backup-on-borgbase).

The final ZIP ran on WordPress 7.1.5, PHP 8.3.36 and MariaDB 11.4.13. Both sites used
ordinary WordPress cron with a 128 MiB memory limit, a 30-second execution limit and
`set_time_limit` disabled. Each backed up the same 256 synthetic upload files totaling
2,147,483,648 bytes. Both jobs finished without warnings.

| Storage | Test rows and SQL payload | Cron backup | Full acceptance run |
| --- | --- | --- | --- |
| Local TLS rest-server | 308,000 rows, 315,392,000 bytes | 20 ticks, 6 min 55 s | 11 min 26 s |
| Hosted BorgBase, normal access | 307,200 rows, 314,572,800 bytes | 24 ticks, 8 min 28 s | 14 min 18 s |

In both runs restic 0.20.0 passed `check --read-data`, restored the uploads with
matching SHA-256 digests and metadata, and supplied SQL for an independent MariaDB
import. The BorgBase run used the WordPress wizard, verified the TLS certificate and
authenticated repository ID, and restored the snapshot identified by the WordPress
job. Its 14 SQL files totaled 322,264,694 bytes. Source and restored
`CHECKSUM TABLE … EXTENDED` values matched for `wp_postmeta` (2494083864),
`wp_posts` (2594047603) and `wp_users` (4213990131). The recovered SQL, job files
and WordPress diagnostics contained zero matches for either password or the HTTP
Basic credentials across 20 files and 322,602,126 bytes. The scan covered raw,
URL-encoded and Base64 representations.

An earlier local run on WordPress 7.1.4 completed in 46 minutes. The first local
7.1.5 run exhausted the shared test disk while restic restored uploads.
The successful local result above used the final ZIP and a fresh restore target.
At the end of hosted acceptance, the normal BorgBase repository held one verified
large snapshot and the append-only repository held its two small smoke snapshots.
Both repositories had zero remaining locks. [Hosted acceptance procedure](../../tests/Live/README.md#full-wordpress-backup-on-borgbase)

The live database exchange requires an eligible MySQL installation. MariaDB, mismatched
WordPress prefixes, unresolved table dependencies and an external persistent object cache
use the manual recovery procedure. WordPress core, configuration and other startup files
also require offline recovery. See [the recovery guide](../06-disaster-recovery.md) and
[the database compatibility requirements](../adr/0043-atomic-mysql-database-exchange.md).

WordPress.org directory approval and the owner’s signed stable deployment are pending. The local
release rehearsal uses the same publishing scripts. [Operator repository smoke](../../tests/Live/README.md).
