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

A job-owned WordPress maintenance file remains in place between mutation requests. A secret unique to that job admits the administrator's progress and cancellation requests to normal WordPress authentication. During the lease, cron releases only this plugin's events, and the controller boots without ordinary plugins and theme code. The marker and its private copy share an inode. Cleanup verifies the inode and independent content proof before removing it. Core's ten-minute maintenance expiry also restores ordinary plugin loading and cron after an extended driver outage. WP-CLI can recover a site whose restored extension prevents normal boot using `wp --skip-plugins=<affected-plugin> --skip-themes backups-for-restic undo <apply-job-id>`.

## Operational limits

The maintenance response covers requests that boot WordPress. Static files and upstream CDN caches follow their own serving policies. Directory checks and filesystem calls depend on host latency. A WordPress 7.1.4 fixture with 20,000 unchanged siblings, one 128 MiB staged replacement, its 96 MiB preimage and one new file produced 20,009 reviewed rows. Each worker started a fresh PHP 8.3 WordPress process with a 128 MiB memory limit and five-second work budget. The preview, apply, undo preview and undo took 15, 9, 1 and 2 ticks respectively. Peak worker memory was 61.4 MiB and the longest tick took 5.10 seconds. A separate run under concurrent load reached 10.5 seconds. The host needs space for the staged payload, private replacements, preimages and review indexes.

A native tmpfs capacity test used a 1,292,345,344-byte sparse live file occupying 4,096 bytes and a nine-byte staged replacement. The review estimated 1,292,349,440 additional data bytes on this 4,096-byte-block volume. Admission failed at 1,283,878,912 free bytes and succeeded for the same digest at 1,300,656,128. An independent 16 MiB allocation after confirmation caused preflight to stop before its first live change. The original retained its SHA-256, inode and block count.

## As built (2026-11)

- The undo review decides on absence first. A created file or directory that is gone, also
  below a directory that was removed since, is kept; a replaced file that is gone, or whose
  parent is no longer the recorded directory, is refused. Before, a removed parent made the
  review fail, so that none of the apply's entries could be undone.
- Every record of an entry's journal, the `attempted` count, the confirmed manifest and the
  `reverted` marker are written durably (`Storage\Files::write_durable()`): the temporary file
  is synchronised before its rename and the directory after it. The retained original is
  synchronised before the record that names it, the candidate with its attributes before the
  `ready` record, the restored original's attributes before the `restore` record, and the live
  directory after a publication or removal and before the record that completes it, also on
  a retry. Where PHP cannot open a directory or the file system cannot synchronise one, the
  rename keeps the order that the file system gives it. A created file whose completed
  record survived a power loss, while the unlink of its private name before it did not, is
  undone like an interrupted link.
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
  and the quarter second have passed. The index keeps at most 524,288 names as salted 64-bit
  codes, about 20 MiB; a folder that alone holds more is listed for every recheck, as before.
  Each publication and removal is preceded by a recheck.
- Residual risks. Between the last check and the rename or unlink, a process of the same user
  that maintenance mode does not stop, such as an SFTP client or a deploy script, can still
  change the live entry, and that change is lost without a copy. Keeping the displaced inode
  through a hard link was not adopted, because a replacement would then need hard links,
  which a create already needs but a replacement does not. A directory rename can likewise
  replace an empty directory that such a process creates at the same name in that moment. In
  a folder of more than 2,048 names, a second spelling of a name that another process
  creates in the same second as one of the worker's own changes can stay hidden from the
  rechecks for up to about one and a quarter seconds; on a case-insensitive file system the
  publication itself meets it, and a case-sensitive one keeps both names.
- Measured with PHP 8.3.37 on tmpfs: 500 new files beside 20,000 others took 27.2 seconds of
  rechecks before and 0.44 seconds with seven folder listings after.
- Each changed entry now waits for about eleven synchronisations, a replaced file for fourteen,
  and each reversed entry for three to six. On slow or network storage this lengthens the
  maintenance window of a large apply.
