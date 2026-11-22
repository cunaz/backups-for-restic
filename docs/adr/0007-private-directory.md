# 0007 · Private working directory, preferably outside the web root, verified actively
Status: accepted (revised for 0018 and 0034)
Date: 2026-11-09

## Context
The engine needs a writable directory for job state and the backup ledger, encrypted caches
(index set, tree blobs), the pack under construction, the scrypt table during an unlock,
restore staging and logs. WordPress' writable locations (`wp-content`, `uploads`) are
web-reachable; nginx ignores `.htaccess`. Shared hosting adds disk quotas.

## Decision
- Default location `WP_CONTENT_DIR/backups-for-restic/` (fallback: the uploads base
  directory when `wp-content` is not writable), created `0700` with `umask(077)`, containing
  `.htaccess` (`Require all denied` / `Deny from all`), `web.config` and an empty `index.php`.
- `BACKUPS_FOR_RESTIC_PRIVATE_DIR` may point outside the web root; the wizard and Doctor
  recommend it.
- Protection is **verified, not assumed**: the Doctor writes a random probe file and fetches
  its URL. Receiving the random content blocks setup and backups until access is denied.
- Content is ciphertext wherever possible: caches are stored as on the server or encrypted
  with the master key; packs under construction are ciphertext. Plaintext exists only in
  restore staging and database import files (explicit actions, `0600`, expiring) and in the
  scrypt table during an unlock (deleted immediately afterwards).
- The directory is excluded from backups except the virtual `spool/` paths of the database
  stream and the manifest.
- Published index and tree files share a per-repository cache cap
  (`advanced.cache_limit_mib`, default 256 MiB); index bytes take priority. Temporary
  ciphertext and concurrent writes can require space beyond that cap. Job history is
  bounded by count, and preflight checks free space.

## Consequences
- Works out of the box on Apache; on nginx the probe turns the recommendation into a
  requirement.
- All plugin writes are concentrated in one place that `uninstall.php` can remove.
- The shared cache cap limits retained cache files; other working data and temporary files
  still need space within the host's disk quota.

## Alternatives considered
- **System temp directory**: shared with other users and periodically cleaned.
- **Uploads directory by default**: always web-served.
- **Database-backed job store**: see ADR 0014.

## As built (2026-11)
- What the directory holds today: `jobs/` with job state, event logs and the engine's
  checkpoints (the ledger is gone, [ADR 0014](0014-file-based-job-store.md)),
  `cache/<repo-id>/index.bin` with the encrypted merged index and
  `cache/<repo-id>/trees/<tree-id>.bin` with encrypted tree blobs for snapshot browsing
  ([ADR 0034](0034-snapshot-reader.md)), `kdf/` with the scrypt table and its checkpoint
  during an unlock, `probe/`, the uploaded CA certificate, the runner lock and, since
  2026-11-11, `state.lock` and `notify.lock`: empty files that serialise changes to the
  state, the notifications and the secrets in the database, and the sending of notices
  ([ADR 0014](0014-file-based-job-store.md), As built). A pack under construction is held
  in memory. Finished restores keep their plaintext files and reports in `restores/` until deletion or expiry.
- The probe has three results for a directory inside the web root. An answer of 200 with
  the probe's content is the critical finding `exposed`: the wizard does not continue, and
  the preflight of a backup fails. An answer that shows the directory protected is reported
  as `protected_inside_web_root`. A probe that cannot run, because the site cannot request
  itself, is reported as `unverified` with the status "recommended". Backups can proceed. Plaintext staging requires a confirmed denial for each public mapping.
- On such hosts the administrator's browser makes the request (`Storage\BrowserProbe`). It
  reaches the site from outside, which is also where an attacker would come from. The
  wizard's environment step and the Environment tab ask for a probe file, request its URL
  without credentials and report what came back. A report with the file's content proves
  that the directory is served, and counts like the site's own finding: the Doctor is
  critical, the setup cannot be completed and backups stop. HTTP 403, 404 or 410 without the token confirm a denial. Other responses leave the check inconclusive. A report is accepted
  for five minutes after its probe file was written, once, and the file is deleted with it.
  The result is bound to the physical path and URL and expires after 24 hours.
- The wizard's script stops at a critical finding, and `POST /setup/complete` refuses with
  `environment_blocked` while the Doctor has one; the routes before it do not ask. Backup and restore preflight both check the private directory.

## Private staging and directory ownership

A restore writes plaintext. Before a restore starts, a probe of the working directory
under a public WordPress path must receive a denial (403, 404 or 410), confirmed as coming
from this site (next section). The probe also
checks custom content and upload directories. If a host blocks loopback requests, a
browser report for the same physical directory and URL is accepted for 24 hours.
Redirects and service errors provide no confirmation. Move the private directory outside
public roots or repeat the check on the Environment tab if staging is refused. Where no WordPress URL maps the directory, the Environment tab checks the actual web server document root. A CLI worker without that information can use the result for the same site and directory for 24 hours.

The first use claims an empty directory. A marker records ownership for subsequent
requests and uninstall. Existing installations are recognized by their working
directories and the plugin's Apache or IIS protection file. Uninstall skips unrelated
directories and preserves protection files customized by the operator.
Uninstall checks both the current filtered location and the previous default or configured
location for outstanding database cleanup before deleting options or local files. If an
owned job document cannot be read, or a live restore still owns the maintenance marker,
the job history and recovery material stay in place for a retry.

## A refusal is confirmed by a known file (2026-11-20)

A WAF, a CDN challenge or another virtual host can answer the site's own request with 403
or 404 while the site's web server would serve the directory, even with a listing of its
subdirectories. A refusal therefore counts only when the same check, with the same kind of
request to the same scheme, host and port, gets a known file of this site back byte for
byte. The known file is the plugin's own stylesheet, `assets/css/admin.css`, which is public
anyway because the admin page loads it; no new public file is needed. Its URL comes from
`plugin_dir_url()` and carries a random query, so that a cache keyed on the query cannot
answer for the site; its expected bytes come from the file on disk. The site's own probe
requests it after a refusal of the probe file. The browser probe requests it beside the
probe file and reports both answers; the expected SHA-256 is recorded with the pending
probe. Every other outcome, also a stylesheet with other bytes or at another origin, is
`unverified`, and plaintext staging is refused with `staging_unverified`. The Doctor's hint
and the error's hint say why.

The control proves that the requests reach this site's file serving at that origin; it
does not prove that the refusal came from the site's own rules. Two cases still pass: a
challenge that lets a cached stylesheet through but stops the probe, where the cache
ignores the query, and an origin that refuses the site's own address but serves others.
A core file was not taken as the control, because every WordPress serves the same bytes
at the same path, another WordPress on the default virtual host among them.

`restores/` and every staging directory below it get the deny files and the empty
`index.php` of the root, as defence in depth against servers that ignore the deny files and
list directories: the listing of `restores/` would name the job IDs, and a staging
directory's would name what it holds. The restore job writes them when it creates the
staging directory; `PrivateDirectory::ensure()`, which every request that takes one of the
plugin's locks runs, adds missing ones to `restores/` and to staging from before. It passes
over links, the names set aside for removal (`.removing-<job-id>`) and directories that a
removal renames at the same moment, and nothing there stops a request. The staged tree
under `files/` gets no such file: it is the snapshot's content and is applied to the live
site as it is.
