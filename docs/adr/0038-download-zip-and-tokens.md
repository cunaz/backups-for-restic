# 0038 · Downloads: a stored zip written by the plugin, and one-time tokens in a file
Status: accepted
Date: 2026-11-16

## Context
Package 2.4 lets an administrator download staged files: one file, or a selection as one
archive. Two things stand in the way. An archive of a large selection cannot be built in one
request, with the memory of one request, or with an extension the host may not have. And a
browser that starts a download itself sends no REST nonce, so the address of a download
needs a credential of its own that is worth nothing once it has leaked into a log.

This record covers the two parts that depend on nothing else, both in the WordPress-free
core. The routes that hand out and accept tokens and the job step that drives the writer
follow with the rest of package 2.4.

## Decision

### The archive
- `Storage\Zip\ZipWriter` writes the zip archive itself. Every entry uses method 0
  (stored): the content of a file is copied in pieces of one mebibyte, so memory does not
  grow with the size of a file or of the archive.
- The work is done in calls with a deadline, as a job step needs them. All state is in
  files beside the archive: `<archive>.part` is the archive being written,
  `<archive>.entries` collects the central directory records, `<archive>.state` is the
  checkpoint (JSON, replaced atomically). The checkpoint names how many bytes of the two
  files count, the last finished entry, and for a file being copied its place and the
  CRC-32 so far. It is written at least once a second and at the end of every call; bytes
  beyond it are cut off when work resumes. A request killed at any point loses the work
  since the last checkpoint and nothing else. A checkpoint that cannot be read or
  contradicts itself counts as none, and the archive is started again. So does a checkpoint
  whose working files are missing or shorter than it counts, which a removal cut short or a
  crash of the machine can leave: nothing is synced to the disk, so after a crash the small
  checkpoint may be there without the last bytes it counts. A finished archive gets its
  name only if it has the size the checkpoint names. Discarding removes the checkpoint
  first, so a request killed there leaves files that the next start clears.
- The archive gets its name by a rename after its end record is written. A file with the
  name of the archive is therefore always complete, and an incomplete one is recognised by
  its name. The writer takes a file with that name and no checkpoint beside it for the
  finished archive and does not look at the tree again. The job step therefore gives every
  build a name of its own, or removes the earlier archive before it builds another one
  under the same name.
