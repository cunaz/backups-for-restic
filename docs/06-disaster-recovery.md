# 06 · Disaster recovery

If WordPress still runs, restore files to private staging from the Backups page and
download them there. If the server is gone, use the recovery kit and the public restic
command line on another computer. The kit's commands require restic 0.16.1 or newer for
the REST credential environment variables. Repositories created by the plugin use format 1.

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
  restic snapshots --host 'example.com' --tag 'backups-for-restic,site:100680ad546c'
  restic restore latest --host 'example.com' --tag 'backups-for-restic,site:100680ad546c' --target ./restore

The database is in the snapshot under the plugin's spool/database folder. In that directory, import the .sql files into an empty database in name order. Replace recovered_site with its name: cat ./*.sql | mariadb recovered_site
Keep this kit away from the website, for example in a password manager.
```

For a rest-server with its own certificate authority, the kit also contains the CA
certificate: save it as `ca.pem` and add `--cacert ca.pem` to every restic command, as the
kit's recipe does. The kit carries the certificate of the saved server; one that was only
used for a connection test is not in it until that server is saved.

After setup, a shell operator can display another copy with
`wp backups-for-restic recovery-kit`. Use a private terminal. To export a new file,
choose a previously unused path outside the website, on storage you control:

```sh
(umask 077 && set -C && wp backups-for-restic recovery-kit --force > /private/path/recovery-kit.txt)
```

The site records each delivery. Store the file with the other offline recovery material.

| Lost item | Recoverable? | How |
| --- | --- | --- |
| REST username/password | yes | add a new `.htpasswd` entry on your rest-server; a hosted service such as BorgBase issues new ones in its control panel |
| Repository URL | yes | on rest-server it is your server URL plus the repository path; a hosted service shows it in its control panel |
| Repository password | **no** | restic's encryption is end-to-end; neither the storage provider nor the plugin author can help. If the WordPress site still works, the plugin shows the kit again after re-authentication |

## Scenario A — the site is damaged, WordPress and the plugin still work

1. Open **Backups → Snapshots**, select the snapshot and tick the files or directories
   you need. **Select entire snapshot** includes every path.
2. Choose **Build a ZIP for download** if you want one archive. Its size is about the
   total size of the staged files. Select **Restore to staging** to start the job.
3. Follow progress under **Backups → Restores**. Download a file by its absolute path
   in the snapshot, or download the ZIP you requested. The restore report lists skipped
   entries such as symbolic links.
4. To put the staged files back, expand **Restore files to the live site** and choose
   **Preview live file changes**. Read the actions and live paths. A site administrator
   who can update WordPress core can acknowledge the preview, type the site's address and
   enter their WordPress password to apply the files. The site's file modification policy
   must permit this operation. A large selection gets a background preview. Keep the tab
   open to advance it, or reopen the preview in the same tab to resume. Use **Next file
   changes** and **Previous file changes** to inspect the pages. A single review accepts
   up to one million entries. Stage larger selections as separate restore jobs. A finished
   background preview is available for one hour while its source remains available. Review a fresh
   preview if the files changed. Use the recovery kit offline for WordPress core,
   configuration, must-use plugins and early-loaded drop-ins. The preview identifies
   these startup paths. It never applies the backup's private database dump. The database
   is restored separately: a database rehearsal imports the dump into temporary tables and
   checks it, and a separately confirmed exchange then makes it live on an eligible MySQL
   server; on MariaDB and with incompatible schemas, import the SQL files by hand
   (Scenario B). Where PHP can tell which user and groups it runs as, the preview refuses
   to replace a file that belongs to another user than PHP, or whose group a file written
   by PHP cannot have: have the owner changed to the PHP user, or the group to one PHP
   belongs to, or restore that file offline. PHP cannot tell when its POSIX extension is
   missing or the host has disabled one of its functions; the preview then does not check
   owner and group, and the apply stops before it replaces such a file. Without the PHP
   intl extension the preview also refuses a name that might match a protected path once
   Unicode is normalized.
5. The **Restores** tab lists available undo jobs and their expiry times. Choose
   **Preview undo** and review the actions: the undo decides for every entry on its own.
   An entry that is still as the apply left it is reverted: a replaced file gets its
   original back, and a file or directory that the apply created is removed. An entry that
   changed after the apply is kept, and the preview gives the reason: for example a file
   that was edited or updated since, a replaced file that was deleted, or a directory the
   apply created into which something was uploaded since (the other files the apply put
   there are still removed). Files and directories that the apply created and that were
   deleted since, such as a removed plugin, need nothing. The preview counts what it
   restores, removes and keeps; confirm to revert the listed entries. The undo checks
   every entry again just before it changes it and keeps one that changed after your
   review. Its result counts what it did and lists every kept entry with its reason. Kept
   entries stay undoable until the expiry time, and the apply stays listed as partly
   undone: if a kept entry is put back as the apply left it, for example by undoing a
   later apply over the same files, preview the undo again. An undo with nothing left to
   revert is refused. Restore files that should not stay as they are offline as in step 4.
   Use **Delete staged files** to remove the private downloads when you are finished.

Through WP-CLI, list snapshots and stage the paths you need. Replace the example
snapshot ID and file path with the ones from your backup.

```bash
wp backups-for-restic snapshots
wp backups-for-restic restore 0123abcd /var/www/html/index.php --wait
```

If a restored plugin prevents WordPress from starting, run WP-CLI from the destination.
Use the affected plugin's slug and the ID of the file apply job. Keep Backups for restic
loaded. The command presents the reviewed undo with its totals and asks for confirmation.
Afterwards it lists the entries it kept, with their reasons.

```sh
wp --skip-plugins=affected-plugin --skip-themes backups-for-restic undo APPLY_JOB_ID
```

If the database or a worker goes away during a confirmed operation, restore service and
resume the same job with `wp backups-for-restic tick --until-idle`. Its journal records
completed changes and checks their identities during recovery.

While a file job keeps the site in maintenance, the commands of Backups for restic load
only this plugin and no theme. Other WP-CLI commands, such as `wp plugin` or
`wp cron event run`, load the site's plugins as during a WordPress update. A restored
plugin that stops WordPress from starting stops them as well; skip it with
`--skip-plugins`.

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
   restic snapshots --host example.com --tag 'backups-for-restic,site:100680ad546c'
   ```

   Use the host and tag from the original site's kit. If you connected the repository from a
   new address and only have its kit, list the whole repository with `restic snapshots`.
   Verify the source hostname, site tag, backup time and paths, then use the full snapshot ID.

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
     in its private directory. Under **Backups → Settings**, upload the PEM certificate from
     the recovery kit using **Upload certificate**.
   - The security keys. If jobs fail with "The stored passwords can no longer be decrypted.",
     `wp-config.php` has other keys than before: see Scenario E.

   On a WordPress whose database does not come from the snapshot, activate the plugin and
   enter the kit's repository address, REST username and REST password in the setup wizard.
   The connection test finds the existing repository, and the wizard then asks for the
   repository password instead of creating a repository. The plugin unlocks the repository
   in the background (this can take a few minutes on shared hosting) and lists the old
   snapshots under **Backups → Snapshots**.

