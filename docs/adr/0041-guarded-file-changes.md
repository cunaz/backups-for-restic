# 0041 · Review, apply and undo staged files

Status: accepted
Date: 2026-11-20

## Context

On shared hosting a PHP request can end during a file copy. The administrator needs to review the actual live destinations, keep other site files, and recover changes after an interrupted apply. The restore input and undo copies contain plaintext site data.

## Decision

An apply starts from a completed private staging directory and its file report. On a fresh site, the snapshot's verified site manifest identifies the original content and upload directories. The destination installation supplies its own content and upload paths. Entries have separate source and destination names. WordPress bootstrap files, configuration, must-use plugins, drop-ins, the running controller and its private state require an offline recovery. A reviewed file can create or replace a regular file or create a directory. Existing files with identical content and unrelated live files stay in place.

The browser confirms the exact site's address, current WordPress password and the signed review. The worker rechecks the live file modification policy and every proposed change before entering maintenance. Small selections use an inline review. A selection with more than 100 reported entries, more than 8 MiB of file comparison work or a directory scan of more than 2,048 entries queues a read-only `file_plan` job. The job checkpoints the report offset, record counts and current content digest. Each digest covers the file size and an ordered sequence of fixed 1 MiB SHA-256 blocks. Existing inline manifests retain their original SHA-256 format.

A background plan stores presentation rows in private pages of 100. A second set of pages orders mutation references by destination depth. The sealed plan digest authenticates the page indexes and each reviewed page. Confirmation binds the completed plan job, the digest, the operation and its source. Each plan supports up to one million reported entries and one million names per indexed folder. Wide folders use a temporary disk index of folded and exact spellings. A directory edit invalidates the index. The apply preflight builds an independent index, and cleanup removes each worker's temporary data. The browser reads one page at a time. An unconsumed plan expires in one hour while its source staging is still available.

Every mutation records its intent, original inode, prepared replacement and prior file data under the apply job. The private directory and live parent must support publication on the same filesystem. The worker verifies content, ownership, permissions and parent identity around publication. Undo works backwards through the attempted entries, including the last entry of an interrupted worker. It preserves later modifications and newly added children. Staging and undo records remain pinned for 24 hours after an apply that attempted a live change. A queued undo can finish after its initial recovery window.

The review shows estimated additional file-data bytes and the free bytes reported for the private journal volume. Each created file uses one private candidate. A replacement also retains a dense copy of the old live bytes. The calculation rounds each copy to the filesystem's reported block size where available and takes no credit for superseded inodes, whose blocks can remain shared, open or snapshotted. Undo moves the retained preimage back into place or removes a created entry, so it needs no additional file payload. The worker rechecks the total before its first live change, then the remaining estimate before each copy or resumed copy. Known insufficient space blocks the operation. Hosts can lack a free-space probe, and quotas, filesystem metadata, compression and concurrent allocations can affect actual writes. A partial apply retains its journal for undo.

A job-owned WordPress maintenance file remains in place between mutation requests. The job's first tick renders it in a normal boot. Later ticks boot under the marker and check the private copy against the content proof recorded then; they do not render it again. The marker and its private copy share an inode. It is readable by every user, like core's own marker, because PHP processes of other system users read it too. It holds no secret: the grant is a hash. Cleanup verifies the inode and independent content proof before removing it. The marker is invalidated in OPcache after it is linked or renewed and before it is removed, because every job uses the same name. Where the process has an opcode cache and OPcache refuses, as `opcache.restrict_api` does outside its path, a server without timestamp checks could keep running an earlier marker, core's among them, so the job stops before its next live change (`maintenance_opcache`). With timestamp checks OPcache notices the new marker within `opcache.revalidate_freq` seconds: the tick that published it waits that long plus one second, at most five, and a longer interval stops the job as well. Core's ten-minute maintenance expiry also restores ordinary plugin loading and cron after an extended driver outage.

The marker admits workers: `wp-cron.php` and the plugin's own WP-CLI command. The command is recognised by its first word on the command line, read with WP-CLI's own rules: a leading alias and every `--name` or `--name=value` parameter, such as `--path`, `--url`, `--user`, `--skip-plugins`, `--skip-themes` and `--allow-root`, are skipped. Every other WP-CLI command boots as during core's own maintenance, which WP-CLI ignores, so it runs with the site's plugins and options. A secret unique to the job admits the administrator's progress and cancellation requests to normal WordPress authentication. A `rest_route` in a form body is refused, because WordPress would route the request by it. An admitted request boots without ordinary plugins and theme code. It releases only this plugin's cron events, also when it runs cron itself under `ALTERNATE_WP_CRON`. It cannot store the substituted plugin list: `update_option()` keeps the stored `active_plugins`, so `activate_plugin()`, `deactivate_plugins()` and the REST plugins endpoint change nothing there. WP-CLI can recover a site whose restored extension prevents normal boot using `wp --skip-plugins=<affected-plugin> --skip-themes backups-for-restic undo <apply-job-id>`.

The job records the uploads root that the review used and plans every tick with it. Every tick checks again that WordPress permits file modifications and, for a job confirmed in the browser, that the confirming administrator still has the backup capability and `update_core`. It reads the account from the database, not from the object cache of a process that runs many ticks, such as `tick --until-idle`. A job started on the command line has no WordPress account, so only the host policy applies to it.

