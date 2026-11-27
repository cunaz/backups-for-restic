# Changelog

All notable changes are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

### Added
- Eligible MySQL installations can exchange a reviewed snapshot database in one atomic operation, preserve the destination administrator, address and backup configuration, bring back the snapshot's roles, active plugins and scheduled events, and undo the exchange for 24 hours. The database report explains manual recovery on MariaDB and other incompatible installations.
- File review runs as a resumable job, serves entries in pages, and checks identities and contents before applying or reversing an approved change. WP-CLI can resume an interrupted job and undo file changes if an installed plugin breaks WordPress.
- `wp backups-for-restic recovery-kit` exports an audited copy of the recovery instructions. Writing credentials to a file or pipe requires `--force`.
- Selected files can be staged from the snapshot browser or WP-CLI and downloaded through single-use forms. Completed restores can include ZIP archives and a database rehearsal report.
- Administrators can preview and confirm live file changes and review an undo within 24 hours. The fresh-install wizard maps verified snapshot content and uploads to the receiving site's paths.
- The package builder can produce a reproducible ZIP for installation from the WordPress dashboard.
- A snapshot reader for the upcoming restore browser: filter by site, read one directory
  without following links, and read the site's manifest under a shared repository lock.
  Encrypted tree caches share the existing cache limit with the index. New backups record
  where their manifest lives so that a different installation can find it after a move.
  Long snapshot lists refresh their shared lock while reading and keep only matching site
  metadata. Repeated tree-cache reads avoid rescanning the cache directory. The search for
  the manifest of an older snapshot stops after five seconds per call, not counting the
  time to load the index, and continues from the tree cache on the next one. An index too
  large for the cache limit no longer removes the cached trees, and temporary tree and
  index files of ended requests are removed after a minute. A private directory reached
  through a symbolic link is backed up under its real path, so that the manifest and the
  database dump are in the snapshot and the recorded location names them.
- Snapshot details report damaged repository trees. The command-line listing preserves
  original link-target bytes and omits malformed modification times.