To recover content onto a newly installed WordPress, connect the existing repository in
the setup wizard. After saving the recovery kit, choose **Restore from an existing site
snapshot**. The same action is available under **Backups → Restores**.

1. Choose the source site's snapshot from the whole repository. Verify its site address,
   date and content path. This flow requires a backup that includes a site manifest and
   database dump.
2. Choose **Stage site files and rehearse the database restore**. The rehearsal imports
   base tables into temporary tables and verifies them. Inspect the database rehearsal to
   see verified rows, live estimates, engines, collations and compatibility findings. Select
   the optional ZIP before staging to obtain the source SQL files for manual recovery.
   MariaDB installations that refuse the protected live rename require a manual database
   import as described in Scenario D.
3. Preview the staged files. The **Snapshot path** and **Live path** columns show how the
   source content and uploads map to this installation. Review and confirm the file apply
   as in Scenario A. The background job hashes large files in chunks and presents
   up to 100 actions per page.
4. Expand **Restore tables to the live database** and choose **Preview live database
   changes**. Review each table marked for replacement or creation and any compatibility
   findings. On an eligible MySQL installation, acknowledge the plan, type the destination
   home URL, enter your WordPress password and choose **Apply database restore**. The
   exchange keeps this site's address, the confirming administrator and active sessions,
   roles, active plugins, cron and backup configuration. Open the site and verify its
   content after the job succeeds.
