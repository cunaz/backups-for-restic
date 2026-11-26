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
- The undo review decides on absence first. A created file or directory that is gone, also
  below a directory that was removed since, has nothing left to revert
  (`removed_since_apply`); a replaced file that is gone is kept (`deleted_since_apply`), and
  so is an entry whose parent is no longer the recorded directory (`parent_changed`).
  Before, a removed parent made the review fail, so that none of the apply's entries could
  be undone.
- Every record of an entry's journal, the `attempted` count, the confirmed manifest and the
  `reverted` marker are written durably (`Storage\Files::write_durable()`): the temporary file
  is synchronised before its rename and the directory after it. The retained original is
  synchronised before the record that names it, the candidate with its attributes before the
  `ready` record, the restored original's attributes before the `restore` record, and the
  live directory after a publication or removal and before the record that completes it,
  also on a retry. Where
  PHP cannot open a directory or the file system cannot synchronise one, the rename keeps
  the order that the file system gives it. A created file whose completed record survived a
  power loss, while the unlink of its private name before it did not, is undone like an
  interrupted link.
- A new directory is prepared in the entry's private item directory with the permissions that
  `mkdir()` in place gave: the reviewed mode narrowed by the umask and, below a set-group-ID
  parent, that group and the bit where the worker may set the group. Its inode is recorded in
  the `ready` record before it is renamed into place. A retry after a kill at any step finds
  the inode either still private or published and completes the entry; undo keeps a
  directory that is still private and removes only the published one.
- The worker's rechecks in one request share an index of the names in the folders of both
  chains (`Restore\LiveSpellings`). Each recheck still reads the identity of every folder on
  the chains and checks the entry itself; a folder is listed again when its identity
  changes. A listing is kept indefinitely only if it began a quarter of a second after the
  second of the folder's last change, because Linux can stamp a change with the previous
  second just after PHP's clock has passed it. A folder of up to 2,048 names is listed again
  after each of the worker's own changes, as every recheck listed it before. A wider folder
  learns the worker's own changes and is trusted only until the second of its last change
  and the quarter second have passed. A wide folder that already looks settled right after
  one of the worker's own changes, because its change time did not move with it, is listed
  again at the next recheck. The index keeps at most 524,288 names as salted 64-bit codes,
  about 20 MiB; a folder that alone holds more is listed for every recheck, as before. Each
  publication and removal is preceded by a recheck.
- Residual risks. Between the last check and the rename or unlink, a process of the same
  user that maintenance mode does not stop, such as an SFTP client or a deploy script, can
  still change the live entry, and that change is lost without a copy. Keeping the displaced
  inode through a hard link was not adopted, because a replacement would then need hard
  links, which a create already needs but a replacement does not. For a removal, moving the
  entry into the private item directory with a rename and checking the moved inode before it
  is deleted would make the removal itself act on the verified inode, and put anything else
  back with a no-clobber link; it costs a rename, a private unlink, one more synchronisation
  and a journal state for an entry that a stopped worker left moved aside, and was not
  adopted in this change. A restore cannot be made to act on the verified inode that way:
  PHP has no atomic exchange of two names, and moving the live file aside first would leave
  the name missing for the site until the original is in place. A directory rename can
  likewise replace an empty directory that such a process creates at the same name in that
  moment. In a folder of more than 2,048 names, a second spelling of a name that another
  process creates in the same second as one of the worker's own changes can stay hidden from
  the rechecks for up to about one and a quarter seconds; on a case-insensitive file system
  the publication itself meets it, and a case-sensitive one keeps both names. On a network
  file system whose clients cache directory attributes, a change made on another host can
  leave a folder's identity unchanged for as long as that cache holds it. A directory that
  someone removes and creates again can get the recorded inode number on a file system that
  reuses them; with the recorded permissions, owner and group, and while it is empty, the
  undo removes it as the one that the apply created.
- Measured with PHP 8.3.37 on tmpfs: 500 new files beside 20,000 others took 27.2 seconds of
  rechecks before and 0.44 seconds with seven folder listings after.