- REST routes and a command that read snapshots: `GET /snapshots` takes a scope (this
  site's snapshots by default, or all of the repository), `GET /snapshots/<id>` answers
  one snapshot with the site's manifest, and `GET /snapshots/<id>/tree` answers one
  directory in pages of at most 1000 entries. Names that are not valid UTF-8 are shown
  with replacement characters, and every entry carries a token that names its path byte
  for byte. `wp backups-for-restic ls <snapshot> [<path>]` prints a directory and quotes
  names that could control the terminal.
- A snapshot browser in the Snapshots tab: this site's snapshots or the whole repository,
  a snapshot's details and the site it holds, and its directories one at a time with
  further pages and a path back up. Links are shown with their target and are not
  followed, and a name that cannot be shown exactly is marked. Files and directories can
  be ticked; the selection is listed and kept for the restore that follows in a later
  version. What lies in a ticked directory is shown as ticked with it and counted once, and
  the selection ends at the 256 paths that a restore takes. A locked repository gets its
  own message and a button to try again.
- The list of this site's snapshots says for each one whether the next cleanup keeps or
  removes it (`retention` in `GET /snapshots`). The preview uses the policy and the
  grouping of the forget job, removes nothing, and is renewed when a retention setting is
  saved: a cached list is only used under the retention settings it was read with.
- Restore engine foundation: several selected paths retain their original layout in empty
  private staging, with resumable planning and disk-space checks before writing. Reads hold
  a shared repository lock, grouped downloads authenticate every blob, and links/special
  files are recorded instead of created. Staged files are `0600` and staged directories
  `0700` whatever the snapshot recorded; the original modes are kept in the report. The
  repository lock is asked for once per tick and before and after a refresh, as backups do,
  so a refresh never replaces a lock that another client removed. WordPress restore jobs
  and screens still follow.
- Restore jobs: `POST /jobs` with `type: restore`, a snapshot ID and optional paths restores
  the selection into a staging directory of the job in the private directory, with
  progress, in resumable steps like a backup. A restore that fails or is cancelled removes
  what it staged and releases its repository lock. While a restore runs, scheduled jobs
  wait. Screens, the WP-CLI command and the download of staged files still follow.
- Restored files do not stay: they are removed 24 hours after the restore finished, or
  after the number of hours set under Settings → Restore (1 to 168). The heartbeat removes
  them, and also what a restore that did not succeed left behind. `GET /restores` lists
  what is staged with its size and time of expiry, and `DELETE /restores/{id}` removes one
  directory at once.
- Building blocks for downloading restored files, without a visible function yet: a zip
  writer of the plugin's own that needs no PHP extension and continues after an interrupted
  request, and download tokens that are valid once and for one hour.
- The dump catalog temporarily requests quoted table/view definitions, including when the
  server disables `sql_quote_show_create`, and restores the site's session setting afterwards.
  It reads schema under the dump's SQL mode so compatibility modes cannot omit table options.
  Database imports also enable quoting for their ownership checks and restore it after each tick.
- Database rehearsal imports staged table data into separate InnoDB tables, with checked
  SQL and resumable transactions. The administrator can download its comparison report.
  It also handles tables containing only generated columns and fixed-format MyISAM
  sources. It reads SQL statements up to 16 MiB; a larger one, which a backup writes for a
  row too large to share a statement, is restored with a command-line client.
- The stored settings and every job carry a layout number, so that later versions can
  convert them. A version installed over a newer one stays inactive and says so instead of
  acting on settings it may misread. The plugin also stays inactive if the database refuses
  to record the first layout number. Unreadable job documents are kept and block new jobs
  and repository changes until a compatible version can read them.
- A running backup shows a progress bar and the minutes left, measured against the last
  backup.
- The dashboard shows how much the repository holds, measured after every backup and
  every prune.
- WP-CLI: `settings list`, `settings get` and `settings set`, `cancel` and `logs`.
- The plugin records when the recovery kit was shown and to whom; the Settings tab shows
  the latest time, and the action `backups_for_restic_recovery_kit_shown` fires.
- The environment checks and Site Health point out a JIT compiler that is switched on,
  and a repository server that does not answer from this host.
- Settings → Repository: forms to enter the REST password and the repository password
  again, for example after the security keys in `wp-config.php` changed, and to upload or
  remove the CA certificate of the server. Values that `wp-config.php` defines are marked.
- On hosts where a site cannot request its own address, the browser of the administrator
  checks that the working directory is not served, in the setup wizard and on the
  Environment tab. Before, the check stayed "could not be verified" on such hosts.
- E-mail notifications: the first failure of a backup, forget, prune or check, once when
  backups become stale (36 hours by default, at least one and a half backup intervals), once
  when they work again, every check that finds damage, and an automatic switch to retention
  mode external. Unsent notices are retried.
- Dashboard cards for old snapshots, unused data and the repository check, with buttons;
  notices for check findings, the switch to external retention and the proposal to switch
  back. A check with findings is also shown on every admin screen and in Site Health.
- Prune estimates the space that only a full `restic prune` from another computer reclaims.
- A daily append-only probe by the heartbeat, so a switch back is proposed even when no
  forget or prune job runs.
- Settings for old snapshots, maintenance and notifications; a gentle CPU mode for hosts
  with CPU limits; a cache size limit, a cache size display and a button to clear the cache.
- `readme.txt` for WordPress.org and guides for cron jobs and for an own rest-server.
- Maintenance jobs: forget after every successful backup, prune and check on their schedules,
  and on demand from the REST API and WP-CLI (`forget`, `prune`, `check`). Forget and prune
  detect append-only servers first and then switch retention to external without deleting
  anything; switching back needs an administrator. A check with errors raises a lasting alert.
- Repository check: index files, packs, snapshot trees and a random share of packs downloaded
  and verified completely, resumable in short ticks; it reports the damage `restic check`
  reports.
- Prune without repacking: deletes the packs no snapshot uses, resumable in short ticks under
  an exclusive lock, verified against `restic prune` and `restic check --read-data`.
- First backup in WordPress (Milestone 1): setup wizard for an own restic REST server or a
  hosted one such as BorgBase, with connection test, append-only detection and an optional CA certificate; a new
  repository with a generated password or an existing one unlocked in the background; a
  recovery kit shown once and later only after re-entering the WordPress password.
- Backup jobs in resumable ticks on WP-Cron with loopback chaining, admin polling and a
  five-minute heartbeat that starts scheduled backups; one job at a time; cancellation.
- Database dump streamed into the snapshot per table, resumable per page, importable with
  `cat *.sql | mariadb`; site manifest; default excludes for caches and other backup
  plugins' archives.
- Encrypted secret store keyed by the salts in `wp-config.php`, with constants that override
  it; settings schema with validation; private working directory with an HTTP exposure
  check; local encrypted index cache.
- Dashboard, snapshots, settings, environment checks and logs in the admin area; Site Health
  tests; WP-CLI commands `doctor`, `backup`, `tick`, `status` and `snapshots`; uninstall
  removes everything except the repository.
- Database and WordPress integration test suites, a disposable test site script, and PHPStan
  rules that keep the core free of WordPress and `Secret::reveal()` on a reviewed list.
- Engine core (Milestone 0): pure-PHP implementation of the restic repository format
  (format 1, and format 2 when PHP has `ext-zstd`), REST backend with
  append-only detection, resumable backup and restore, forget with restic's policy
  semantics, and locking that restic respects.
- Poly1305 in pure PHP: the engine needs nothing from sodium.
- Conformance suite with the restic CLI (0.19.1, 0.18.1, 0.14.0) and rest-server as oracle,
  including a 300 MB streamed database dump and every server status code the plugin maps.
- Built-in structure-aware fuzzer for every parser that reads server data.
- Benchmarks with the Milestone 0 gates, mutation testing configuration, a PHPStan rule
  that keeps the engine core free of WordPress, and CI workflows.

### Changed
- The Restores tab lists the database restores whose private tables are on another database
  server or in another database (`GET /database-changes/foreign`) and offers the review and
  discard of their binding, also from the undo of such a restore, so that a site that moved to
  another database can start a database restore again without shell access. Every name is
  shown as text, escaped where it does not print. After a discard, the Restores tab lists the
  binding under "Discarded bindings" with the private tables that are left to remove by hand,
  and `wp backups-for-restic database-discarded` prints them, for 90 days or until they are
  marked as taken care of; until then the job history keeps that job and its log, which its
  card opens. While another request holds the lock of the job runner, marking them changes
  nothing and says so. After a lost answer to the confirmation, the page shows the result of a
  discard that the server recorded, and after a lost answer to marking the tables it reads the
  lists again; an older answer to the lists that arrives after a newer one changes nothing.
  On a site under `DISALLOW_FILE_MODS`, where WordPress gives no
  account the capability `update_core` that a discard needs, the page says so and names the
  WP-CLI command that can, instead of only saying that the account cannot. A discard that meets
  another request's lock of the job runner keeps its confirmation form and says that the review
  is still valid.
- Backups for restic requires 64-bit PHP. On 32-bit PHP, activation stops before the
  first backup. Existing installations retain their settings and local restore data.
- The snapshot list of the admin page shows the snapshots of this site; those of other
  sites and clients in the same repository are listed with `scope=repository`. Listing
  snapshots now takes a shared repository lock, so it answers "repository locked" while a
  prune holds its exclusive lock.
- Prune and check examine index files one pack at a time, and prune rewrites them without
  retaining every decoded blob. Prune loads its index cache under the repository lock and
  releases the blob index after marking. Check streams the server's pack listing to a
  private disk table and compares it in resumable batches.
- Index downloads and cache reads check the space needed for encrypted input before
  allocating it, so the next index file can be refused with `index_too_large` before
  decryption exhausts the process. Truncated cache pieces are rebuilt instead of causing
  repeated capacity refusals, and a zero-byte index is rejected before download.
  The check checkpoint layout is now version 3; an older in-progress check must be cancelled
  and started again.
- The list of everything in the repository takes less than half the memory it took, and
  reading it from the local cache a fifth. Index-only measurements suggest that roughly a
  million stored pieces could fit under a PHP memory limit of 128 MB, depending on the
  site's baseline memory use; complete WordPress backups at that size remain to be
  measured. When memory is tight, the backup uploads smaller packs than configured. A
  repository that is still too large ends the job with a clear message.
- The setup wizard lists an own restic REST server first and preselects it; BorgBase is the
  second option, and `repository.server` defaults to `rest-server`.
- Retention needs `keep.within` of at least one day or `keep.last` of at least 1. The
  settings refuse anything below (`retention_floor`), and a forget job that finds such a
  pair stored fails without deleting anything.
- A notification that could not be sent is tried again after 15 minutes, 1 hour, 6 hours
  and 24 hours, not at the next four heartbeats. It is dropped after the fifth refusal or
  after two days.
- With `advanced.loopback_chaining` off, creating a job no longer requests `wp-cron.php`;
  the heartbeat and an open admin page advance the job.
- Prune streams its pack listing to a private file and processes it in resumable batches.
  Unindexed pack IDs stay on disk, so a large listing cannot exhaust PHP memory by itself.
  The complete listing is validated before any index rewrite or deletion begins.
  A live pack missing from that listing stops prune before it can remove an unindexed
  recovery copy of the missing data.
- Prune also deletes the data that a cancelled or failed backup left on the server without
  ever listing it, as `restic prune` does. Until now only a prune from another computer
  removed it.

### Security
- The discard of a database restore binding whose private tables are on another database
  server or in another database is reviewed and confirmed as an undo is. Before, the REST
  route forgot the binding, and with it the undo of that restore, after the WordPress password
  alone, and WP-CLI after a prompt or `--yes`: nothing bound the decision to what the
  administrator had seen, and no site address was typed. Now
  `POST /database-changes/<id>/discard-review` says what the discard forgets and what stays:
  the database and server of the private tables and those that the site uses now, the table
  names, and whether the undo ends with it. Its digest and review token have to be confirmed
  with the typed site address and the WordPress password, and a binding that changed after the
  review is not discarded. `wp backups-for-restic database-discard` prints the same review and
  asks for the site address on a terminal; a script passes both `--site-url` and the `--digest`
  of a review that an earlier run printed, and one without the other, or a digest without a
  value, is refused. `--yes` is refused, so a script of the earlier form stops with an error and
  discards nothing. The discard still drops nothing.
- A database restore binding whose saved server UUID or database name is missing or malformed,
  which only a change outside the plugin leaves, was counted as one of another server, so its
  discard was offered and would have forgotten private tables on this very server. Nothing
  can tell such a binding apart from this server's: its discard and its undo are refused
  (`database_binding_unreadable`), it is not listed for a discard, a new database restore waits
  with that blocker, and its expiry is deferred and reported on its job. The same holds for a
  binding whose token or fingerprint cannot name its tables, which the list of bindings to
  discard offered although its review refused it, and for one whose saved exchange plan names
  another database, server or number of tables than the binding itself: where the plan's
  database was connected and the binding named another, the discard was offered and would have
  forgotten both.
- A live database restore brings back the roles, the active plugins and the scheduled events
  of the backup. Before, it kept those of the running site, so a change made after the
  backup survived the restore: a capability given to a role reached every restored account,
  and a plugin activated since stayed active, which the review did not show. The exchange now
  keeps from the running site only its address, the WordPress database version, WP-Cron's
  lock, the settings and state of Backups for restic and the approving administrator's
  account with its user metadata and sessions, and adds Backups for restic to the backup's
  active plugins if they lack it. An undo brings back the earlier database's own roles,
  plugins and events. The review lists what is kept, the plugins that stop or start, each
  with whether its file is on the site, and the roles whose capabilities change; a plugin
  that stops does so without its deactivation routine, and WordPress skips a plugin of the
  backup whose file is missing. If the backup's roles would leave your account without the
  backup capability, `manage_options`, `edit_users`, `promote_users` or `update_core`, the
  review and the exchange refuse with `database_admin_capabilities`, and an unreadable record
  of your account's own capabilities with `database_admin_capabilities_unreadable`. The
  backup's roles (up to 1 MiB) and list of active plugins (up to 256 KiB, also with
  Backups for restic added) are refused if WordPress did not write them that way or the
  backup lacks them (`database_roles_invalid`, `database_plugins_invalid`) or if they are
  larger (`database_roles_too_large`, `database_plugins_too_large`); the running site's are
  only compared, and the review says so where they cannot be read. The review also shows
  stored denials that differ. A change of the backup capability after the review stops the
  exchange before it writes anything (`database_exchange_changed`). An undo's
  refusals point to a role on the running site and to the retained tables. Rehearse a staged
  database again before you review it, and review and confirm again a database change that
  was confirmed before this version: it stops with `database_exchange_changed`.