5. **Undo live database changes** lists the previous table generations and their expiry.
   Choose **Preview database undo**, review the tables and confirm with the site's URL and
   your WordPress password. Reverting returns the earlier generation to service. Changes
   visitors made to the replaced tables after the apply belong to the generation being
   taken out of service. Keep any new data you need before undoing.
6. If the preview reports a blocker, use the staged SQL dump and the manual import
   procedure in Scenario D. MariaDB's write-lock behavior requires manual import.
   An automatic exchange requires the configured backup capability and all four WordPress
   capabilities `manage_options`, `edit_users`, `promote_users` and `update_core`.

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

Extract the archive into a private directory outside the web root, or unpack the
database directory from the staging ZIP there. Create an empty recovery database and
give its account access only to that database. Each table dump starts with a
`DROP TABLE IF EXISTS` statement. Keep the current live database available for rollback.

In Bash, change into the directory containing `000-header.sql` and `manifest.json`.
Check the table file names against the manifest, then import in name order using one
database connection. Replace `recovery_user` and `recovery_database` with the account
and new database you created.

```bash
set -euo pipefail
for table in 010-*.sql; do
    tail -n 1 -- "$table" | grep -Eq '^-- end table rows=[0-9]+ bytes=[0-9]+$' || {
        echo "Incomplete table dump: $table" >&2
        exit 1
    }
done
cat -- 000-header.sql 010-*.sql 900-views.sql |
    mariadb --user=recovery_user --password recovery_database
```

Use the `mysql` client for MySQL. Check the imported table counts against the rehearsal
or dump manifest, and start a private WordPress copy against the recovered database with
the snapshot's table prefix. Confirm its home URL, administrator login and content
before switching production. A manual SQL import restores the source accounts and
options. Use the destination's recovery kit to reconnect backups if its salts differ.

### Applying a rehearsed database on a running installation

Open the successful database rehearsal under **Backups > Restores** and review the
database table plan. It distinguishes replacement, creation and tables kept on the
destination. On a compatible host the initiating WordPress administrator confirms the
current site address and password. The job imports verified data into private tables
first. WordPress enters maintenance during the final write-locked exchange. The current
home and site URLs, active plugins, roles, confirming administrator and sessions, backup
configuration and cron schedule carry across.

The automatic exchange supports writable MySQL 8.0.13 or later, InnoDB tables, a
matching prefix and WordPress schema version, and a standard single-site connection
with a local object cache. MariaDB supports the rehearsal and the manual recovery
procedure above. Missing source trigger information, views, foreign keys, triggers,
CHECK constraints, incompatible accounts or unsupported database permissions can also
require that procedure. The MySQL account needs direct access to the affected schema
with SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, LOCK TABLES and TRIGGER, and
global PROCESS for the physical table dictionary. A schema containing views also needs
SHOW VIEW so MySQL can reveal their dependencies. If external applications or cross-schema
views depend on the WordPress tables, use a database administrator for recovery. On shared hosting, ask the database
administrator to perform a manual import where these grants are unavailable.
[Full compatibility conditions](adr/0043-atomic-mysql-database-exchange.md).

**Undo and edits made after apply.** An apply job records a 24-hour undo deadline.
Within it, review and confirm the undo on the Restores screen. Undo brings the original
generation live and copies across the current protected administrator and site state.
New posts, users and ordinary settings created in the intervening live generation then
remain in private database tables for a further 24 hours. Export any required newer
data before the undo job's reported retention deadline. The plugin's heartbeat expires
those private copies. Uninstall also removes them.

**Selective recovery by a database administrator.** Use the original database apply job
ID to open its private `jobs/<apply-job-id>/job.json`. The following command prints the
logical name, retained physical name and expected InnoDB table ID. It reads the stored
generation because undo changes which set of tables is retained.

