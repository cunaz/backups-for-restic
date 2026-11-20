# Recovery acceptance for 0.3.0-rc.1

These checks used disposable WordPress installations and repositories on 2026-11-20.
The browser runs installed the release ZIP into the receiving site. No test wrote to a
production WordPress site or an operator's storage account.

| Scenario | Evidence |
| --- | --- |
| Backup using shared-hosting limits | WordPress 7.1.4 and PHP 8.3.36 ran ordinary cron requests with a 30-second execution limit, a 128 MiB memory limit and `set_time_limit` disabled. A fixture with 2 GiB of uploads and a 300 MiB MariaDB database finished in 46 minutes. Independent restic verification and restore matched the upload bytes and restored table checksums. |
| Files and database on a fresh MySQL site | Two packaged WordPress 7.1.5 installations used separate databases, salts and administrators on MySQL 8.4.11. Chromium checked the incoming and original posts before and after undo. The destination login, session, site URL and backup settings survived the exchange. Stale review and an incorrect confirmation password were refused. |
| Fresh MariaDB site | All ten packaged Chromium tests passed on WordPress 7.1.5 and MariaDB 11.4.13. The database rehearsal showed the manual SQL recovery procedure. File restore, cancellation, undo and downloads remained available. |
| Interrupted MySQL exchange | Child processes were stopped around the real table rename. A new worker recognised the committed generation or resumed the pending exchange. Tests checked physical InnoDB table IDs, administrator and configuration preservation, cleanup and undo. |
| Database privilege boundaries | A restricted account rehearsed with database-local grants. The exchange required the InnoDB metadata privilege. A database containing views also needed visibility of those views. Revoking a privilege after review prevented changes to the live generation. |
| Bedrock path mapping | Two independent sites placed WordPress in `web/wp`, content in `web/app` and uploads in `web/media`. The 699-file fixture covered 16.6 MB and 12 database tables. Review mapped the content and uploads, preserved live bootstrap files and configuration, and resumed the same undo job following a database outage. |
| Wide folder and large replacement | A real WordPress fixture with 20,009 review rows and 20,000 unchanged siblings replaced a 128 MiB file and recovered its 96 MiB preimage. Separate WP-CLI workers had 128 MiB each. Preview took 15 ticks, revalidation and application nine, undo review one and undo two. The longest measured tick was 5.10 seconds with a five-second work budget. Peak memory was 61.4 MiB. Both file digests matched. |
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