- Compressed repository objects have their zstd frame sizes, windows and cumulative output checked before allocation. Verified pack data must also match its expected size and SHA-256.
- Uninstall checks all plugin-owned recovery records before deleting local data, including records in the default working directory after a change of configuration.
- The plugin reads no more of a server's answer than a repository file of that kind can
  hold. Before, a broken or malicious server could answer the request for a lock or a
  snapshot with a body larger than PHP's memory.
- Repository requests verify the server's certificate even when other code on the site
  switches certificate verification off for all requests (a filter on `https_ssl_verify` or
  on the request arguments, or the `http_api_curl` action).
- After the setup, `POST /repository/connect` and `POST /repository/create` answer with the
  recovery kit only if the request carries the administrator's WordPress password, like
  the recovery kit route; before, any session with the plugin's capability got the
  repository password and the REST credentials from them. A refused connect no longer
  replaces the stored password or drops the cached key.
- The stored REST password is only sent to the host, port and user name it was saved for.
  A connection test or a save for another server must carry the password
  (`password_required`); before, a request that named another host got the stored password
  sent there.
- Backup, forget, prune and check jobs cannot start before the setup is completed and the
  recovery kit acknowledged (`setup_incomplete`), neither through the REST API nor WP-CLI.
- A repository password chosen by the administrator must have at least 12 characters
  (`password_too_short`); a secret is read back after it is stored, and saving fails if the
  options table did not keep it.
- Job files and logs also redact the REST credentials in the form the `Authorization`
  header carries them.
- File apply no longer writes a snapshot's private database dump and site manifest to the
  live site. After the private directory had moved, applying `wp-content` from an older
  snapshot recreated `spool/database/*.sql` in the web root without its deny files. A
  directory named `spool` that holds a backup's site manifest or database dump is now
  skipped in every apply (`snapshot_spool`); it is recognized by the format line that the
  backup writes at the start of these files, so another directory with that name is
  applied as usual. The database rehearsal reads the dump from staging.
- Must-use plugins, drop-ins and the other startup and private locations stay protected
  when they are configured with repeated or trailing slashes or through a symbolic link, for
  example below a `WP_CONTENT_DIR` that ends in a slash. A location that is not an absolute
  path without `.` or `..` segments makes file apply unavailable (`protected_path_invalid`)
  instead of leaving it unprotected.
- Protected names are compared the way case-insensitive filesystems compare them: with full
  case folding, without invisible characters and, with the intl extension, after Unicode
  normalization. A name such as `wp-conﬁg.php` with a ligature no longer passes for an
  ordinary file. Without intl, a name that only normalization could tell apart from a
  protected one is refused (`unverified_name`).
- While a live file change holds the site in maintenance, a request that the job's control
  token admits can no longer reach another REST route through a `rest_route` field in its
  form body, and no admitted request can store the shortened list of active plugins that
  it starts with. Every tick of a file apply or undo checks again that the administrator
  who confirmed it still has the backup capability and `update_core`
  (`file_writes_refused`). The check reads the account from the database, so a role taken
  away also stops a running `wp backups-for-restic tick --until-idle`.
- Downloading a restored file or a restore's ZIP asks for the WordPress password, as the
  recovery kit does, and every grant is recorded with time, account, restore job, kind and
  path; the Settings tab lists the latest beside the deliveries of the recovery kit, and
  the action `backups_for_restic_download_granted` fires. A restored `wp-config.php` and
  database dump together decrypt the stored passwords; before, any session or application
  password with the plugin's capability could download them without a trace. Staging and
  the two reports still need the capability alone. Restore jobs record who started them,
  and the job view shows it.
- Wrong WordPress passwords at the recovery kit, at creating and connecting a repository,
  at live file and database changes and at downloads are counted per account. The first
  three cost nothing; then each makes the next attempt wait longer, from one minute
  doubling up to 32, and from the tenth on each locks the confirmation of that account for
  an hour (HTTP 429 `user_password_throttled` with `Retry-After`). Each fires core's
  `wp_login_failed` action, so that login limiters and fail2ban rules count it. The
  password is read from the JSON body of a request only.