- Every job document is written durably as well (`JobStore::save()`), and a new job directory
  is synchronised in `jobs/` before its first document is written. Without that, a power loss
  could bring back an older `job.json`, for example one without the manifest hash and the
  `applying` step that the apply records before its first live change, while the journal on
  disk shows a partial apply, and undo could no longer read it. Every save is durable, not
  only the saves that precede a live change: an empty document makes its job unreadable in
  any case, and the saves that precede a live change or a table exchange are spread over
  several job types. A backup, restore or maintenance tick saves its document twice; an
  apply or undo tick saves it once for each finished entry and twice more.
- Each changed entry now waits for about eleven synchronisations, a replaced file for fourteen,
  and each reversed entry for three to six, plus two for the job document. On the ext4 disk of
  the measurement machine a durable job document took about 6 ms against 0.14 ms for an
  atomic one, and a created file spent about 29 ms in its own synchronisations. At 10 ms per
  synchronisation, as on slow or network storage, the document adds about 40 ms to a backup
  tick and 20 ms to each applied entry, and the maintenance window of a large apply grows
  accordingly.
- Undo decides entry by entry. An attempted entry that still is what the apply left there is
  reverted: a replaced file gets its original back, a created file or directory is removed.
  Every other entry is kept with a reason, and one kept entry no longer makes the whole undo
  inapplicable: `changed_since_apply` (content, type, size, permissions, owner or group
  differ, or cannot be established), `deleted_since_apply`, `parent_changed`, `new_children`
  (a created directory holds an entry that the undo keeps, something added after the apply
  or a kept entry of its own; its other entries are still removed), `destination_changed`
  (the site now maps the snapshot path to another live path, or to none because its layout
  would map it ambiguously), the reason of the site's file policy where it no longer permits
  the change (for example `protected` or `unwritable_destination`; the latter also where the
  folder that holds a created folder, or a created file whose private name remains, no
  longer lets the worker change its names, which the policy does not ask about there), and
  `original_unavailable` (the retained copy is damaged). An entry with nothing left to
  revert is listed as kept with `not_applied`, `already_undone` or `removed_since_apply`; it
  counts as `unchanged`, not as kept, and its journal record stays as it is: only an entry
  that the undo reverted is recorded as undone, so that one that someone else removed and
  that comes back as the apply left it is reverted by a later undo. An entry is missing only
  where its name is missing from a canonical folder that the worker can search and that is
  the entry's recorded parent, or below a folder whose name is missing in that sense: below
  a folder without search permission, where `file_exists()` answers false for a file that is
  there, the entry is kept as `changed_since_apply`; below a link or another folder put in
  place of its own, where the folder that holds it may be elsewhere, as `parent_changed`;
  and a journal record that cannot be looked up stops the undo instead of counting as an
  entry that was never attempted. The review, inline and background, shows each entry's
  action and reason and the totals by action. A review that finds every entry with nothing
  left to revert, as after an undo that stopped after its last revert or once the kept
  entries were removed, records the apply as undone and writes the `reverted` marker, while
  no job is active, and says so (`undone`) instead of offering an undo that would be
  refused; a background review counts these entries in its result and decides when its first
  page is read. Before it writes the marker, it decides every entry against its live path
  again: an entry that came back since the review, a check that does not finish within two
  seconds, such as one that verifies a large file, and anything it cannot read leave the
  apply eligible. An undo with nothing to revert that still keeps a changed entry is refused
  (`undo_nothing_to_revert`): WP-CLI says so after its review, and over REST such a review
  is not applicable and gets no confirmation token, so the admin page shows its totals and
  offers no undo. Before, a partial apply stopped by `file_space` could become
  impossible to undo through one upload into a folder that it created, and the journal with
  the only copies of the original files expired with its window.