## Operational limits

The maintenance response covers requests that boot WordPress. Static files and upstream CDN caches follow their own serving policies. Directory checks and filesystem calls depend on host latency. A WordPress 7.1.4 fixture with 20,000 unchanged siblings, one 128 MiB staged replacement, its 96 MiB preimage and one new file produced 20,009 reviewed rows. Each worker started a fresh PHP 8.3 WordPress process with a 128 MiB memory limit and five-second work budget. The preview, apply, undo preview and undo took 15, 9, 1 and 2 ticks respectively. Peak worker memory was 61.4 MiB and the longest tick took 5.10 seconds. A separate run under concurrent load reached 10.5 seconds. The host needs space for the staged payload, private replacements, preimages and review indexes.

A native tmpfs capacity test used a 1,292,345,344-byte sparse live file occupying 4,096 bytes and a nine-byte staged replacement. The review estimated 1,292,349,440 additional data bytes on this 4,096-byte-block volume. Admission failed at 1,283,878,912 free bytes and succeeded for the same digest at 1,300,656,128. An independent 16 MiB allocation after confirmation caused preflight to stop before its first live change. The original retained its SHA-256, inode and block count.

## As built (2026-11)

- Protected, configuration and startup locations are compared in every spelling that a
  canonical live target can have. Repeated and trailing slashes are dropped, and the deepest
  existing ancestor of each location is resolved, so a `WPMU_PLUGIN_DIR` that WordPress
  derives with `//`, or a content directory configured through a symbolic link, still names
  the directories WordPress loads from. A location that is not an absolute path without `.`
  or `..` segments makes the plan unavailable (`protected_path_invalid`).
- Names are compared with protected paths name by name, under a key that folds fully, drops
  default-ignorable code points, maps the dotless i and the two canonical singletons that
  equal ASCII punctuation, and applies NFKC_Casefold where the intl extension is loaded. The
  key is at least as loose as ext4 and f2fs casefolding, HFS+, APFS and NTFS. Without intl it
  is still exact for ASCII names. A target inside a live root whose name and a protected
  name both keep other characters, and agree in what canonical equivalence leaves alone, is
  refused (`unverified_name`). A name that reads as `.` or `..` once default-ignorable code
  points are dropped is an invalid path. Duplicate sibling names are still detected with
  simple case folding.
- No apply writes a snapshot's spool. A directory named `spool` is skipped
  (`snapshot_spool`) in ordinary and fresh-site applies, wherever the snapshot recorded it,
  when it holds what only a backup writes: a `manifest.json` that opens with the site
  manifest's format, or a `database/` directory with a dump manifest or a file that starts
  with the dump's format line. Every file of a dump starts with that line, so a selection
  of a single table file is recognized too. Another directory named `spool`, also one with
  a `database/` directory of its own, is applied as usual. The check lists at most
  65,536 names of a database directory and reads at most 1,024 files named like a dump's,
  so that one directory cannot use up a request. What cannot be decided counts as the
  backup's: a file that cannot be read, and a database directory in which neither bound
  showed a dump. The check reads the staged
  files because an ordinary restore does not record the manifest locator; it also covers
  older snapshots without one and copies of a spool at other paths. The database rehearsal
  reads the dump from staging.
- The review refuses a replacement that the apply could not finish: a live file with setuid,
  setgid or sticky bits (`special_permissions`), a file whose owner is not the process's
  effective user (`foreign_owner`), and a file whose group a copy written by the process
  cannot have (`foreign_group`). A copy can have the groups the user belongs to and the
  group of the private directory, in which the apply builds it: below a setgid directory,
  and with BSD group semantics, a new file takes its directory's group even when the user
  is not a member. Root can give any group. A file that an apply created has one of these
  groups, so the checks after publication and during undo accept it. The identity comes
  from the POSIX extension. Without it, or where a host disables its functions, the
  review cannot know the identity, and the check before each replacement remains the only
  one. Refusing every replacement instead would also refuse the undo of each file an apply
  created, because the undo review asks the same question.
- A protected location that resolves to the filesystem root makes the plan unavailable
  (`protected_path_invalid`), as an invalid spelling does.
Ticks under the marker boot without the theme and ordinary plugins. Filters that these ticks consult take effect only from `wp-config.php` or a must-use plugin: `file_mod_allowed`, `backups_for_restic_capability`, filters that grant the confirming administrator a capability, and `backups_for_restic_private_dir` or `upload_dir` where they move the private directory. A restriction in a regular plugin or the theme applies at confirmation and in preflight. A capability that only such code grants stops the job at its first tick under the marker, before that tick changes a file. A change to a role's own definition reaches a process that runs many ticks only when it starts again. OPcache caches the marker per web server, and a command-line tick checks only its own cache, which says nothing about the web server's. With timestamp checks disabled, a web server that compiled an earlier marker runs it until one of its own ticks invalidates the name. A job driven only from the command line can then leave the browser's progress requests on the maintenance page, or, if the earlier marker was core's own with its long-passed time, let requests boot normally while files change.
