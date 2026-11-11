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

## As built (2026-11)
- What the directory holds today: `jobs/` with job state, event logs and the engine's
  checkpoints (the ledger is gone, [ADR 0014](0014-file-based-job-store.md)), `cache/` with
  the encrypted merged index, `kdf/` with the scrypt table and its checkpoint during an
  unlock, `probe/`, the uploaded CA certificate and the runner lock. Trees are not cached,
  and a pack under construction is held in memory, so there is no pack temp file. `tmp/`,
  `logs/` and `restores/` are created and still empty; restore staging and import files
  come with Milestone 3.
- The probe has three results for a directory inside the web root. An answer of 200 with
  the probe's content is the critical finding `exposed`: the wizard does not continue, and
  the preflight of a backup fails. An answer that shows the directory protected is reported
  as `protected_inside_web_root`. A probe that cannot run, because the site cannot request
  itself, is reported as `unverified` with the status "recommended" and blocks nothing: on
  such hosts the protection is assumed, not verified. Blocking there would shut out the
  hosts the plugin is made for; instead a probe from the administrator's browser, which can
  reach the site where the site cannot reach itself, is planned with the wizard's browser
  tests (phase 0.6 of Milestone 3).
- The wizard's script stops at a critical finding, and `POST /setup/complete` refuses with
  `environment_blocked` while the Doctor has one; the routes before it do not ask. Of the
  jobs, only the backup asks the probe before it starts; forget, prune and check do not.