```bash
jq -r '
  .data as $d
  | select($d.database_generation == "applied" or $d.database_generation == "undone")
  | ($d.database_owner.prefix | startswith("_")) as $underscore
  | ((if $underscore then "bfr_r_" else "_bfr_r_" end) + $d.database_owner.token) as $base
  | $d.exchange_plan.pairs | to_entries[]
  | (if $d.database_generation == "applied"
     then [.value.before, "_b"]
     else [.value.after, "_t"] end) as $kept
  | select($kept[0] != null)
  | [.value.name, ($base + $kept[1] + (.key | tostring)), $kept[0]]
  | @tsv
' /private/path/jobs/APPLY_JOB_ID/job.json
```

Check the original schema name and server UUID in `data.exchange_plan` against
`SELECT DATABASE(), @@server_uuid`. For each retained name, verify its physical
`TABLE_ID` in `information_schema.INNODB_TABLES` under `database/retained_name`.
Export the verified tables to private storage before the retention deadline. For
example, substitute the verified schema and physical name below. The command prompts
for a password.

```bash
mysqldump --user=recovery_user --password --single-transaction --no-tablespaces --set-gtid-purged=OFF \
    --skip-triggers recovery_source_database retained_table_name > retained-table.sql
```

Import the exported files into an isolated recovery database and select the rows that
must be reconciled. Keep the job's logical-to-physical mapping with the export.
Deleting or renaming live tables manually would bypass the plugin's generation checks.

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