- A refusal of the probe of the private directory counts as protection only when the same
  check also gets the plugin's own stylesheet back from the site. A 403 or 404 from a
  firewall, a CDN challenge or another virtual host no longer allows plaintext restore
  staging; the probe says it could not be verified instead. `restores/` and every staging
  directory get the deny files and `index.php` of the private directory, so that a server
  that lists directories does not list the restores.
- Deleting the plugin on a PHP older than 8.3 stops with a message that asks to switch
  back first. Before, the uninstall did nothing there and WordPress deleted the plugin,
  leaving staged files, retained database tables and the encrypted secrets behind with
  nothing left to expire them.
- `wp backups-for-restic snapshots` prints host names with control characters quoted, as
  `ls` prints names; a client of a shared repository could send escape sequences to the
  terminal through the host name of a snapshot.

### Fixed
- A restore and a check no longer run PHP out of memory while they read large blobs beside a
  large index. Beside WordPress and an index of 1,000,000 blobs under 128 MiB, a restore of
  chunks of nearly 8 MiB ended with PHP's fatal error, a restore of full chunks in a
  compressed repository refused them with `decompression_limit` after the download, and a
  check ended with the fatal error on a pack of full chunks. A restore now reads a file in
  groups no larger than the memory left can take, down to one blob at a time; a blob is let
  go once it is written, and a group once its last blob is taken out of it. A blob that does
  not fit by itself ends the restore with `restore_blob_memory` and its own text before it
  is downloaded, which says to raise the memory limit or to restore with restic on another
  computer. Once a group has arrived, memory that other code kept meanwhile is noticed
  before a blob is opened and ends the restore the same way instead of with PHP's fatal
  error. Under 128 MiB beside WordPress every restore that the version before completed
  still completes, measured in steps of 2 MiB of memory in use: a file of full chunks up to
  102 MiB in use, of chunks that compress up to 106 MiB (before: 88 MiB) and of small blobs
  up to 122 MiB (before: 112 or 120 MiB); above that the restore stops with
  `restore_blob_memory` where it ended with PHP's fatal error or `decompression_limit`. With
  memory enough, a restore and a check send the requests they sent before, and staged files
  are unchanged.
- A check reads every sampled pack whole and reports its damage whatever memory is left. It
  reads in pieces that fit into the memory left, down to 64 KiB, and leaves only the blobs
  that do not fit unopened: a pack whose name and header are right reports them as the
  warning `pack_memory`, which says how many blobs were not verified (they may still be
  damaged), and a pack of which not even the smallest piece fits is the warning
  `pack_unread`, beside the damage found before.
- What the memory budget lets a restore or a check read is no longer refused once it is
  downloaded. The budget counted the lengths of the copies, and a blob read with that much
  left could end a restore with `decompression_limit` after the download or with PHP's fatal
  error, and a check reported it as damaged (`blob_corrupt`). The budget now counts what PHP
  reserves for each copy, and no more: whole pages of the system for a string larger than a
  block of 2 MiB, a share of a new block for a smaller one, or nothing where PHP has free
  pages for it in the blocks it holds. A refusal that the decoder still makes, for a frame
  without a declared size and with a large window, ends a restore with `restore_blob_memory`
  and leaves the blob unverified in a check (`pack_memory`); an index file, a snapshot or a
  tree that a check cannot decode in the memory left stops it with `decompression_limit`
  instead of being reported as unreadable or its blobs as missing. A backup that cannot
  decode a folder of the parent snapshot in the memory left says so, instead of calling the
  parent snapshot incomplete, and reads the folder's files again as before.
- The review and the discard of a database binding answer what is the case: a binding that was
  discarded already, one whose job or undo has not ended, a server that reports no server UUID,
  a damaged record and an unreadable job document each have a code and text of their own, and a
  database failure says that nothing was discarded and that the log of that database restore
  job names the error, where it is written. Before, the route answered with a backup's text
  that pointed to "the log of the job", which a discard does not have. A private directory
  whose jobs cannot be read is answered as such (`database_discard_storage`), and any other
  failure as unexpected, recorded nowhere, with its details in WP-CLI
  (`database_discard_unexpected`). While a stored job cannot be read, no binding is reviewed or
  discarded: that job may be a queued undo of the binding (`job_unreadable` or
  `job_inaccessible`, with its ID).
- After the site's original database was connected again, a new database restore stayed
  blocked with the advice to discard a binding that the Restores tab did not list and whose
  review said that the connected database holds its tables, until a heartbeat had cleaned it
  up. The review of a new database restore now asks the server what is the case each time, as
  the list and the review of a discard do: another server, a server that does not say which
  one it is, a record that cannot be read and an interrupted import on this server each block
  with a text of their own, and nothing else blocks. A server that cannot be asked who it is
  ends that review with a text of its own (`database_binding_unasked`); before, the review
  answered with the text of a failed dump, which points to the log of a job that a review
  does not have.
- The review of a discard, and that of a database restore into such a database, no longer end
  in a fatal error on a site whose connection reports its database name in bytes that are not
  UTF-8, such as a connection in latin1 to a database named `möbel`. The REST API answers such a
  name with U+FFFD in place of each of those bytes, and WP-CLI prints it with Go's escapes; the
  digest of the review covers the bytes, and that of every review that could be made before
  stays the same.
- A failed job is described as the kind of job it is, on the admin page, in the job list, in
  WP-CLI and in the failure e-mail. Before, every job got the texts of a backup: a restore
  that lost its repository key, or found the setup incomplete, said "The backup is not
  configured completely", a restore that could not write in the private directory "This
  server cannot run the backup right now", a restore of a snapshot that is gone that the
  repository kept changing while it "started over three times", a prune "The next backup
  tries again", and an undo that its reverts "can be undone". A restore or maintenance job
  whose key is gone now ends with `key_missing`, a restore before the completed setup with
  `setup_incomplete`, and a restore of a missing snapshot with `restore_snapshot_missing`,
  each with its own text; where a general text names the backup or an action that the job
  does not have, the job's kind has texts of its own. No retry changes. The preflight that
  forget, prune and check share with backups no longer logs "for the backup".
- `GET /jobs/{id}/kept-entries` answers "The job does not exist." only for an ID that no job
  has. A job of another type answers 404 `not_an_undo`, an undo that is queued or running
  409 `undo_not_ended`, and a document that cannot be read 409 `job_unreadable`.
- An undo that is cancelled after it counted every entry is recorded as a finished undo:
  undone when it kept nothing, which ends the recovery window as such an undo does, and
  partly undone with the number of kept entries otherwise. Before, it was recorded as partly
  undone without a number, also when it had reverted everything, and the Restores tab
  offered to revert the rest until a review retired the apply. If the apply cannot be marked
  as undone then, it stays open for a later undo and the job's log says why.
