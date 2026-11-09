# 06 · Disaster recovery

This guide is written for the worst day. It assumes nothing about the state of the
WordPress site. Every procedure uses only the **recovery kit** and the public `restic`
program on any computer. The in-plugin restore flows in [`02-architecture.md`](02-architecture.md)
§9 are conveniences built on top of exactly these steps. Repositories created by the plugin
use restic repository format version 1, so any restic release since 0.9 works; use a current
one.

## The recovery kit

Produced by the setup wizard, stored outside the server (password manager, printed in a
safe):

```
Backups for restic — recovery kit       created 2026-11-09, plugin 0.1.0, repository format 1
RESTIC_REPOSITORY=rest:https://abc123.repo.borgbase.com/
RESTIC_REST_USERNAME=abc123
RESTIC_REST_PASSWORD=<borgbase rest password>
RESTIC_PASSWORD=<repository password — nobody can recover this for you>
Site: https://example.com   Snapshot host name: example.com   Tags: backups-for-restic, site:3f9a1c2b7d4e
Restore recipe: export the four variables above, then
  restic snapshots
  restic restore latest --target /tmp/restore
```

For a rest-server with its own certificate authority, the kit also contains the CA
certificate: save it as `ca.pem` and add `--cacert ca.pem` to every restic command, as the
kit's recipe does.

| Lost item | Recoverable? | How |
| --- | --- | --- |
| REST username/password | yes | regenerate in the BorgBase control panel, or add a new `.htpasswd` entry on your rest-server |
| Repository URL | yes | shown in the BorgBase control panel; on rest-server it is your server URL plus the repository path |
| Repository password | **no** | restic's encryption is end-to-end; neither BorgBase nor the plugin author can help. If the WordPress site still works, the plugin shows the kit again after re-authentication |

## Scenario A — the site is damaged, WordPress and the plugin still work

Use **Backups → Restore** in the admin. WP-CLI, where available:

```bash
wp backups-for-restic snapshots
```

```bash
wp backups-for-restic restore <snapshot-id> --to=staging --include=/var/www/html/wp-content/uploads --wait
```

```bash
wp backups-for-restic apply <restore-job-id> --yes
```

```bash
wp backups-for-restic restore <snapshot-id> --db --wait
```

The plugin keeps its own settings across a database restore, so backups continue afterwards.

## Scenario B — the server is gone

1. **Provision** a new server or hosting account with PHP, a web server and MariaDB/MySQL.
   On any computer with restic installed (your laptop is fine), **export the kit** in a shell:

   ```bash
   export RESTIC_REPOSITORY='rest:https://abc123.repo.borgbase.com/'
   export RESTIC_REST_USERNAME='abc123'
   export RESTIC_REST_PASSWORD='…'
   export RESTIC_PASSWORD='…'
   ```

   For a self-hosted rest-server with a self-signed certificate, add `--cacert /path/to/ca.pem`
   to every restic command below.

2. **Find the snapshot**:

   ```bash
   restic snapshots --host example.com --tag backups-for-restic
   ```

3. **Restore everything to a scratch directory**:

   ```bash
   restic restore <snapshot-id> --target /srv/restore --verify
   ```

   Inside `/srv/restore` you find the original absolute paths, for example
   `/srv/restore/var/www/html/…`, plus the virtual spool directory
   `…/wp-content/backups-for-restic/spool/` containing `manifest.json` and the
   `database/` directory with one SQL file per table.

4. **Put the files in place**: upload the WordPress root (and `wp-content` or uploads if they
   lived elsewhere, see `manifest.json → paths`) to the new web root; fix ownership.
5. **Create the database** and import the dump files in name order (the header file first,
   views last):

   ```bash
   cat /srv/restore/…/spool/database/*.sql | mariadb -u <user> -p <database>
   ```

   Every file ends with a trailer line `-- end table rows=… sha256=…`; a file without it was
   truncated and that snapshot should not be used for the database.

6. **Adjust `wp-config.php`** for the new database credentials. If the domain changed, run
   `wp search-replace` afterwards.
7. **Reinstall the plugin and reconnect**: activate it, choose "Connect to an existing
   repository" in the wizard, paste the kit's values. The plugin unlocks the repository (this
   can take a minute on shared hosting), lists the old snapshots and continues the series.