- What can be known about one entry keeps that entry with a reason, and the undo goes on
  with the next one; the reviews, inline and background, the worker's last check and the
  cleanup of a stopped undo read an entry by the same rule. Only three things stop an undo:
  a journal record that is damaged or cannot be looked up; a write of the plugin's own
  records or private copies that fails, such as a journal record, the attributes of the
  retained original, the list of kept entries or the job document; and a refusal of the file
  system that no check could foresee, a rename or unlink refused after every check passed or
  an `rmdir()` refused while the folder holds no name to read. A review stops as a whole
  only for what concerns every entry: a site layout or protected location that is invalid,
  or a staged tree, apply manifest or sealed plan that is gone or damaged. A created
  directory that a review cannot read, or that is gone when its names are read, is kept as
  `changed_since_apply`, as the worker's last check keeps it, and one whose interrupted
  reading cannot find its place again as `new_children`. Before, such a directory failed a
  background review with an internal error, and the inline review kept an unreadable one as
  `new_children`; the name checks of the site policy that cannot read a folder answer
  without a PHP warning, which a debugging site could print into the review's reply. The
  index of names that a background review keeps for a folder with more than 2,048 names
  answers, in an undo review, a folder that is gone, cannot be read or changes while its
  names are read with a spelling that may be there, so that the site policy keeps the entry
  as `ambiguous_destination` (or `ambiguous_source`) and the review goes on; before, the
  review failed, also for one upload into such a folder during its scan. A preview still
  fails there, and a damaged index fails in both.
- Whether an entry is still what the apply left there is decided by content and attributes,
  not by the inode alone. The inode that the apply published, with the attributes it
  recorded, counts as before while its content has the reviewed digest. A regular file with
  one link and another inode counts when its size, which the `ready` record keeps since
  this version, and the attributes that the record already kept for the candidate (type,
  permission bits, owner, group and modification time, the `installed` digest) are those of
  the apply's candidate, and its content has the digest of the staged source. Stacked
  applies compose this way: the undo of a later apply over the same file publishes its
  retained copy as a new inode with the time that the earlier apply installed, and the
  undo of the earlier apply then restores its own original; an undo of the later apply
  that stopped before or after its rename and was resumed leaves the same state. The
  modification time is compared because a file written after the apply has a later one,
  also with the same bytes: a re-upload of the same image or a deploy of the same version
  is kept, where it would otherwise be removed or overwritten with the saved original. The
  apply installs a copy that it wrote itself, so the installed time is the time of the
  apply, not the snapshot's time that the staged copy has. A file that a tool puts back
  with exactly the installed time, permissions, owner, group and bytes is not told apart.
  An entry recorded by an earlier version has no recorded size, so only its own inode
  counts. A different size keeps the entry without reading it, so a file with another
  inode costs at most the installed size to verify: in the digest format of its review, a
  plain SHA-256 for an inline review, whose sources are at most 8 MiB, and the ordered
  1 MiB block digest for a background review, which resumes across ticks.
- The link count belongs to that state: the apply leaves one link, or two while the private
  name of an interrupted create remains. A file with another count, the installed inode as
  well as another one, is `changed_since_apply`, decided like the size before the site policy
  is asked, which would refuse it as `type_conflict`. The answer therefore does not depend on
  inode numbers. ext4 gives a file created after the installed one was freed the recorded
  number, and a file written in the same second with the installed permissions, owner and
  group then passes for the installed inode; it is reverted only with the installed link
  count, size, attributes and content, as any other copy is, but its reason can be
  `undo_journal`.
- The worker reads the identity of the live file, which covers its device, inode, owner,
  group, mode, size and both change times, before it verifies the content. It compares it
  after the verification and once more as the last check before the rename or unlink, after
  the folder and policy rechecks and, for a restore, after the record that precedes the
  rename; a difference keeps the entry. Two gaps remain, and the checks do not close them.
  The times have whole seconds: a write in place that keeps the size and falls in the same
  second as a change the checks already saw leaves the identity as it was, after the content
  was read; the apply has the same gap. And the rename or unlink acts on the name, not on the
  verified inode: a process that replaces the file between the last check and that call,
  with another inode and other times, has its file replaced or removed instead, as the
  residual risks above describe; the same holds for an empty directory put in place of a
  created one before its `rmdir()`. A created directory is removed only while it is empty,
  which `rmdir()` itself enforces: a name that arrives in it after the last check keeps it
  as `new_children`, as at the check, and the undo goes on with the next entry. Only a
  refusal of `rmdir()` that leaves no name to read in the directory stops the undo.