- The local header of a file is written with an empty CRC-32 field, the content is copied
  once, and the field is filled in afterwards; that write is repeated harmlessly after a
  kill. The checksum of a file copied over several requests is kept as a number and joined
  piece by piece (`Storage\Zip\Crc32`, the method of zlib's `crc32_combine`). No hash
  context is serialized, so the checkpoint never passes through `unserialize()`.
- The tree is walked depth first, the names of each directory in byte order. The archive
  is the same however the calls are cut, which is what the tests compare. A directory gets
  an entry of its own, so an empty one survives.
- Zip64 fields are written where a size, an offset or the number of entries does not fit
  the classic field, and only there. The limits are arguments of `ZipRecords` so that tests
  reach them with kilobytes.
- Names are stored as UTF-8 with the language encoding flag. A name is refused, never
  changed, when it is not valid UTF-8, is absolute or starts with a drive letter, has an
  empty, `.` or `..` component, or holds a NUL or a backslash. Programs on Windows read a
  backslash as a separator, so `..\x` could leave the directory an archive is unpacked to.
  Changing such names instead could make two entries collide.
- The modification time is stored as MS-DOS time in UTC and exactly in the extended
  timestamp field. Modes are not stored: staged files are owner-only on the server, and
  the archive should unpack with the defaults of the computer it is unpacked on.
- The tree may hold directories and regular files with one link, which is what staging
  holds ([ADR 0036](0036-selected-file-staging.md)). Every entry is inspected with
  `lstat()` before it is listed or read, and an opened file is compared with what was
  inspected. A symbolic link, a second hard link or a special file ends the work. A file
  whose size or time changed since its header was written is reported; the tree must not
  change while it is archived.
- The caller names the tree and the archive, and the archive must lie outside the tree.
  The caller also keeps two requests from working on one archive, as the runner's lock does.

### The tokens
- `Storage\DownloadTokens` issues a token for a subject, an opaque string by which the
  caller names one download, such as a job and a relative path, or a job and the word
  `zip`. A token is 32 bytes from the `RandomSource` as 43 characters of base64url.
- A token is valid once and for one hour.
- The store is one file, `download-tokens.json`, in a directory the caller names, written
  atomically with owner-only permissions. A record holds the SHA-256 hash of the token, the
  SHA-256 hash of the subject and the expiry time. The token is returned to the caller
  once and is stored nowhere. A token has 256 bits of entropy, so a plain hash is enough;
  the subject is hashed so that a record has a fixed form whatever bytes a path has.
- Issuing and redeeming read, change and write the store under a `FileLock`. Redeeming
  compares the presented hash with every record through `hash_equals()`, removes the
  matching record and answers yes only after the store is written. Of two requests that
  present one token at the same moment, one is served. If the store cannot be written, the
  token is not accepted.
- A token presented for another subject is refused and stays valid for its own.
- Expired tokens are removed from the file whenever a token is issued or presented. At
  most 256 tokens are valid at once; when that number is reached, the token that expires
  first gives way, so a full store never blocks a download.
- A store that cannot be read as one counts as empty: every token in it is refused, and
  the next token issued replaces the file.

## Consequences
- A download needs no PHP extension. The archive is as large as the files in it, and it
  occupies that space beside staging until it expires with it.
- A tree with one name that cannot be stored yields no archive. Staging on a server that
  is not Windows keeps the bytes of a name as the snapshot has them, so a staged tree can
  hold such a name: one in Latin-1, one with a backslash, or one that starts like a drive
  letter. The writer refuses it when the walk reaches it, which may be after several
  requests of copying. The job step has to end the build then and report the name, which
  the exception carries percent-encoded in its context.
- The token of a download stays valid if the clock of the server is set back by up to five
  minutes after it was issued. A larger step removes the tokens issued before it, and the
  administrator asks for the download again.
- The token store lives on the file system of one web server. Where several servers share
  a file system without a working `flock()`, a token could be redeemed twice, which is the
  limit the other locks of the plugin have.
- Whoever holds the token within the hour can download once. The route that redeems it
  still has to build the path from the subject it checks, never from the token.

## WordPress delivery

A restore request with `archive: true` builds `selection.zip` in its own staging directory
after releasing the repository lock. The runner resumes the ZIP in subsequent ticks. The
staged files remain downloadable if an entry cannot be archived, and the job warning
identifies the refused name. Expiry removes the archive together with the restored files.

Before the first byte, the job compares what the archive needs at most with the free space
where the host reports it: the staged bytes once more, three times the size of the report
for the headers (a stored entry writes its name in its local header, its central record and
the spool of those records, with at most 228 bytes besides, and a line of the report holds
the name and more than a hundred bytes), and a reserve of 64 MiB for the site's other
writers. An archive that does not fit is not begun, and the job says so in a warning;
without a figure for the free space the archive is built and a full disk is noticed as
before. A request to cancel that arrives while the archive is built discards it and keeps
the staged files ([ADR 0037](0037-restore-job.md)).

An administrator requests a download with `POST /restores/<job>/download`. The response
contains a form that submits the one-time token and encoded subject to `admin-post.php`.
A grant of restored content needs the WordPress password and is recorded (last section).
The token store requires an exclusive lock. The handler checks the staging lifetime and
the file again on redemption, then streams the inspected handle as an attachment. The
download kinds are `file` with an absolute snapshot path, `archive`, `report`, and
`database_report` when a database rehearsal has published its result.

## Alternatives considered
- **PHP's zip extension (`ZipArchive`).** Not installed everywhere, which would make the
  download depend on the host ([ADR 0002](0002-runtime-dependencies.md)). It also writes
  the archive when it is closed, in one request, and offers no way to continue after a
  kill.
- **Compression.** Deflate costs processor time that a request with a short deadline does
  not have, and most of what a site stores in bulk (images, archives, fonts) is compressed
  already. A stored entry also has a size known beforehand, which keeps the header simple
  and the progress exact.
- **Data descriptors after the content instead of filling in the CRC-32.** Readers that
  stream an archive cannot tell where a stored entry with a descriptor ends.
- **A serialized hash context in the checkpoint.** Shorter, but the checkpoint would be fed
  to `unserialize()`.
- **Tokens in the database, as an option or a transient.** A transient may live in an
  object cache that other code reads, an option is part of every database dump the plugin
  itself backs up, and neither offers a compare-and-delete that two requests cannot both
  win. The private directory already holds everything a download serves.
- **A signed, stateless token (HMAC over subject and expiry).** Cannot be valid only once
  without a store of used tokens, which is the same file.
- **The REST nonce in the address.** It is valid for up to a day, for every route, and
  would end up in the access log of the server.

## As built: restored content needs the WordPress password (2026-11-20)

Staged files are site content, and among them can be `wp-config.php` and the database dump
of `spool/database/`. The eight salt constants of the one are the key material of the
secret store, and the options table of the other holds its ciphertexts: together they
give the repository password, the REST password and the cached master key, which the
recovery kit hands out only after the WordPress password was entered again. A grant for
them now asks the same.

- **What needs the password.** The kinds `file` and `archive`: a grant needs the WordPress
  password of the current user as `user_password` in the JSON body of the grant request,
  checked by the one confirmation that the kit and the live changes use, with its count of
  wrong passwords ([security](../03-security-and-threat-model.md) §5.5). An application
  password authenticates the request and is not enough. A kind that the route does not
  know counts as content, so that a new kind needs the password until it is classified.
- **What does not.** The kinds `report` and `database_report`. The restore report holds
  one line per staged entry with its quoted path, type, mode, time, size and link target:
  what the snapshot routes show to the same capability. The rehearsal's report holds the
  snapshot ID, the site address and prefix, table names, row counts and sizes, engines,
  character sets and collations, missing privileges, server versions and a digest of the
  imported content, but no row value. The page fetches it by itself for its preview.
- **Staging stays behind the capability alone.** Staging writes the copy into the private
  directory, owner-only, and gives nothing out; what leaves that directory is guarded. A
  restore job records the user who started it (`user` in its data), and the job view
  shows it.
- **The audit.** Before the token of a content grant exists, an entry with the time, the
  user ID and login, the restore job, the kind and the path is written to the state option,
  under its lock; if it cannot be written, no token is issued. The path is kept in Go's
  quoting (`Support\GoQuote`), the form of names in restic's tree JSON, so that line
  breaks, control characters, quotes and bytes that are not UTF-8 appear as escapes and
  cannot break the list, a JSON answer or a log line of an audit plugin; it is cut after
  1 KiB and marked with three dots. The last twenty are kept. `GET /settings` answers the
  latest five as `downloads`, and the Settings tab shows them beside the deliveries of the
  kit. The action `backups_for_restic_download_granted( $user_id, $job_id, $kind, $path )`
  fires after the entry is stored, with the quoted path. A refused request and a file that
  the restore does not hold are not recorded. The entry records the grant, not the
  download: whoever holds the token can redeem it once within the hour, and the handler
  that redeems it knows no user.
- **The form.** The staged restore's card has a password field for the two content
  downloads. The page sends the password in the JSON body of the grant request only and
  clears the field at once, whatever the answer; the reports send none.