Alternative to steps 4–7: install WordPress and the plugin first, then use the wizard's
"fresh install import" path, which performs the file and database restore through the UI.

## Scenario C — a single file or folder

```bash
restic ls <snapshot-id> /var/www/html/wp-content/uploads/2026/11/
```

```bash
restic dump <snapshot-id> /var/www/html/wp-content/uploads/2026/11/photo.jpg > photo.jpg
```

```bash
restic dump -a zip <snapshot-id> /var/www/html/wp-content/uploads/2026/11 > nov.zip
```

## Scenario D — only the database

```bash
restic dump -a tar <snapshot-id> /var/www/html/wp-content/backups-for-restic/spool/database > database.tar
```

Extract, check the trailer lines, import in name order.

## Scenario E — "secrets cannot be decrypted"

The WordPress salts in `wp-config.php` changed or the database moved to a site with
different salts. Nothing is lost: open **Backups → Settings → Repository** and re-enter the
REST password and the repository password from the kit (or define them as
`BACKUPS_FOR_RESTIC_REST_PASSWORD` / `BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD` constants). The plugin
derives and caches the master key again.

## Scenario F — the repository is locked

A job was killed hard. The plugin removes its own stale locks (older than 30 minutes) before
the next job. If a lock from another machine blocks the plugin and you are sure no restic
process is running there, remove it from any computer:

```bash
restic unlock
```

Lock removal works even on append-only repositories.

## Scenario G — reclaiming space (full prune)

The plugin deletes snapshots according to the retention policy and removes packs that no
snapshot references. Packs that still contain some live data are not repacked by the plugin.
Run a full prune from any computer when the dashboard shows a large reclaimable amount:

```bash
restic forget --host example.com --tag backups-for-restic --keep-daily 7 --keep-weekly 4 --keep-monthly 6 --keep-within 2d --prune
```

With append-only enabled, temporarily disable it first (BorgBase: repository settings;
rest-server: restart without `--append-only`) and re-enable it afterwards. The plugin notices
both changes, but it never starts deleting on its own after append-only was turned off. **Do not** run
`restic migrate upgrade_repo_v2` or `prune --repack-uncompressed` while the plugin uses the
repository: after the upgrade, restic compresses every index and snapshot file it writes,
even with `--compression off`, and the plugin cannot read compressed data unless the server
has the `zstd` PHP extension ([ADR 0019](adr/0019-repository-format-and-chunker.md)).

## Scenario H — the first backup seems to take forever

On shared hosting the first backup of a large site runs in many short slices and can take
hours. Progress continues while the dashboard tab is open, with every visit to the site, and
fastest with a control-panel cron that fetches `wp-cron.php` every minute. The dashboard
shows the measured rate and an estimate. Later backups touch only changed files and finish in
minutes.

## Scenario I — the check found damage

The dashboard, Site Health and an e-mail report that the repository check found errors. Some
snapshots may then not restore completely. **Do not delete anything and do not prune** until
the cause is clear.

1. Stop every automatic deletion: set **Backups → Settings → Old snapshots → Who removes old
   snapshots** to *Another computer*, so that this site runs no forget or prune, and pause
   maintenance jobs on any other computer that uses the repository. Backups may go on.
2. On any computer with restic and the recovery kit, check everything:

   ```bash
   restic check --read-data
   ```

3. Follow restic's [troubleshooting guide](https://restic.readthedocs.io/en/stable/077_troubleshooting.html).
   In short: `restic repair index` rebuilds index files that are damaged or miss packs;
   `restic repair snapshots --forget` replaces snapshots that refer to lost data with copies
   without the damaged files. Repairs delete files, so on an append-only server they need
   deletions allowed, as in Scenario G.
4. Start **Back up now**: the next backup uploads any data that went missing again.
5. Run `restic check --read-data` again. Only when it finds no errors is the repository
   healthy; the plugin's check reads a sample of the data (2 % by default) and cannot prove
   that. Then start **Check now** on the dashboard to clear the warning, and set retention
   back to *This site* if the server allows deletions.

## Restore drills

Quarterly, restore the latest snapshot to a scratch directory on any computer and open the
manifest; once a year, rebuild a staging copy of the site from it. The dashboard reminds you
(configurable); an automated drill job is on the backlog.