- The confirmed review binds the exact set of entries. Its digest covers every entry with
  its action and reason. An inline undo stores the reviewed decisions with its job, a
  background one reads them from the sealed review, whose header now also carries the
  totals by action; an undo review sealed before has none and is reviewed again. A
  background review shows a created directory for removal only if every entry in it is a
  removal in the same review: a row that it reviews again, after a tick whose document a
  stopped worker did not write or from a checkpoint of an earlier version, which starts
  over with the refused count cleared, withdraws the removal that it claimed before. The
  worker reverts only entries that the review showed for a revert. It checks each one again
  immediately before it touches it and keeps one that changed since the review, counted in
  `changed_after_review`, instead of failing the job. An entry that the review showed with
  nothing left to revert is checked against its live path as well and counts as `unchanged`
  only while that still holds; one that came back since is kept, not reverted, and counted
  in `changed_after_review`, so that the undo does not end the window while that entry
  could still be reverted. Its preflight therefore no longer
  rechecks every live entry before maintenance; it checks that the confirmed review belongs
  to the apply and its manifest. An inline undo queued by an earlier version has no stored
  decisions, and a background one a sealed review without totals, so nothing says which
  entries the administrator saw as kept: both are refused with `undo_review_required`,
  before maintenance or, if that version's worker had begun, before its next entry, and the
  undo is reviewed again.
- Kept entries stay undoable within the 24-hour window, which does not change. A kept
  entry's journal record and retained copy are left as they are, and only an undo that kept
  no entry, or a review that finds nothing left to revert, writes the `reverted` marker
  that ends the window early. Otherwise the apply stays listed for undo and pinned, and a
  later undo of the same apply reviews every entry again, for example after a later apply
  over the same files was undone; what an earlier undo reverted is `already_undone`. The
  apply's document records `undo_state` (`undone` or `partly_undone`), `undo_kept` (the
  number of entries the last finished undo kept, null after an undo that stopped after a
  revert before its document counted every entry) and `undo_job`; the undo list shows this
  state. An undo that is stopped once its document counts every attempted entry of its
  apply, by a request to cancel that comes after the worker counted the last entry and
  before the runner saved the step after it, or by a failure of its recording, is recorded
  as a finished undo is: undone, with the `reverted` marker, when it kept nothing, and
  partly undone with the number of kept entries otherwise (2026-11-26). Before, its cleanup
  recorded it as partly undone without a number, also when it had reverted everything, and
  the undo list offered to revert the rest until a review found nothing left and retired
  the apply. The cleanup decides from the document alone and changes no live file: a
  cursor short of the apply's attempted entries, counts by outcome that do not add up to
  it, or a journal whose count cannot be read leave the apply as a stopped undo leaves it,
  as does an undo that stopped before it counted its last entry, whose entry at the cursor
  no record proves reverted: partly undone without a number once the undo's document
  counts a reverted entry, and otherwise in the state it had, which is `applied` for a
  first undo that only kept entries or reached none. A review decides those against the
  live files. If the `reverted` marker of an apply that a stopped undo reverted completely
  cannot be written, the cleanup records the apply the same way and says so in the job's
  log: without its marker the apply is not undone, and an undo that reverted files does
  not leave it without a record.