- A background file review of a folder with more than 2,048 names ends when the folder keeps
  changing. The review needs one reading of all the folder's names during which the folder
  does not change; a reading that a change cuts short, between two ticks or during one, is
  begun again, and after five such readings in a row a preview stops with
  `file_folder_changing`, whose log line names the folder, and so does the check that an
  apply makes before maintenance, before it changes a file. An undo review keeps the entries
  of that folder as it keeps those of a folder it cannot read (`ambiguous_destination`) and
  goes on. Before, the reading began again at every tick for as long as the folder changed
  between ticks, so that such a review never ended, and a change during a tick failed a
  preview at once, as did a change that removed or renamed names after a tick looked at the
  folder and before it resumed its reading.
- The recovery window of an apply is decided only from undo records that can be
  established. A count of attempted changes or an undone marker that cannot be looked up,
  below a folder that the PHP user cannot search, or that is not a regular file, such as a
  directory or a link, keeps the saved original files, the journal, the staged files and the sealed review
  until the window ends, as an open window does. The Restores tab lists the apply with a
  notice and without **Preview undo**, and an undo or its preview is refused with
  `undo_records_unreadable`. Before, such a record counted as missing: the heartbeat deleted
  the journal and the saved copies, the staged files expired with their own hours, the apply
  left the list, and a stopped apply recorded that it had attempted no change. A link at the
  name of the undone marker counted as an apply undone completely. By the same rule a job
  document that cannot be looked up counts as stored: it blocks new jobs, keeps the staging
  of its restore and stops an uninstall, where it counted as gone before; and the staging of
  a pruned job is not removed as a leftover while its report cannot be looked up. Such a job
  has a code of its own, `job_inaccessible`, whose texts name the job and ask for ownership
  and permissions of its folder to be put right, for example after a WP-CLI command that ran
  as root; `job_unreadable`, whose texts ask for another plugin version, stays for a document
  that this version reads but cannot parse. Every refusal for either names the job, which the
  REST API returns as `job`, and `GET /jobs/{id}`, its log, its cancellation and the
  WP-CLI commands `logs` and `cancel` answer for that job as the refusal does, where they
  said that it did not exist. So do an undo and its reviews of an apply whose document cannot
  be read, which were refused as for an apply past its window (`undo_unavailable`).
- A heartbeat that stops while it deletes the journal of an apply that was undone completely
  leaves the apply's window closed: the count of attempted changes goes first, and its removal
  is synchronised. Before, the journal went in the order that the file system lists it, and on
  tmpfs, which lists the newest entry first, a deadline between the undone marker and the
  count left an attempted change that no undo had reverted: the apply was listed for undo
  again until its 24 hours ended.
- A cancelled apply or undo whose cleanup failed afterwards, for example because the site
  could not leave maintenance mode, shows that failure on the admin page beside its state and
  its counts, and WP-CLI's report of a cancelled undo names it too. Before, only a failed job
  showed its error, and the page said "Cancelled" while the maintenance page stayed up.
- While a stored job cannot be read, no staging and no sealed review expires, and no undo
  journal of an apply is deleted. Such a job may be an undo that still runs, whose apply's
  journal must stay beyond the window, or an apply within its recovery window: before, the staging of its restore expired with its
  own hours and its sealed review after one, while its journal stayed, so that once the job
  could be read again the apply was offered for undo and the undo refused with
  `file_staging_gone`.
- A stopped apply or undo records what it did also when another part of its cleanup fails:
  when the site cannot leave maintenance mode, when its verification data cannot be removed,
  and when that removal has to wait for a later tick, which a deactivation may never give.
  The failure is still reported and the other parts still run. Before, the cleanup ended
  there, and an undo that had reverted files recorded nothing of it, in its result or in its
  apply.
- A file preview, apply or undo that stops says what stopped it, on the admin page, in the
  job list, in the failure e-mail and in WP-CLI. Before, a file that changed after the
  review was reported as "Another backup job is still running", and a refused or damaged
  private file as "This server cannot run the backup right now", which sent the
  administrator to an Environment tab that was all green. A file that changed after the
  review now asks for a new preview (`file_changed_after_review`); staged files that are
  gone, a preview past its hour, an apply past its recovery window and the maintenance mode
  have codes and texts of their own, and anything else ends the job with the code of the
  preview, apply or undo and the reason in its log. A failed or cancelled apply shows how
  many changes it completed of those it attempted, and that they can be undone. The file
  routes of the REST API answer an expired preview with `file_preview_expired`, an apply
  past its window with `undo_unavailable` and a private record that cannot be read with
  `live_plan_failed` instead of `live_plan_conflict` or `environment`.
- Deleting staged files that the undo of an apply still needs says until when they are kept
  and why (`restore_kept_for_undo`, with `until`); a staging that a running job uses says
  so (`restore_in_use`). Both were "This restore is still running".
- After a reload, the first preview of live file changes no longer shows a finished
  background preview as if it were new: it is shown with the time it was finished, beside
  a button that inspects the live site again and the confirmation. A preview that is still
  queued or running is followed as before.
- A confirmation whose answer is lost or is not JSON, as behind a proxy that timed out, is
  no longer shown as refused while its job runs: the page looks for the job and follows
  it, and otherwise says that the request may have been accepted and where to check.
  Reviews carry `reviewed_at` for this.
- Reading the lists of the Restores tab again, as when a followed job ends, keeps every
  card whose entry did not change, with an open review, what was typed into it and an
  answer that is still on its way. Before, every undo card was built anew.
- Names, link targets, paths, host names, tags, manifest fields and table names from a
  snapshot or a database are shown apart from the writing direction of the text around
  them, and one that holds a control, zero-width or right-to-left character is shown
  quoted with Go's escapes, as `wp backups-for-restic ls` prints it. A file named with a
  right-to-left override no longer reads as another name. The documentation of `exact`
  says that it means valid UTF-8 and nothing more.
- "Select entire snapshot" shows every entry of the snapshot browser as selected; before,
  the entries stayed unticked and could be added to the selection a second time.
- A file preview shows its entries by action above the confirmation. A preview of more
  than 100 entries is read one page at a time, so the confirmation could come long before
  its last page.
- Every reason of the file review and of the undo review has a text: `identical_contents`,
  `different_filesystem`, `overlapping_destinations`, `parent_excluded` and
  `staged_type_changed` were shown as codes, and so were the database review blockers
  `database_exchange_foreign` and `database_exchange_rebuilt`. A unit test reads the codes
  from the code that gives them and fails on one without a text.
- The hints of a locked or unreachable repository no longer say that the plugin tries
  again: the job that they belong to has stopped. A request, such as reading snapshots or
  testing a server, says to try again.
