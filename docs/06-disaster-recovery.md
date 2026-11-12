# 06 · Disaster recovery

This guide is written for the worst day. It assumes nothing about the state of the
WordPress site. Every procedure uses only the **recovery kit** and the public `restic`
program on any computer. The plugin itself cannot restore yet: restore from the admin area
is planned for Milestone 3 ([`02-architecture.md`](02-architecture.md) §9) and will be built
on exactly these steps. Repositories created by the plugin use restic repository format
version 1, which every restic release since 0.9 reads. The commands of the kit and of this
guide need restic 0.16.1 or newer, because older releases do not read
`RESTIC_REST_USERNAME` and `RESTIC_REST_PASSWORD`; use a current one.

## The recovery kit

Produced by the setup wizard, stored outside the server (password manager, printed in a
safe). The examples in this guide use a rest-server of your own at `backup.example.net`:

```
Backup recovery kit
========================================
Site: https://example.com
Created: 2026-11-09 14:05 UTC
Plugin version: 0.1.0

Repository: rest:https://backup.example.net:8000/site/
REST username: site
REST password: <REST password>
Repository password: <repository password>
Repository format: 1

Restore without WordPress, on any computer with restic 0.16.1 or newer:
  export RESTIC_REPOSITORY='rest:https://backup.example.net:8000/site/'
  export RESTIC_REST_USERNAME='site'
  export RESTIC_REST_PASSWORD='<REST password>'
  export RESTIC_PASSWORD='<repository password>'
  restic snapshots --host example.com
  restic restore latest --host example.com --target ./restore

The database is in the snapshot under the plugin's spool/database folder. Import its .sql files in name order, for example: cat *.sql | mariadb <database>
Keep this kit away from the website, for example in a password manager.
```

For a rest-server with its own certificate authority, the kit also contains the CA
certificate: save it as `ca.pem` and add `--cacert ca.pem` to every restic command, as the
kit's recipe does. The kit carries the certificate of the saved server; one that was only
used for a connection test is not in it until that server is saved.

| Lost item | Recoverable? | How |
| --- | --- | --- |
| REST username/password | yes | add a new `.htpasswd` entry on your rest-server; a hosted service such as BorgBase issues new ones in its control panel |
| Repository URL | yes | on rest-server it is your server URL plus the repository path; a hosted service shows it in its control panel |
| Repository password | **no** | restic's encryption is end-to-end; neither the storage provider nor the plugin author can help. If the WordPress site still works, the plugin shows the kit again after re-authentication |

## Scenario A — the site is damaged, WordPress and the plugin still work

The plugin lists the snapshots, under **Backups → Snapshots** and with WP-CLI:

```bash
wp backups-for-restic snapshots
```

It cannot restore them yet. Restore single files as in Scenario C, the database as in
Scenario D, or everything as in Scenario B from step 2, with restic on any computer. Restore
from the admin area and with WP-CLI (to a staging directory first, then applied to the live
site) is planned for Milestone 3.

## Scenario B — the server is gone