- An undo's result counts the entries by outcome, `restored`, `removed`, `unchanged`, `kept`
  and `changed_after_review`, and names `apply_state`. The kept entries with their reasons
  are written to a paged list in the undo job's directory, in pages of 100 that a sealed
  summary authenticates as a background review's pages are, and served by
  `GET /jobs/{id}/kept-entries`. The route answers `not_found` only for an ID that no job
  has; for a job that exists it says why there is no list: `not_an_undo` for a job of
  another type, `undo_not_ended` for an undo that is queued or running, and `job_unreadable`
  for a document that this version cannot read (2026-11-26; before, each of them was "The
  job does not exist."). The admin page asks only for the list of an undo that has ended,
  and WP-CLI only for the undo that it has just run and waited for, so neither meets these
  answers in its own course. WP-CLI `undo` prints the totals of its review, refuses an
  undo with nothing to revert, and afterwards prints the kept entries and the counts, as the
  admin page shows them, also for an undo that was cancelled or failed. Neither says that a
  kept entry changed after the apply unless its reason is `changed_since_apply`. Each
  outcome is on disk before the job document that counts it: a kept entry's page is written
  durably, a revert's journal record is. A restarted tick repeats only the entry that the
  document does not count yet, and a kept entry is written again at the same place in the
  list. The job document is the one commit point, and it names what it counts: with the
  number of kept entries it stores `kept_page_digest`, the digest of the version of the last
  page that holds them, computed from the bytes when they were written (`KeptList`). The
  list holds four invariants. I1: at every moment the files on disk hold at least the
  entries that the document counts, and no write replaces a version that a count names; each
  kept entry writes its page as a new version named by the number of entries on it
  (`rows-0.3`), a retry writes the uncounted version again, also with another reason, the
  next entry writes a version with one more, and only versions below the counted one are
  removed. I2: the digest of a full page enters the index of page digests only once the
  document counts that page, and it is the digest that the document held, not one read from
  the file later; every slot is a digest that a saved document held. I3: sealing reads one
  page, the last counted one, and the index, whatever the length of the list. I4: a counted
  page that changed on disk after it was written is refused: the last page by the seal, and
  by the next entry that builds on it, against the digest that the document names; every
  other page when it is read, against its slot. The entry at position c, while the document
  counts c entries with the digest d, is written in this order: when c begins a page p, the
  index gets slot p-1 = d unless it holds it already, replaced as a whole on disk; the
  page's version with the new entry is written on disk on top of the counted version, whose
  digest must be d; the version below the counted one is removed; then the document is saved
  with c+1 and the new digest. A stop between any two of these leaves either the earlier
  document with an index slot that it names and an uncounted version, or the new document,
  so that a cancelled or failed undo seals its list with the counted entries. The seal gives
  the index the last page's digest as its last slot and writes the summary; a seal after a
  stop of an earlier one writes the same summary. A kept entry costs one more synchronised
  write, its page version, and the index one more on each page boundary. A list that cannot
  be sealed, such as one whose counted page changed on disk, does not keep the undo from
  recording its counts and the state of its apply: the result has no list, the job's log
  says why, and `GET /jobs/{id}/kept-entries` answers 409 `kept_list_unavailable` and WP-CLI
  says that the list is unavailable, instead of showing an empty list. A sealed list of which
  a part is missing or was changed since is answered the same way, and WP-CLI still prints
  the recorded counts and how the undo ended, without saying that the kept entries are
  listed. Neither points to a new undo preview for the reasons: a preview decides every
  entry as it is now, so it shows what can be reverted within the window, not why the
  earlier undo kept an entry. Before, the seal read
  and hashed every counted page as it found it, so that a page changed on disk was sealed as
  if the undo had written it, a long list had to be read in one request, and a seal that
  failed left the undo without a result. Tests stop an undo with several kept entries, and
  one across the end of a page, at every synchronisation, rename and removal of the list and
  of the document, and again at every one of a retry with another reason, and read the list
  after each stop.
- The result counts what this undo did, and a retry counts a revert as its own only from a
  record of this undo that proves it. The record that completes an entry names the undo job
  that reverted it, and so does the `restore` record before a restore's rename; the retained
  inode back at the live path shows that the rename happened, because only that rename puts
  it there. A retry of the same undo that finds the entry reverted under such a record
  counts it as restored or removed, also when the document did not count it before the
  worker stopped. A cancelled or failed undo does not retry the entry: its cleanup reads the
  record of the entry at the cursor, the only one that the worker can have reverted without
  the document counting it, by the same rule, completes a `restore` record that the retained
  inode proves, and counts the revert before the result is recorded, without a change to the
  live site; an entry whose record cannot be read is logged and not counted. Before, such a
  revert was missing from the result, and an apply whose only revert it was stayed listed as
  applied with its original already back. A removal has no record before its unlink or
  `rmdir()`: a record written there would say the same after a stop before the removal, when
  someone else may remove the entry, as after one between the removal and the completing
  record. A retry that finds the entry gone without the completing record therefore counts
  it as `unchanged`, as it counts an entry that someone else removed after the review or
  that an earlier undo reverted; the first case undercounts a removal that it did make.
  Before, the worker counted an entry that it found reverted by the action the review had
  shown, so a file that someone deleted between the review and the worker counted as
  removed.
- An undo tick advances at least one entry, also one that begins after its deadline, as an
  apply tick does; the verification of a large file still yields after a block. Before, a
  tick that began late made no progress, and with a budget of zero none at all.
- What ends a preview, apply or undo is named for what it is (2026-11). A check that finds
  a live or staged file, or the site's file policy, changed after the review raises
  `file_changed_after_review`, before the apply's first change as between two of them;
  staged files that are gone `file_staging_gone`; an apply past its window
  `undo_unavailable`; a preview past its hour `file_preview_expired`; another update in
  maintenance mode `maintenance_busy`; a marker that cannot be published or kept
  `maintenance_failed`; a site that cannot leave maintenance mode `maintenance_stuck`.
  Every other plugin exception that ends one of these jobs, such as a damaged private
  record or a file operation that the file system refused, gives the job's own code
  (`file_preview_failed`, `file_apply_failed`, `file_undo_failed`) and its message in the
  log. Before, the codes of the exception classes reached the page: "Another backup job is
  still running" for a file that changed, "This server cannot run the backup right now"
  for a refused write, which sent the administrator to an Environment tab that was all
  green. Retries do not change: they follow the exception's class or context code. A
  failed or cancelled apply shows the changes it completed of those it attempted, and that
  they can be undone.
- Staged files that an undo needs are not deleted, and the refusal says so and until when:
  `restore_kept_for_undo` with the end of the window of the latest apply of them, or
  `restore_in_use` while a job that uses them runs. Before, both were "This restore is
  still running".
- A review shows its entries by action above the confirmation (`actions` of an apply
  review, `totals` of an undo review): a background review is read one page of 100 at a
  time, so the confirmation can come before the last page. An apply preview counts its
  rows by action as it builds them and seals the counts in its header; one that a version
  without them began seals none and shows none.
- After a reload, the admin page follows a background preview of this tab only while it is
  queued or running. A preview that it finished before is shown with the time it was
  finished, a control that inspects the live site again, and the confirmation, which the
  worker checks entry by entry against the live site in any case. A confirmation whose
  answer is lost or is no JSON is looked for by its job's type and the time of the review
  (`reviewed_at`), and followed if it started.
Ticks under the marker boot without the theme and ordinary plugins. Filters that these ticks consult take effect only from `wp-config.php` or a must-use plugin: `file_mod_allowed`, `backups_for_restic_capability`, filters that grant the confirming administrator a capability, and `backups_for_restic_private_dir` or `upload_dir` where they move the private directory. A restriction in a regular plugin or the theme applies at confirmation and in preflight. A capability that only such code grants stops the job at its first tick under the marker, before that tick changes a file. A change to a role's own definition reaches a process that runs many ticks only when it starts again. OPcache caches the marker per web server, and a command-line tick checks only its own cache, which says nothing about the web server's. With timestamp checks disabled, a web server that compiled an earlier marker runs it until one of its own ticks invalidates the name. A job driven only from the command line can then leave the browser's progress requests on the maintenance page, or, if the earlier marker was core's own with its long-passed time, let requests boot normally while files change.
