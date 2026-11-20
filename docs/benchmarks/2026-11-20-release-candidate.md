# Recovery acceptance for 0.3.0-rc.1

These checks used disposable WordPress installations and repositories on 2026-11-20.
The final ZIP contains 242 files and is 590,997 bytes. Two builds reproduced SHA-256
`7d17690fd78fddb4c1e193e1980a3f3334124b6348ff490ab75ce58d6f6df9ca`. The final
changelog edit left every other packaged file byte-identical. The browser
runs installed candidate packages into the receiving site. The storage tests used local
private repositories.

| Scenario | Evidence |
| --- | --- |
| PHP throughput | On PHP 8.3.36 with OPcache and JIT disabled under concurrent test load, chunking reached 7.9 MB/s (gate 5), deriving a new key took 2.9 seconds (gate 30), and a 256 MB backup plus 2,000 small files extrapolated to 2.4 minutes per GB (gate 15). |
| Native integer width | WordPress 7.1.5 on Alpine x86 PHP 8.3.36 with four-byte integers refused activation from the final ZIP before writing settings or scheduling a heartbeat. A simulated host change kept a stored secret intact, showed the administrator notice and registered no workers. Deactivation cleared both scheduled events and uninstall refused to remove the local data. |
| Web access to private storage | WordPress 7.1.5 ran the packaged HTTP probe against Apache 2.4.69 and nginx 1.30.6. It permitted plaintext staging under Apache's generated deny rule and nginx's explicit deny. It detected a readable directory with Apache overrides disabled and with nginx serving default static files, refused staging in both cases and removed every temporary probe. |
| File-planner mutation testing | Infection 0.35.10 measured a covered mutation score of 97.86% across 468 mutations. PHPUnit killed 455, one was invalid, ten survived and two timed out. |
| WordPress package and dependencies | Official WordPress Plugin Check 2.2.0 inspected the installed ZIP under WordPress 7.1.4, using the repository's CI settings. Its strict JSON report contained zero errors and warnings. All 242 files matched. Composer and npm reported zero dependency advisories. |
| Backup using shared-hosting limits | WordPress 7.1.4 and PHP 8.3.36 ran ordinary cron requests with a 30-second execution limit, a 128 MiB memory limit and `set_time_limit` disabled. A fixture with 2 GiB of uploads and a 300 MiB MariaDB database finished in 46 minutes. Independent restic verification and restore matched the upload bytes and restored table checksums. |
| Files and database on a fresh MySQL site | Two packaged WordPress 7.1.5 installations used separate databases, salts and administrators on MySQL 8.4.11. Chromium checked the incoming and original posts before and after undo. The destination login, session, site URL and backup settings survived the exchange. Stale review and an incorrect confirmation password were refused. |
| Fresh MariaDB site | All ten packaged Chromium tests passed on WordPress 7.1.5 and MariaDB 11.4.13. The database rehearsal showed the manual SQL recovery procedure. File restore, cancellation, undo and downloads remained available. |
| In-place upgrade | WordPress's plugin updater replaced the installed package from commit `9bb0902` with the final ZIP. Existing settings, encrypted secrets, kit audit, completed job and log survived. The old snapshot restored its original bytes, a new backup completed, and restic 0.19.1 passed `check --read-data`. Activation with a deliberately newer stored schema preserved the data and displayed an administrator notice. |
| Browser accessibility | axe-core 4.14.0 checked the plugin's wizard, dashboard, browsable snapshot and live-change confirmation in Chromium using WCAG A/AA rules through WCAG 2.2. It reported zero violations and zero incomplete checks across these four states. Keyboard activation, focus and recovery after a failed pagination request were also exercised. |
| Interrupted MySQL exchange | Child processes were stopped around the real table rename. A new worker recognised the committed generation or resumed the pending exchange. Tests checked physical InnoDB table IDs, administrator and configuration preservation, cleanup and undo. |
| Database privilege boundaries | A restricted account rehearsed with database-local grants. The exchange required the InnoDB metadata privilege. A database containing views also needed visibility of those views. Revoking a privilege after review prevented changes to the live generation. |
| Bedrock path mapping | Two independent sites placed WordPress in `web/wp`, content in `web/app` and uploads in `web/media`. The 699-file fixture covered 16.6 MB and 12 database tables. Review mapped the content and uploads, preserved live bootstrap files and configuration, and resumed the same undo job following a database outage. |
| Wide folder and large replacement | A real WordPress fixture with 20,009 review rows and 20,000 unchanged siblings replaced a 128 MiB file and recovered its 96 MiB preimage. Separate WP-CLI workers had 128 MiB each. Preview took 15 ticks, revalidation and application nine, undo review one and undo two. The longest measured tick was 5.10 seconds with a five-second work budget. Peak memory was 61.4 MiB. Both file digests matched. |
| Disk exhaustion before publication | A real limited filesystem held a sparse 1,292,345,344-byte original and a nine-byte replacement. The preview required 1,292,349,440 additional bytes. Allocating 16 MiB after confirmation caused the worker to stop before live mutation. The original hash, inode and allocated blocks were unchanged. |
| Maintenance and offline undo | The ordinary public request returned HTTP 503 during a live change. The authorised controller kept working, unrelated cron work stayed out of the maintenance window, and ordinary requests resumed after lease expiry. WP-CLI recovered a site on which a restored plugin broke normal startup. |
| Recovery kit | A real terminal and an explicitly requested private file received the kit. A redirected command without `--force` and a subscriber using `--force` were refused. Successful deliveries recorded the time, account and route, with no credentials in the audit. |
| Private TLS repositories | The operator smoke command backed up and restored against two disposable rest-server instances with separate credentials and private repositories. It checked both ordinary and append-only behaviour, including an append-only repository with prior history. |
| Release deployment rehearsal | An isolated SVN repository and disposable signing keys exercised the signed-tag check, the initial release, retries, an upgrade and byte-for-byte export validation. Conflicting tags, downgrades, unsigned tags and unauthorised signing keys were refused. |

The live database exchange requires an eligible MySQL installation. MariaDB, mismatched
WordPress prefixes, unresolved table dependencies and an external persistent object cache
use the manual recovery procedure. WordPress core, configuration and other startup files
also require offline recovery. See [the recovery guide](../06-disaster-recovery.md) and
[the database compatibility requirements](../adr/0043-atomic-mysql-database-exchange.md).

The owner runs the hosted repository test against dedicated credentials before release,
approves the WordPress.org listing and authorises the signed stable deployment. The local
release rehearsal uses the same publishing scripts. [Operator repository smoke](../../tests/Live/README.md).