An error with code `tree_unreadable` can mean that a directory's entry names are duplicated
or out of order, even when the encrypted data authenticates correctly. The plugin refuses
that directory because its listing is ambiguous. The full restic check below helps find
other damage. Both restic 0.19.1 and 0.20.0 can report a clean check on a directory with
duplicate names. Rebuilding the index leaves those directory entries in place. Follow the
[tree-name recovery procedure](#duplicate-or-unordered-tree-names) to identify the affected
snapshots and paths. Starting with restic 0.20.0, the CLI's
restore operation also reports an error for duplicate or unordered names. [restic 0.20.0 release notes](https://github.com/restic/restic/releases/tag/v0.20.0)

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

   If this check is clean but the plugin still reports `tree_unreadable`, use the
   [tree-name recovery path below](#duplicate-or-unordered-tree-names) instead of steps 3
   to 5. Do not repair the index or disable append-only protection to try to clear that
   finding. If restic reports separate pack or index damage, the following steps address
   that damage only.

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
6. Require both a clean `restic check --read-data` and a clean plugin check before resuming
   automatic deletion. The full restic check verifies all data; the plugin reads a sample
   (2 % by default) and also checks tree names for duplicates and incorrect order. Start **Check now** on the dashboard and confirm that it clears the
   error notice. Then set retention back to what it was in step 1. Leave it at *Another
   computer* if that was your choice or if the server is append-only.

### Duplicate or unordered tree names

Keep automatic deletion paused as in step 1 above. A tree with duplicate names cannot tell
the plugin unambiguously which file belongs at a path. Index repair does not change tree
contents, and this guide provides no automatic lossless repair for that ambiguity.

1. Obtain the **full 64-character tree ID** from the saved check report. The dashboard shows
   only its first eight characters, which are not sufficient for the commands below. On
   the WordPress installation, in its directory, run:

   ```bash
   wp option get backups_for_restic_state --format=json
   ```

   In `check_alert.findings`, find each entry whose `code` is `tree_unreadable` and copy its
   complete `object` value. Check `check_alert.job` and `check_alert.at` to make sure this is
   the check you are investigating; `last_check.findings` also retains the full IDs from the
   latest check. The option stores the check's original IDs; only the dashboard response
   truncates them. If the report is absent or no longer matches, run a new plugin check
   before continuing. Do not guess missing ID characters or derive them from a short match.

   Use each complete ID to locate candidate snapshots:

   ```bash
   restic find --tree <tree-id>
   ```

   Repeat for each reported tree to identify candidate snapshots. In restic 0.19.1 and
   0.20.0, this command reports the first matching path in each snapshot. The same tree
   can occur at additional paths. To inspect the stored entries directly, use
   `restic cat blob <tree-id>`. Alternatively, `restic cat tree '<snapshot-id>:<path>'`
   reads the tree at a snapshot path; it does not accept a tree ID.

   Before deciding that data is recovered, inventory every reference to each reported tree.
   This read-only walk also covers trees reused at several paths:

   ```bash
   restic snapshots --json
   restic cat snapshot <snapshot-id>
   restic cat blob <root-tree-id>
   ```

   For **every** snapshot in the first output, take its `tree` field from `cat snapshot`
   as the root tree at `/`. Record a match if this ID is the reported tree ID. Read its
   `nodes` array with `cat blob`; for each entry whose `type` is `dir`, append its `name`
   to the current path, record a match if its `subtree` is the reported ID, and read that
   subtree with `restic cat blob <subtree-id>`. Repeat until every directory entry has been
   visited. Visit a reused subtree at **each** path, even if its ID was seen before. Keep
   duplicate names as separate entries, recording their positions in the parent `nodes`
   array; collapsing them into a map would hide data. Record the snapshot ID with every
   matching path, including `/` when the snapshot root matches.

   Do not proceed to forgetting snapshots if any tree cannot be read, the inventory is
   incomplete, or duplicate entries remain unclear. Keep the repository unchanged and seek
   help with the report. These commands diagnose the contents; they do not repair them.
2. Preserve the original repository and recovery kit. Recover required files to a separate
   empty directory from unaffected snapshots or another backup, and verify their contents.
   Do not rely on a restore of duplicate entries to preserve every version of a file.
3. If an affected snapshot contains data you still need, leave it in place and keep automatic
   deletion paused. Only after the required data is independently recovered and verified,
   and you accept losing those snapshot records, remove the specifically identified
   snapshots with `restic forget <snapshot-id>` **without `--prune`**. On an append-only
   server, leave them in place unless you have deliberately arranged that maintenance
   exception; a clean restic check alone is no reason to allow deletions.
4. Run the full restic check and the plugin check again. Other affected snapshots can keep
   the finding active, and resolving one tree can expose further findings. Repeat the
   diagnosis as needed. Resume automatic retention only when both checks pass, as in step 6.

## Index memory limit

`index_too_large` means the repository index and the job's working buffers do not fit in
the memory available to PHP. It is a local capacity limit, not a finding that the repository
is damaged. A backup first tries smaller packs, down to 4 MiB, and records that adjustment
in the job log. If the budget still does not fit, the job stops.

The budget is checked after each pack in an index has been decoded. A single large index
file or pack can exhaust PHP memory before this check, so a PHP out-of-memory error is
also possible. The same capacity guidance below applies; neither error proves repository
damage.

Ask the host to increase PHP's `memory_limit`, then confirm the effective value under
**Backups → Environment** and start the job again. WordPress and other active plugins also
use that allowance, so a given limit does not guarantee a fixed number of blobs. If the
host cannot supply enough memory, use restic and the recovery kit on another computer to
check and restore the repository. Do not prune or repair it merely because of this error.
The index measurements and their limits are documented in
[ADR 0031](adr/0031-compact-index-and-memory-budget.md).

## Scenario J — the plugin is inactive after a downgrade

An older plugin can find settings written in a layout it does not understand. It then
shows an update notice and registers no backup page, REST route, scheduled callback or
WP-CLI command. No plugin backup or maintenance job runs in this state. Install the version
that wrote the settings, or a newer version that supports them. Do not lower or delete the
stored layout number to bypass the refusal: an older version could misread retention
settings and delete snapshots that should be kept.

A malformed layout number causes the same refusal. Only a missing option is treated as a
legacy installation; an empty, zero, negative or non-numeric stored value is preserved and
keeps the plugin inactive. Keep a copy of the database when investigating damaged settings,
then restore the settings and their layout number together from a verified database backup
with a compatible plugin version. Do not invent a replacement number. The first recording
of a missing number never replaces a value another request has inserted in the meantime.

Deactivating with a newer or malformed layout removes scheduled events but leaves existing
jobs and repository locks untouched. Install a compatible version to handle them. Repository
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