- A live database exchange waits, for at most a minute, until requests that started before maintenance have ended: the site's database account must have had no other connection to the site database for a second, so a request that reconnected in between is waited for as well. Such a request could otherwise write a post or an order into one database generation and its metadata into the other. Connections that stay open, such as persistent database connections or long-running WP-CLI processes, make the exchange try again later. The exchange now needs MySQL 8.0.22 or later with the Performance Schema enabled and listing every connection; the review reports a server that leaves connections out.
- After a database migration or a failover to another server, a database restore that kept private tables no longer stops backups and all other jobs. Only another database restore waits. The new `wp backups-for-restic database-discard` command and REST action list those tables for removal on the old server and forget them without dropping anything. Uninstall no longer fails on such a restore, and all-tables backups no longer fail because of it.
- A database undo whose reply was lost is recorded by a later tick or by the heartbeat, which then expires the replaced tables. Before, the heartbeat stopped at that undo for good.
- `OPTIMIZE TABLE` and other table rebuilds after a database exchange no longer cost the undo of a rebuilt live table, and a rebuilt witness no longer stops expiry and uninstall. A rebuilt retained table is kept and listed on the job instead of being left behind unnoticed.
- One database restore whose cleanup keeps failing no longer stops older ones from expiring.
- The database review reports disabled InnoDB table locks, statement-based binary logging and a Galera node before the import starts, instead of failing after it.
- A row batch larger than the server's `max_allowed_packet` is refused with its own message before it is sent, and the job log names its size, the limit it needs and the server's limit. It was reported as a busy database and retried.
- A file in a compressed repository can be restored when restic stored one of its chunks as a full 8 MiB of data that does not compress, such as part of a photo or a video. Such a chunk is stored 202 bytes longer than itself, and restore refused it as an oversized blob.
- A compressed index file is decoded beside one copy of itself, and the check before decoding counts what the zstd extension allocates: twice a declared size. Index files of 150,000 blobs, as restic 0.14 to 0.16 write them in compressed repositories, load under 128 MiB with WordPress loaded, and so do 8 MiB data blobs beside a large index in restore and check. An index file that cannot be decoded in the memory left stops the job with `index_too_large` instead of a PHP out-of-memory error.
- When the private directory runs out of space during job creation, WP-CLI reports how to retry. Scheduled backups keep their due time and retry on the next heartbeat after space becomes available.
- Registering the REST API routes no longer emits a PHP 8.6 deprecation notice.
- A pack listing that the server answered with something else, such as the error page of a
  proxy, is downloaded again when a check or prune is retried; before, every retry read the
  same stored answer and failed. A server that keeps answering so is asked at most four
  times; then the job ends with the error instead of downloading the listing without end.
  A damaged length in the local index cache makes the cache
  be rebuilt instead of ending every backup, check and prune with `index_too_large`. A
  settings layout number whose stored value is a serialized null counts as malformed and
  keeps the plugin inactive, as other malformed numbers do, instead of passing for an
  installation without a number.
- A file name that ends in a line break is written to a snapshot's tree with the break
  escaped, as restic writes it. It was written unescaped before.
- Initial layout recording no longer overwrites a number another request has inserted.
  Newer and malformed settings layout numbers are preserved and keep the plugin inactive;
  deactivation leaves their jobs and repository locks alone. Only a missing layout option
  is treated as a legacy installation.
- Database backups preserve invisible columns and columns with expression defaults such
  as `CURRENT_TIMESTAMP`. They were previously emitted as NULL or omitted from INSERTs;
  values already missing from old snapshots cannot be recovered by this correction.
- Reading a snapshot now refuses directory listings with duplicate or out-of-order names,
  including differently escaped copies of the same name. The repository check reports
  these trees as damaged before a restore can use their entries.
- An index file that holds an object where the restic format has the list of packs or the
  list of a pack's blobs is now refused, as restic refuses it. An empty object was read as
  an empty list before, and an object of blobs as a list of them. Where a member name is
  repeated, the last member decides, which is more lenient than restic.
- Local backup working-file failures now stop the job with an environment error. Refused
  creation, incomplete writes and failed truncation cannot advance a checkpoint, and an
  unreadable saved directory cannot silently become empty in a snapshot.
- Job cleanup releases locks by the checkpoint's run number without resuming the engine.
  It works with unknown checkpoint versions and damaged node files, and needs no backup
  plan or repository index. Other hosts' and other runs' locks are kept.
- Failed recursive cleanup of private working files or caches is reported as a local
  storage error instead of silently leaving files behind. Other cleanup actions still run,
  including removal of an unconfirmed password. If the server refuses deletion, retention
  switches to external even when local cleanup fails.
- A backup that was paused for longer than its repository lock lasts could end with a
  snapshot that referred to data a `restic prune` on another computer had removed in the
  meantime. The backup now checks after such a pause that everything it relies on is still
  in the repository, and starts over if not. It does the same when its lock is removed
  while it works, before it writes an index file or the snapshot. A check starts over
  instead of reporting as damage what another computer pruned or forgot during its pause.
- A request that ended at the very start of a backup, check or forget could leave a
  repository lock that blocked other programs for 30 minutes. Every run now records its
  number first and removes such a lock itself.
- On a site whose scheduled requests were more than 25 minutes apart, a prune that needed
  more than one request started from the beginning every time and never finished. It now
  goes on where it was unless another program changed the repository in the meantime, and
  gives up with `repository_changed` after starting over three times.
- A prune that had to start over while it was deleting left data behind that nothing
  removed later. The next pass now finds and deletes it.
- Settings that the database refused to store no longer pass for saved: the request answers
  with an error, and saving a server puts back what it had changed. WordPress reports such a
  refusal the same way as an unchanged value, so it went unnoticed before.
- Saving a server that fails half way, for example because the database refuses a write,
  leaves the site with the server, the REST password and the key it had. Before, the new
  address could stay with the old password or without the certificate that belongs to it.
- Where `wp-config.php` defines the repository address, testing another address with a
  certificate of its own no longer replaces the stored certificate.
- What the plugin remembers between requests (last results, handled schedule slots, who
  looked at the recovery kit, waiting notices) is no longer overwritten when two requests
  change it at the same time, for example a running backup and the admin page. A notice is
  not sent twice when two requests try to deliver it at once.
- Deactivating the plugin just as a backup or a check finishes no longer leaves the job
  active and its lock in the repository: the result is recorded and the job cleaned up in
  the same request, and recording the result of a check no longer needs the server.
- A wrong repository password entered under Settings → Repository no longer replaces the
  stored one. Before, the stored password and the cached key were gone as soon as the form
  was sent, and backups failed until the right password was entered. Now the entered
  password is kept apart and takes effect only once the unlock job has opened the
  repository with it.
- The setup wizard sends the CA certificate along with the connection test. Before, the
  test ran without it and a server with a certificate of its own authority could not be
  set up. The test stores nothing; the certificate is kept when the server is saved.
- Connecting to an existing repository now records its ID and format version. Before, the
  settings kept format 1, so the Doctor did not ask for the zstd extension, and the recovery
  kit and the site manifest named the wrong format.
- A repository of format version 2 is refused when a job unlocks it on a server without the
  zstd extension, instead of failing later in the first backup.
- After a change of the salts in `wp-config.php`, a site that defines both passwords there
  goes on backing up: the cached repository key is derived again. Before, every job failed
  until the stored secrets were deleted by hand.
- Creating a repository stores its password before the repository exists. Before, a failure
  between the two left a repository that nobody could open.
