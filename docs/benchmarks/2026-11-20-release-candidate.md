# Recovery acceptance for 0.3.0-rc.1

These checks used disposable WordPress installations and repositories on 2026-11-20.
The ZIP used for the hosted acceptance contains 242 files and is 591,899 bytes. Builds from the working
tree and a clean checkout reproduced SHA-256
`2416473f698ec9ff3a07494a934bce217abe522efec4db18140bf1964e09fde2`.
The earlier package used to compare PHP 8.6 route registration had SHA-256
`f13aee02252dfa5729f117d4575bf45e961a917f23a535bd13d8e754eaaa4fd1`. The full
WordPress matrix below used the final ZIP, including its fixes for job creation,
filesystem write failures and WP-Cron callbacks.
The hosted tests used two dedicated BorgBase repositories with normal and append-only
access. The other storage fixtures used local private repositories.

### Package after merge review

The archive prepared for main contains 244 files and is 593,062 bytes, with SHA-256
`66f722c743ad37b520a5c1b1d37960ed3390f0c779c039e4a026c4af95be315c`.
The snapshot detail endpoint now distinguishes a damaged repository tree from an
unreadable optional site manifest. The CLI listing preserves invalid UTF-8 link-target
bytes and rejects a timestamp with a trailing newline. The reader, browser and timestamp
suites passed 106 cases with 646 assertions. The hosted transfer and restore measurements
below apply to the preceding archive, identified above.

