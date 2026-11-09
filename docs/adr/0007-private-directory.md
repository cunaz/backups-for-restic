# 0007 · Private working directory, preferably outside the web root, verified actively
Status: accepted (revised for 0018)
Date: 2026-11-09

## Context
The engine needs a writable directory for job state and the backup ledger, encrypted caches
(index set, tree packs), the pack under construction, the scrypt table during an unlock,
restore staging and logs. WordPress' writable locations (`wp-content`, `uploads`) are
web-reachable; nginx ignores `.htaccess`. Shared hosting adds disk quotas.

## Decision
- Default location `WP_CONTENT_DIR/backups-for-restic/` (fallback: the uploads base
  directory when `wp-content` is not writable), created `0700` with `umask(077)`, containing
  `.htaccess` (`Require all denied` / `Deny from all`), `web.config` and an empty `index.php`.
- `BACKUPS_FOR_RESTIC_PRIVATE_DIR` may point outside the web root; the wizard and Doctor
  recommend it.
- Protection is **verified, not assumed**: the Doctor writes a random probe file and fetches
  its URL; a 200 response fails the check and blocks setup and backups until fixed.
- Content is ciphertext wherever possible: caches are stored as on the server or encrypted
  with the master key; packs under construction are ciphertext. Plaintext exists only in
  restore staging and database import files (explicit actions, `0600`, expiring) and in the
  scrypt table during an unlock (deleted immediately afterwards).
- The directory is excluded from backups except the virtual `spool/` paths of the database
  stream and the manifest.
- Size is bounded: cache cap (default 256 MiB), one pack temp file, job history by count;
  preflight checks free space.

## Consequences
- Works out of the box on Apache; on nginx the probe turns the recommendation into a
  requirement.
- All plugin writes are concentrated in one place that `uninstall.php` can remove.
- Disk quota on shared hosting is respected by design.

## Alternatives considered
- **System temp directory**: shared with other users and periodically cleaned.
- **Uploads directory by default**: always web-served.
- **Database-backed job store**: see ADR 0014.