- The last backup, the last forget, prune and check and the check alert belong to one
  repository and are dropped when the jobs turn to another one. The stale notice then counts
  from that day.
- Saving another server or creating a repository is refused while a job is running.
- Saving a new REST password for the same address keeps the cached repository key.
- Deactivating the plugin ends the running job and releases its lock in the repository.
- The setup cannot be completed by a request while the Doctor reports a critical finding.
- A request to cancel that arrives while a step is running is honoured by the same tick.
- A request to cancel that arrives just after a backup wrote its snapshot no longer ends
  the job as cancelled with the snapshot left unrecorded; the job ends as succeeded.
- A CA certificate that could not be written to disk is no longer announced as uploaded.
- `wp backups-for-restic tick --until-idle` and the commands with `--wait` returned at once
  when another tick was working on the job. They now wait for it.
- A cancel request that arrived while a tick was running could be lost, or overwrite the
  progress the tick had just saved, and two requests at the same moment could both start a
  job. A cancel request is now a marker file that the runner reads before every step, and
  jobs are created under a lock.
- The check alert lists errors before warnings; many warnings of an early phase can no
  longer push the errors off the list.
- The dashboard's append-only line shows the latest conclusive probe of the current
  repository. An inconclusive probe no longer replaces it with the wizard's answer, and a
  result for another repository is ignored.
- Uninstalling also removes the working directory selected with the
  `backups_for_restic_private_dir` filter.
- The recovery kit is refused (`ca_certificate_missing`) when the settings name an uploaded
  CA certificate that is missing, instead of leaving the certificate out silently.
- The texts of the admin page can be extracted for translation: every call passes the text
  domain as a literal. The settings page no longer claims that repeated checks read the
  whole repository; each check reads a random sample.
- Retention removes old snapshots again. Forget grouped snapshots by all their tags, and
  every snapshot carries the tag of the job that wrote it, so each was the newest of a group
  of its own and none was ever removed. Forget now selects this site's snapshots by host
  name, the plugin's tag and the site's tag and applies the policy to them as one group
  (`restic forget --host <host> --tag backups-for-restic,site:<hash> --group-by host`).
- Prune stays safe when requests fail or end at the wrong moment: a tree that was missing
  once is still walked when marking could not start over, so its packs are not taken for
  unused ones. An interrupted deletion resumes from its saved plan. After a lock gap, prune
  checks snapshots and indexes and starts marking over only if they changed; the next pass
  also finds packs whose index entries were already removed. An interrupted start no
  longer leaves an exclusive lock that blocks every client for 30 minutes.
- After the site was connected to an existing repository, the cleanup after each job
  removed the index cache of the repository in use, or left the caches of earlier
  repositories, because it went by a setting that only a newly created repository sets. It
  now goes by the repository whose key is cached.
- A check keeps what it has checked when a request fails (it writes its checkpoint at every
  change of phase and every five seconds), and a repository that this PHP cannot read
  (format 2 with compression, no zstd extension) is reported as that, not as damaged.
- A check no longer hangs on a pack that the host cannot read within one request: the
  requests that end while reading it are counted also when they read other packs first,
  and after three the pack is reported as unverified.
- A backup, check or forget that no request advanced for more than 30 minutes takes its
  repository lock again instead of refreshing a lock other clients no longer honour, and
  waits for a client that holds a conflicting lock by then.
- A backup or forget whose start fails after it took its lock releases the lock.
- The key derivation table is created with mode 0600.
- The test site of the integration suite runs without PHP's JIT, with which PHP 8.3
  crashed in backup ticks, and with WordPress's memory ceiling at the 128M of the
  acceptance criteria.
- A request that died while refreshing a repository lock no longer leaves the new lock
  behind; leftovers of the same run are removed and never block it.
- Forget counts `keep-within` back from the newest snapshot that is not in the future, as
  restic does; a snapshot from a wrong clock no longer makes it remove snapshots that
  restic keeps.
- Timestamps before the year 100, such as Go's zero time, are read exactly instead of as
  two-digit years, and dates that do not exist are refused.
- Pack headers longer than restic allows are refused.
- A prune that is retried after a failed read keeps every pack that a snapshot uses, and
  a long prune or check refreshes its lock while it works.
- Cancelling a forget releases its lock even when the retention settings became unusable.
- A `keep-within` value that is not a non-zero duration, and notification recipients that are
  not e-mail addresses, are rejected when the settings are saved instead of failing later.
- The file review refuses a replacement that the apply could not finish: a file owned by
  another user than PHP (`foreign_owner`), with a group that a file written by PHP cannot
  have (`foreign_group`), or with setuid, setgid or sticky bits (`special_permissions`). Before,
  the review called such a plan applicable, and the apply stopped at the first such file
  after earlier changes were made. Without the POSIX extension the review cannot check the
  owner, and the apply still stops before it replaces such a file.
- Undo of a file apply works after a directory that the apply created was deleted, for
  example a removed plugin: its entries are kept and the replaced files are restored.
  Before, the review stopped at the first entry below the deleted directory, and nothing
  could be undone.
- File apply and undo put each journal record and private copy on disk before the live
  change that depends on it, and each live change on disk before recording it, so that a
  power loss or a crash of the server during an apply leaves a state that the next attempt
  and the undo recognise, as a stopped request already did. Every job document and each
  new job directory are now put on disk as well, so that a power loss cannot bring back a
  document that lost what a running apply, undo or database exchange had recorded. Each
  saved job document waits for the disk, which on slow storage adds a few hundredths of a
  second to a backup step and to each file of an apply.
- A file apply stopped right after it created a directory can be resumed and undone:
  directories are prepared privately and renamed into place. Before, the retry could not
  prove that it had created the directory, so every entry below it failed and undo left it.
- Applying many files into one large folder no longer lists the folder several times for
  every file while the site is in maintenance: 500 new files beside 20,000 others needed
  27 seconds of checks before and less than half a second now.
- Undo of a file apply works entry by entry. It reverts every entry that is still as the
  apply left it and keeps every entry that changed since, with the reason, instead of
  refusing the whole undo for one changed entry. Before, one upload into a folder that a
  partial apply had created, or an update of one replaced file, made the apply impossible
  to undo, and after 24 hours the only copies of the original files expired with it. A
  file with another inode counts as unchanged when its content, size, type, permissions,
  owner, group and modification time are those the apply installed, so an apply can be
  undone after a later apply over the same files was undone, while a file written after
  the apply with the same bytes is kept. Kept entries stay undoable until the recovery
  window ends, and the apply is listed as partly undone. The undo checks every entry again
  just before it changes it and keeps, rather than fails on, one that changed after the
  review. Its result counts the entries by outcome, only what the undo itself reverted,
  and lists the kept ones with their reasons, also in `wp backups-for-restic undo` and for
  an undo that was cancelled or failed; a list that cannot be sealed, for example because a
  page of it changed on disk, is reported as unavailable while the counts are still
  recorded. A review that finds nothing left to revert records
  the apply as undone. An undo that could only keep changed entries is refused with its own
  message, and so is an undo confirmed before this version, which is reviewed again
  (`undo_review_required`). An undo step now advances at least one entry when it starts
  late, as an apply step does.