| Scenario | Evidence |
| --- | --- |
| PHP throughput | On PHP 8.3.36 with OPcache and JIT disabled under concurrent test load, chunking reached 7.9 MB/s (gate 5), deriving a new key took 2.9 seconds (gate 30), and a 256 MB backup plus 2,000 small files extrapolated to 2.4 minutes per GB (gate 15). |
| Native integer width | WordPress 7.1.5 on Alpine x86 PHP 8.3.36 with four-byte integers refused activation from the final ZIP before writing settings or scheduling a heartbeat. A simulated host change kept a stored secret intact, showed the administrator notice and registered no workers. Deactivation cleared both scheduled events and uninstall refused to remove the local data. |
| PHP 8.6 | All 236 packaged PHP files passed syntax checking under PHP 8.6.0 with every diagnostic enabled. The previous candidate produced a deprecation notice when registering the REST routes. The corrected package registered the same 33 route and method sets without a notice. With native sodium disabled, WordPress's bundled compatibility library encrypted and recovered an option through the packaged secret store. All 38 database tests passed on MySQL 8.4.11 (388 assertions). |
| Web access to private storage | WordPress 7.1.5 ran the packaged HTTP probe against Apache 2.4.69 and nginx 1.30.6. It permitted plaintext staging under Apache's generated deny rule and nginx's explicit deny. It detected a readable directory with Apache overrides disabled and with nginx serving default static files, refused staging in both cases and removed every temporary probe. |
| WordPress compatibility | Seven combinations of WordPress 6.7.12, 7.0.9 and 7.1.5 passed 1,084 integration cases with 9,011 assertions and zero skips. All three versions ran on PHP 8.3 and 8.5. WordPress 7.1.5 also ran on PHP 8.6. The 6.7.12/PHP 8.5 row used 96 MiB and the other six used 128 MiB. Single-site and multisite installations matched all 242 files of the final ZIP. The PHP 8.6 run included the full 1 GiB CLI cancellation case. |
| PHP 8.3 without zstd | On the final ZIP the full unit suite ran 2,133 cases and 28,023 assertions on PHP 8.3.36 in 19 minutes, peaking at 82 MiB. Four cases require the native zstd extension and were skipped. The additional frame-boundary cases passed independently on PHP 8.3 and 8.6. |
| restic interoperability | The conformance suite passed with 36 tests and 1,480 assertions using restic 0.20.0, 0.19.1, 0.18.1 and 0.14.0. Each version read plugin-written backups, wrote a repository for the plugin to restore and deduplicate, and verified restored bytes. Testing with and without zstd covered both format 2 behaviours. |
| WordPress index memory | On WordPress 7.1.5 with PHP 8.3.36 limited to 128 MiB per worker, backup, check and prune completed at 500,000 blobs. The respective peaks were 100.61, 107.48 and 93.13 MiB. Check traversed 42,754 trees with no errors. At one and two million blobs, each job reported `index_too_large` and released its lock. Independent restic checks read all stored data before and after every size. |
| Parser fuzzing | The two-minute run for each of the 26 targets processed 253,492,676 inputs without a finding (seed `6961317253957537550`). |
| Admin JavaScript analysis | CodeQL 2.27.5 ran all 89 standard JavaScript queries from pack 2.5.1 on the shipped admin script. The SARIF report recorded successful execution and zero alerts. The extracted script matched the ZIP byte for byte. |
| Starting a job with full storage | WordPress 7.1.5 on PHP 8.6.0 ran WP-CLI on a private 512 KiB filesystem. Tests exhausted inodes at directory and lock creation, exhausted blocks when writing the first job document, and remounted the fixture read-only. Failed starts left no job reservation and succeeded after space or write access returned. Failure to append the first log entry preserved an already committed job. With all PHP diagnostics enabled, the libraries bundled with WP-CLI 2.12.0 emitted deprecation notices on PHP 8.5 and 8.6. |
| Storage failure during a WordPress heartbeat | The REST job endpoint returned its structured environment error. The heartbeat preserved the due slot, continued other callbacks and started that backup once storage was writable. When the configured directory was entirely unavailable, other WordPress callbacks still ran after both the heartbeat and the tick. |
| Core mutation testing | Infection 0.35.10 passed the configured 90% covered mutation score on the final ZIP's source with 90.39% in one full run. Of 4,285 mutations, PHPUnit killed 3,811 directly (88.94%), 62 timed out at 60 seconds and 412 survived. There were zero errors, skipped mutations or uncovered generated mutants. These counts apply to the configured Crypto, Format, Forget and database restore tests. The preceding full run scored 88.81%. The repeat included new checks for zstd frame boundaries and the import transcript across a resumed SQL table. |
| File-planner mutation testing | Infection 0.35.10 measured a covered mutation score of 97.86% across 468 mutations. PHPUnit killed 455, one was invalid, ten survived and two timed out. |
| WordPress package and dependencies | Official WordPress Plugin Check 2.2.0 inspected the installed ZIP under WordPress 7.1.4, using the repository's CI settings. Its strict JSON report for the final ZIP was an empty array and the process returned status 0. All 242 installed files matched the ZIP. Composer and npm reported zero dependency advisories. |
| Files and database on a fresh MySQL site | All ten browser tests passed with the final ZIP in 8.7 minutes. Two WordPress 7.1.5 installations used separate databases, salts and administrators on MySQL 8.4.11. Chromium checked the incoming and original posts before and after undo. The destination login, session, site URL and backup settings survived the exchange. Stale review and an incorrect confirmation password were refused. |
| Fresh MariaDB site | All ten Chromium tests passed on WordPress 7.1.5 and MariaDB 11.4.13 in 7.6 minutes. Each of the four browser test installations across both database engines matched all 242 files from the final ZIP. The database rehearsal showed the manual SQL recovery procedure. File restore, cancellation, undo and downloads remained available. |
| In-place upgrade | WordPress's plugin updater replaced the installed package from commit `9bb0902` with the final ZIP. Existing settings, encrypted secrets, kit audit, completed job and log survived. The old snapshot restored its original bytes, a new backup completed, and restic 0.19.1 passed `check --read-data`. Activation with a deliberately newer stored schema preserved the data and displayed an administrator notice. |
| Browser accessibility | axe-core 4.14.0 checked the plugin's wizard, dashboard, browsable snapshot and live-change confirmation in Chromium using WCAG A/AA rules through WCAG 2.2. It reported zero violations and zero incomplete checks across these four states. Keyboard activation, focus and recovery after a failed pagination request were also exercised. |
| Interrupted MySQL exchange | Child processes were stopped around the real table rename. A new worker recognised the committed generation or resumed the pending exchange. Tests checked physical InnoDB table IDs, administrator and configuration preservation, cleanup and undo. |
| Database privilege boundaries | A restricted account rehearsed with database-local grants. The exchange required the InnoDB metadata privilege. A database containing views also needed visibility of those views. Revoking a privilege after review prevented changes to the live generation. |
| Bedrock path mapping | Two independent sites placed WordPress in `web/wp`, content in `web/app` and uploads in `web/media`. The 699-file fixture covered 16.6 MB and 12 database tables. Review mapped the content and uploads, preserved live bootstrap files and configuration, and resumed the same undo job following a database outage. |
| Wide folder and large replacement | A real WordPress fixture with 20,009 review rows and 20,000 unchanged siblings replaced a 128 MiB file and recovered its 96 MiB preimage. Separate WP-CLI workers had 128 MiB each. Preview took 15 ticks, revalidation and application nine, undo review one and undo two. The longest measured tick was 5.10 seconds with a five-second work budget. Peak memory was 61.4 MiB. Both file digests matched. |
| Disk exhaustion before publication | A real limited filesystem held a sparse 1,292,345,344-byte original and a nine-byte replacement. The preview required 1,292,349,440 additional bytes. Allocating 16 MiB after confirmation caused the worker to stop before live mutation. The original hash, inode and allocated blocks were unchanged. |
| Maintenance and offline undo | The ordinary public request returned HTTP 503 during a live change. The authorised controller kept working, unrelated cron work stayed out of the maintenance window, and ordinary requests resumed after lease expiry. WP-CLI recovered a site on which a restored plugin broke normal startup. |
| Recovery kit | A real terminal and an explicitly requested private file received the kit. A redirected command without `--force` and a subscriber using `--force` were refused. Successful deliveries recorded the time, account and route, with no credentials in the audit. |
| Release archive and credentials | The final archive (242 files) and the tracked checkout (543 files) contained zero matches for the actual BorgBase test HTTP and repository passwords, including URL-encoded and Base64 representations. |
| Diagnostic logs | The integration suite checks the repository password and HTTP credential against job files and REST responses. A separate comparison of 64 server logs and job records (1,701,260 bytes) found zero matches for the fixture's application, administrator and HTTP credentials in raw, URL-encoded and Base64 forms. |
| Hosted BorgBase smoke | The plugin initialized two repositories and backed up and restored a random 32 KiB file on each, including v2 listings and ranged reads. The smoke test removed its own objects from the normal repository before the large WordPress run. The append-only repository refused deletion. A second backup and restore on that repository kept the first snapshot and produced a second. Independent restic 0.20.0 runs verified the decrypted IDs, read all data in both repositories and verified both append-only snapshots. No locks remained. |
| Private TLS repositories | The operator smoke command backed up and restored against two disposable rest-server instances with separate credentials and private repositories. It checked both ordinary and append-only behaviour, including an append-only repository with prior history. |
| Release deployment rehearsal | An isolated SVN repository and disposable signing keys exercised the signed-tag check, the initial release, retries, an upgrade and byte-for-byte export validation. Conflicting tags, downgrades, unsigned tags and unauthorised signing keys were refused. |

## PHP unit matrix

The unit suite ran against the source matching the final ZIP with native CLI OPcache
and JIT disabled. Each PHP version ran all 2,145 cases. The conditional cases exercise
operation with and without zstd. The other configurations cover the skipped branch.

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