1. **Provision** a new server or hosting account with PHP, a web server and MariaDB/MySQL.
   On any computer with restic installed (your laptop is fine), **export the kit** in a shell:

   ```bash
   export RESTIC_REPOSITORY='rest:https://backup.example.net:8000/site/'
   export RESTIC_REST_USERNAME='site'
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
   lived elsewhere; `manifest.json` names them as `abspath`, `content`, `uploads` and
   `roots`) to the new web root; fix ownership. Leave out the restored `spool` directory: it
   holds the database dump as plain SQL, which must not lie in a web root.
5. **Create the database** and import the dump files in name order (the header file first,
   views last):

   ```bash
   cat /srv/restore/…/spool/database/*.sql | mariadb -u <user> -p <database>
   ```

   Every table's file (`010-<table>.sql`) ends with a trailer line
   `-- end table rows=… bytes=…`; a file without it was truncated and that snapshot should
   not be used for the database.

6. **Adjust `wp-config.php`** for the new database credentials. If the domain changed, run
   `wp search-replace` afterwards.
7. **Check the plugin**: the restored files contain the plugin, and the imported database
   contains its settings and its encrypted passwords. With the security keys of the restored
   `wp-config.php` it needs no new setup: open **Backups**, start **Back up now**, and the
   series continues. Two things can be missing:

   - The CA certificate of a server with its own certificate authority. The plugin keeps it
     in its private directory, which is not part of the backup, and the settings page has no
     field for it. Save the certificate from the kit as `ca.pem` in that directory (by
     default `wp-content/backups-for-restic/`).
   - The security keys. If jobs fail with "The stored passwords can no longer be decrypted.",
     `wp-config.php` has other keys than before: see Scenario E.

   On a WordPress whose database does not come from the snapshot, activate the plugin and
   enter the kit's repository address, REST username and REST password in the setup wizard.
   The connection test finds the existing repository, and the wizard then asks for the
   repository password instead of creating a repository. The plugin unlocks the repository
   in the background (this can take a few minutes on shared hosting) and lists the old
   snapshots under **Backups → Snapshots**.

A "fresh install import" in the wizard, which would do steps 4 to 6 through the admin area
after WordPress and the plugin are installed, does not exist yet; it is planned for
Milestone 3.

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

The security keys (salts) in `wp-config.php` changed, or the database moved to a site with
other keys. The stored passwords and the cached repository key no longer decrypt, and jobs
fail with "The stored passwords can no longer be decrypted." Nothing is lost: the repository
and the recovery kit are not affected. A site that defines both passwords in `wp-config.php`
is not affected either: its jobs derive the repository key again and go on. There are two
ways back:

- **Through the admin area**: open **Backups → Settings** and go to **Repository**. Enter
  the kit's REST password under "REST password" and choose "Test and save". Then enter the
  kit's repository password and your WordPress password under "Repository password" and
  choose "Unlock with this password"; the plugin derives the key in the background, which
  can take a few minutes on shared hosting. A password that does not open the repository
  changes nothing: the plugin goes on with what it had. Schedule, retention and exclusions
  stay as they are. A password that `wp-config.php` defines has no field to fill in: the
  form says so, that password was never lost, and only the other one is entered.
- **In `wp-config.php`**: define both passwords from the kit there,

  ```php
  define( 'BACKUPS_FOR_RESTIC_REST_PASSWORD', '…' );
  define( 'BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD', '…' );
  ```

  The next job derives the key from the repository password again and caches it; that can
  take a few minutes on shared hosting. The values in the database that no longer decrypt
  are not used any more. To remove them:

  ```bash
  wp option delete backups_for_restic_secrets
  ```

## Scenario F — the repository is locked

A job was killed hard. The plugin removes its own stale locks (older than 30 minutes) before
the next job. If a lock from another machine blocks the plugin and you are sure no restic
process is running there, remove it from any computer:

```bash
restic unlock
```

Lock removal works even on append-only repositories.

A backup of the plugin that is paused between two steps holds a lock as well, and after 30
minutes without a step restic calls that lock stale. If you remove it and then run `restic
prune`, the prune deletes what the unfinished backup has uploaded so far, because no
snapshot uses it yet. The plugin notices this when the backup goes on: it starts the backup
over, says so in the job's log, and uploads again what is missing. Nothing is lost, the
backup only takes longer. If this happens more than three times in one backup, the job
fails with the error `repository_changed`; the next scheduled backup runs normally. To
avoid it, do not prune from another computer while the dashboard shows a backup in
progress ([ADR 0030](adr/0030-lock-continuity.md)).

The plugin can release its own run's lock even when an upgrade makes that run's checkpoint
version unreadable or its saved directory entries are damaged. Cleanup reads the stable
run number, without loading the repository index or resuming the backup. It does not
remove another run's or another host's locks. If the ownership record itself is unreadable
or the server is unreachable, the stale-lock procedure above still applies.

### Local backup working-file failures

An environment error naming a backup working file means that PHP could not create, read,
write, truncate or remove a file in the plugin's private directory. Check free disk space,
the hosting account's quota and the directory's ownership and permissions; the PHP process
must be able to write there. Keep the directory private and do not make it world-writable.
After correcting the cause, start a new backup and confirm that it succeeds. A partial
checkpoint or an unreadable saved directory is refused, so missing bytes cannot silently
become an empty directory in a successful snapshot. This local failure alone is not a
reason to repair or prune the repository ([ADR 0032](adr/0032-run-checkpoint-files-and-cleanup.md)).
Cleanup of a job or an old cache reports the same local storage error if its files cannot
be removed. The job records the error and continues its remaining cleanup steps.

## Scenario G — reclaiming space (full prune)

The plugin deletes snapshots according to the retention policy and removes packs that no
snapshot references, and packs that an interrupted backup left behind. Packs that still
contain some live data are not repacked by the plugin.

A plugin prune paused for more than 25 minutes takes its lock again and checks whether the
snapshots or indexes changed. If they did, it starts its scan over; otherwise it continues
from its saved position. After three restarts, another change ends the job with
`repository_changed`. Let other computers finish using the repository before retrying.

Run a full prune from any computer when the dashboard shows a large reclaimable amount:

```bash
restic forget --host example.com --tag backups-for-restic --group-by host --keep-last 2 --keep-daily 7 --keep-weekly 4 --keep-monthly 6 --keep-within 2d --dry-run
```

Check the list, then run the command again with `--prune` in place of `--dry-run`. Without
`--group-by host`, restic also groups by paths, and with `--group-by host,tags` every snapshot
is a group of its own, because each carries the tag of the job that wrote it, and nothing is
removed. If several sites with the same host name share the repository, select one site with
`--tag backups-for-restic,site:<hash>`; `restic snapshots` shows each site's tag.

With append-only enabled, temporarily disable it first (rest-server: restart without
`--append-only`; BorgBase: repository settings) and re-enable it afterwards. The plugin notices
both changes, but it never starts deleting on its own after append-only was turned off. **Do not** run
`restic migrate upgrade_repo_v2` or `prune --repack-uncompressed` while the plugin uses the
repository: after the upgrade, restic compresses every index and snapshot file it writes,
even with `--compression off`, and the plugin cannot read compressed data unless the server
has the `zstd` PHP extension ([ADR 0019](adr/0019-repository-format-and-chunker.md)).

## Scenario H — the first backup seems to take forever

On shared hosting the first backup of a large site runs in many short slices and can take
hours. Where the site can call itself, each slice starts the next one. Where it cannot,
progress continues while the dashboard tab is open, with every visit to the site, and with a
control-panel cron that fetches `wp-cron.php`
([Running backups with a real cron job](guides/cron.md)). The dashboard shows how many files
and how much data the backup has processed and how much new data it has uploaded. The first
backup shows no estimate of the remaining time, because the plugin does not know the size
of the site before it has gone through it. Later backups are measured against the last one,
with a bar and the minutes left at the speed so far; they touch only changed files and
finish in minutes.

## Scenario I — the check found damage

The dashboard, Site Health and an e-mail report that the repository check found errors. Some
snapshots may then not restore completely. **Do not delete anything and do not prune** until
the cause is clear.

1. Stop every automatic deletion: set **Backups → Settings → Old snapshots → Who removes old
   snapshots** to *Another computer*, so that this site starts no forget or prune, and pause
   maintenance jobs on any other computer that uses the repository. Note what the setting
   was. The setting does not stop a job that is already running: if the dashboard shows a
   job that removes old snapshots or unused data, cancel it there and wait until it has
   stopped.

   If a finding says that a pack is missing or damaged (for example "A pack that the index
   lists is missing on the server."), pause the scheduled backups as well, until the index
   is repaired in step 3: set **Settings → Schedule → Back up** to *Only manually*. While
   the index lists data that is gone, a backup takes that data as stored and does not upload
   it again, so its snapshot would be damaged too. With other findings backups may go on.
2. On any computer with restic and the recovery kit, check everything:

   ```bash
   restic check --read-data
   ```

3. Follow restic's [troubleshooting guide](https://restic.readthedocs.io/en/stable/077_troubleshooting.html)
   and repair the index first. In short: `restic repair packs <pack-id>` saves what is
   intact in a damaged pack that the check names and removes the pack; `restic repair index`
   builds a new index from the packs that exist, so that it no longer lists lost data.
   Repairs delete files, so on an append-only server they need deletions allowed, as in
   Scenario G.
4. Start **Back up now**, wait until the dashboard shows that the backup succeeded, and
   set the backup schedule back. With a correct index the backup uploads again whatever
   went missing and still exists on the site, which also heals older snapshots that use the
   same data. While the backup runs it holds a lock, and the check of the next step would
   refuse to start.
5. Run `restic check --read-data` again. If it still names snapshots that refer to lost
   data, that data no longer exists on the site. Only then run
   `restic repair snapshots --forget`: it replaces those snapshots with copies without the
   damaged files and directories, which is a loss that cannot be undone. Check once more.
6. Only when `restic check --read-data` finds no errors is the repository healthy; the
   plugin's check reads a sample of the data (2 % by default) and cannot prove that. Then
   start **Check now** on the dashboard to clear the warning, and set retention back to
   what it was in step 1. Leave it at *Another computer* if that was your choice or if the
   server is append-only.

## Scenario J — the plugin is inactive after a downgrade

An older plugin can find settings written in a layout it does not understand. It then
shows an update notice and registers no backup page, REST route, scheduled callback or
WP-CLI command. No plugin backup or maintenance job runs in this state. Install the version
that wrote the settings, or a newer version that supports them. Do not lower or delete the
stored layout number to bypass the refusal: an older version could misread retention
settings and delete snapshots that should be kept.

Deactivating an incompatible version removes its scheduled events but leaves existing jobs
and repository locks untouched. Install a compatible version to handle them. Repository
data and the recovery kit remain usable with restic independently of the plugin; the lock
guidance in Scenario F still applies.

The options and job documents have separate layout numbers. An unreadable job produces
`job_unreadable` when a new job or a repository change is requested, even if the options
are compatible. The plugin keeps every unreadable job document, including one whose raw
status looks finished, because it cannot safely interpret that status. Install a version
that can read the job. If that version still refuses it, keep a copy of the private job
directory when investigating damaged files; do not remove the job just to unblock changes
while it may still be running.

A different notice says that the first settings layout number could not be saved. Check
the database's write access and storage availability. The plugin retries recording the
number on the next request and starts normally once that write succeeds.

## Restore drills

Quarterly, restore the latest snapshot to a scratch directory on any computer and open the
manifest; once a year, rebuild a staging copy of the site from it. The plugin does not
remind you of this yet: a reminder on the dashboard is planned for Milestone 3, and an
automated drill job is on the backlog.