- While a live file change holds the site in maintenance, WP-CLI commands other than the
  plugin's own run with the site's plugins, as during a WordPress update. Before, every
  command started with this plugin alone: `wp plugin activate`, or deactivating this
  plugin, could leave every other plugin inactive, and `wp cron event run --due-now`
  dropped other plugins' due events. Requests that run cron themselves, as with
  `ALTERNATE_WP_CRON`, release only this plugin's events during the job.
- A file apply or undo keeps the maintenance marker that its first tick wrote and the
  uploads directory it was confirmed with. URL and upload-directory filters in the theme or
  a regular plugin, which do not load during maintenance, made its first change fail or
  refused uploads after other files had changed.
- The maintenance marker is readable like WordPress's own, so PHP processes of other
  system users show the maintenance page instead of a fatal error. It is also cleared from
  OPcache, so hosts without timestamp checks run the current job's marker. Where
  `opcache.restrict_api` keeps the plugin from clearing it, a host without timestamp checks
  stops the change before it touches a file (`maintenance_opcache`), and a host with them
  waits until OPcache has seen the new marker.
- A file job that the site's file modification policy stops reports `file_writes_refused`
  instead of "Another backup job is still running". A confirmation that finds the
  permission gone just before the job would start reports it too, instead of asking the
  administrator to wait for active work to finish.
- The database backup reads every table along its whole primary key, or a unique key over
  NOT NULL columns. Before, a table without a single-column integer key, such as
  `wp_term_relationships`, was read by position: a row added or removed while the backup
  ran made it skip a row that existed all along, or write one twice, which stopped the
  import with a duplicate key.
- The database backup reads each table's part of a backup step from one consistent
  snapshot, on a database connection of its own that it opens with the site's settings and
  checks before it uses it. A transient that is renewed while a table is read is no longer
  in the backup twice. Where no such connection can be opened, the backup reads through the
  site's connection as before, with its own settings around every query. A database drop-in
  that sends those queries, or the reads of a table, to another connection than the settings
  stops the backup with its own message instead of writing values in another time zone or
  character set. A table without a key is read with one statement, or marked in the
  database manifest and named in a warning of the job when it could not be.
- A table whose structure changes while it is backed up, for example by a plugin update, is
  backed up again from its beginning instead of producing a file that cannot be imported.
  A table that keeps changing stops the backup with its own message.
- TIMESTAMP values keep their instant: the database is read and written in UTC. Before,
  values from the other half of the year moved by the daylight saving hour on import.
- Text is read in utf8mb4 whatever `DB_CHARSET` is, so a site with a latin1 connection
  gets its stored bytes back and no longer loses characters outside latin1 as `?`.
- Table definitions with characters such as "х", "内" or "Å" in a comment, a default, an
  ENUM value or a column name are no longer damaged in the backup.
- FLOAT columns are backed up with every digit they hold.
- A row larger than 16 MiB as SQL no longer fails every backup: it is written as a statement
  of its own, and the database manifest records each table's largest statement. A table of
  large rows is read only as many rows at a time as fit into the PHP memory limit, so that
  every row that fits on its own is backed up; before, a page of such rows could end the
  request. A row too large for the PHP memory limit stops the backup with its own message,
  before it is read, and the log of the job names the table of a failed database dump.
- A table that another connection keeps locked, for example with `LOCK TABLES` or during
  a long `ALTER TABLE`, no longer holds a backup step for as long as the server lets a
  query wait for a lock, a year on MySQL and a day on MariaDB by default. The backup waits
  five seconds and then tries again later, with growing pauses; a table that stays locked
  stops the backup at the sixth try with its own message, which names the table.
- A row limit (`sql_select_limit`) that the server's settings or a plugin left on the
  database session no longer cuts the database backup short. It could leave out tables,
  columns, parts of a key or rows without an error.
- A private directory that `BACKUPS_FOR_RESTIC_PRIVATE_DIR` names through a symbolic link,
  as some hosts mount their data volume, is used by its real path. Large file previews failed there,
  and after one had failed, no restore staging expired any more. Expiry also goes on when
  the preview data or the undo workspace of one job cannot be removed.
- Restores run on hosts that disable `disk_free_space()` or do not report the free space:
  the job says so in a warning and stops only if a write fails. Releasing the lock of a
  finished restore can no longer cost its staged files, whatever goes wrong there.
- A full disk, a quota or missing permissions during a restore are reported as such
  (`staging_space`, `staging_write`), with what to do, instead of as damage in the
  repository; a selected path that the snapshot lacks has a message of its own
  (`restore_path_missing`).
- After the server clock was ahead and was set back, scheduled backups, prune and check
  go on at their next time instead of waiting until the clock catches up, and the notice
  about old backups comes again when it is due. One e-mail reports that the clock was set
  back, if failure notices are on.
- Cancelling a restore, or deactivating the plugin, while its ZIP is built or its
  database rehearsed ends only that work and keeps the staged files.
- A restore builds its ZIP only when the disk has room for it beside the staged files and
  a reserve; otherwise it says so, and the files can be downloaded one by one.
- The ticks of a restore use the index cache and no longer download the whole repository
  index again in every tick.
- The mutation gates counted mutants as killed that no test killed. Infection serves the
  mutated class through a file wrapper of its own, and the tests ran under it: `fsync()` fails
  on that wrapper, and it reports unreadable files and links to removed files as missing. So
  every test that writes a job document or journal record durably, reads an unreadable file or
  removes a dangling link failed or warned whatever the mutation. On `main` the file planner
  gate reported 98.48 % of its covered mutants killed (the CI run of `f0ea681`). With PHP's
  own wrapper, and with the planner tests that this release adds, it kills 88.99 %, and
  87.45 % counting code that no test reaches: below the 90 % it was meant to hold. The gates
  added in this release reported 98 % covered for file restore, 100 % for the apply jobs and
  99.87 % for ZIP downloads; they kill 81.84 %, 83.58 % and 84.98 %, and the database gate
  about 86 %, which varies between runs. The tests of a mutant now run with PHP's own wrapper,
  a canary fails a gate whose mutant processes cannot write durably, and the floors are the
  measured scores. Section 5.6 of `docs/04-development-and-quality.md` lists the escaped
  mutants by class.
- The database mutation gate counted mutants as killed that its tests did not kill. Its
  processes shared one database server, and the tests of the database exchange, which look at
  the whole server, could fail through what another process did there; on a slow runner its
  fixed limit of 60 seconds also timed out mutants that the tests let escape, and Infection
  counts a timeout as detected. Each process now runs against a server of its own, the test of
  a row batch beyond `max_allowed_packet` runs in the gate again, and the timeouts follow the
  time of the tests. On the same code the gate kills 80.69 % of its mutants and 84.59 % of the
  covered ones, where it reported 81.58 % to 81.86 % and 85.52 % to 85.81 %.
