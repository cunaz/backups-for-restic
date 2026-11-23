# 03 · Security design and threat model

Security is a design input, not a review step. This document lists what we protect, from
whom, and which control addresses each threat. Every control has an owner in the module map
of [`02-architecture.md`](02-architecture.md). The controls of the WordPress-free core have
tests in the unit and conformance suites. The WordPress integration suite enumerates the registered REST routes and their
capability checks. Packaged browser tests cover staged downloads, reviewed live changes,
cancellation, undo and recovery across independently installed sites.

Contents: 1 Principles · 2 Assets · 3 Actors and trust boundaries · 4 Threats and controls ·
5 Secrets · 6 Cryptography and format implementation · 7 Network · 8 Filesystem ·
9 WordPress layer · 10 Backup integrity and ransomware resistance · 11 Supply chain ·
12 Logging and redaction · 13 Secure defaults enforced by the wizard · 14 Disclosure policy ·
15 Pre-release checklist

## 1. Principles

1. **Least exposure**: a secret exists in as few places, for as short a time, and in as few
   representations as possible. Every deliberate exposure is listed in §5.4.
2. **Least privilege**: only administrators act; repository credentials get only what the
   chosen retention mode needs (append-only when the plugin does not prune).
3. **No home-made cryptography, no shell.** AES, SHA-256 and PBKDF2 come from PHP
   extensions. Poly1305, scrypt's core and the chunker, which PHP lacks, are textbook
   algorithms checked against reference implementations (§6). The plugin starts no
   processes at all.
4. **Fail closed**: a failed precondition blocks the operation; an authentication failure
   on any repository object is an error, never a warning. One precondition that cannot be
   verified does not block yet: the HTTP probe of the private directory when it cannot run
   (T-05).
5. **Same-user compromise is game over, and we say so**: code running as the PHP user can
   read `wp-config.php` and therefore everything derived from it. Controls target the layers
   below that: database-only leaks, web-exposed files, other users on the host, network
   attackers, and attackers who try to destroy backups from the server.
6. **Recoverability is a security property**: a backup that cannot be restored, or that an
   attacker can erase, protects nobody. Every snapshot must be restorable with the plain
   restic CLI from the recovery kit alone.

## 2. Assets

| Asset | C | I | A | Notes |
| --- | --- | --- | --- | --- |
| **Repository password** | critical | — | critical | Unrecoverable if lost; unlocks every backup |
| **Repository master key** (cached after unlock) | critical | — | high | Equivalent to the password; stored encrypted at rest |
| **REST credentials** (rest-server or BorgBase) | high | — | medium | Read/write (or append-only) access to the repository; regenerable on the server |
| Site files incl. `wp-config.php` (in snapshots) | high | high | high | DB credentials, salts |
| Database content (in snapshots, in restore staging) | high | high | — | user data, hashed passwords, sessions |
| Snapshots and packs in the repository | high (ciphertext) | critical | critical | Ransomware target |
| Local cache (the merged index) | medium | high | — | Encrypted and authenticated with the master key; integrity matters for dedup decisions |
| Job state, checkpoints and logs | medium | — | — | Paths, sizes, timings |
| Server resources (CPU seconds, memory, disk quota) | — | — | medium | Backups must not take the site down |
| Recovery kit (outside the server) | critical | — | critical | Operator's password manager |

## 3. Actors and trust boundaries

```
 Internet ─┐  B1: HTTPS edge
           ▼
 Visitor │ Low-privileged user │ Administrator (trusted, phishable)
 ───────────────────────────────────────────────────────── B2: capability + nonce + REST permission
 Plugin code (PHP user) ◀─ B3 ─▶ other plugins/themes (same user) ◀─ B4 ─▶ database
           │ B5: filesystem modes (other OS users on the host)
           │ B6: TLS (network attacker)
           ▼
 Repository server: own rest-server or BorgBase (ciphertext, sizes, timings; enforces append-only)
```

| Actor | Capability | Trusted? |
| --- | --- | --- |
| Unauthenticated visitor | HTTP requests to any web-reachable file | no |
| Low-privileged WordPress user | authenticated, no `manage_options` | no |
| Administrator | everything in wp-admin | yes, but phishable and fallible |
| Code running as the PHP user | read `wp-config.php`, files, memory of the same process | **no**, but beyond our control; we limit blast radius |
| Other OS users on a shared host | depends on file modes; cannot read our `0700` directory | no |
| Database-only attacker | reads/writes the options table | no |
| Network attacker | observes or modifies traffic to the repository server | no |
| Repository server operator (self-hoster or BorgBase) | stores ciphertext; could delete or withhold data; enforces append-only | partially (availability), not for confidentiality |
| Supply chain (our dependencies, CI, WordPress.org credentials) | could inject code | must be verified |

## 4. Threats and controls

STRIDE class in brackets. "Residual" is what remains after the control.

| ID | Threat | Control(s) | Residual |
| --- | --- | --- | --- |
| T-01 | Unauthorised user starts a backup/restore, changes settings or downloads staged files [E, T] | The menu and every REST route enforce the configured backup capability. Live file writes also require `update_core` and permission under the host file modification policy, both checked again for the confirming user, read from the database, on every tick of the job. Live database writes require `manage_options`, `edit_users`, `promote_users` and `update_core` alongside the backup capability. A download grant authorizes one exact staged subject; a grant of a restored file or archive also needs the user's WordPress password and is recorded (§5.5). Staging itself needs the capability alone, because the staged copy stays in the private directory (§8). | Compromised administrator account or unredeemed download grant; a holder of the capability can stage any path of a snapshot and read the reports |
| T-02 | CSRF on destructive actions [T] | WordPress authenticates REST requests with its cookie nonce or an Application Password. File and database apply/undo each require an operation-specific signed review, explicit acknowledgment, the exact home URL and the confirming user’s WordPress password in the JSON body. Discarding the binding of a database restore whose private tables are on another database server requires the WordPress password in the JSON body, is refused while the connected server can verify the binding, and drops nothing. The download form uses a one-time POST token. Wrong WordPress passwords are counted per user and slowed down, and reported to login limiters (§5.5). | An authenticated administrator can intentionally authorize a destructive change. |
| T-03 | Secrets leak through the database (SQL injection elsewhere, leaked DB dump) [I] | repository password, REST password and cached master key encrypted with a key derived from `wp-config.php` salts (§5); `autoload = no`; constants in `wp-config.php` as an alternative | attacker with DB **and** `wp-config.php` |
| T-04 | Secrets leak via logs, notices, e-mails, REST responses, HTML forms [I] | `Secret` type redacts itself; `Redactor` masks the registered secret values in job files and event lines (§12); REST errors and e-mails are built from codes, not from log lines; secret fields are write-only; after the setup the recovery kit is sent only with the administrator's WordPress password (§5.5); the stored REST password goes only to the server it was saved for (T-28); tests search the files under `jobs/` and four REST responses for both passwords | browser extensions on the admin's machine |
| T-05 | Web access to the private directory (caches, checkpoints, staging, KDF checkpoint) [I, T] | directory outside the web root recommended and supported; otherwise `.htaccess`, `web.config`, `index.php`, `0700`; **active HTTP probe**: if it reads its file back, the Doctor is critical and backups stop; the cache is encrypted with the master key; from Milestone 3 staging and import files `0600` and short-lived | servers that serve dotfiles and ignore modes, caught by the probe when it can run. A refusal (403, 404 or 410) counts as a denial only when the same check also gets the plugin's stylesheet back byte for byte from the same scheme, host and port, with a random query past any cache: a firewall, a CDN challenge or another virtual host that refuses the probe fails that control, and the finding stays `unverified`. Where the site cannot request itself, the administrator's browser makes both requests and reports the answers; until one has, the finding is `unverified`; a restore requires a verified denial before it stages plaintext under a public path. `restores/` and every staging directory carry the deny files and the `index.php` of the root, against servers that list directories. A challenge that lets a cached stylesheet through but stops the probe, or a server that serves the directory only to others, still passes |
| T-06 | Nonce reuse or weak randomness breaks confidentiality of AES-CTR [I] | nonces, salts, ids and passwords from `random_bytes()` (CSPRNG) only; one nonce per sealed object; unit test asserts distinct nonces across a batch; no deterministic nonce derivation anywhere | OS CSPRNG failure |
| T-07 | Tampered or corrupted repository objects accepted (malicious server, MITM, disk error) [T] | Poly1305 tag verified before any decryption (constant time); blob id (SHA-256 of plaintext) verified after decryption; the names of index, snapshot and lock files verified against their content on download, the name of a pack when the check reads the whole pack; index and tree JSON validated against expected shapes; a failed tag aborts the job with `authentication_failed`, a wrong id or shape with `repository_damaged` | — |
| T-08 | Weak password brute-forced from a leaked key file [I] | generated passwords are 192-bit random; a password that somebody chose for a new repository, in the request or in `wp-config.php`, must have at least 12 characters, which the core enforces (`Connection\Setup::create()` refuses a shorter one with `password_too_short`); scrypt with `N ≥ 16384, r = 8` (restic's default); key files live only on the repository server | weak user passwords remain weak; the password of an existing repository is taken as it is |
| T-09 | Cached master key or KDF checkpoint read from disk [I] | master key only in the encrypted secret store (DB), never on disk; the scrypt ROMix table is written to the private directory only during an unlock, created with mode `0600` under a umask of `077` (`Crypto\Scrypt::open_table()`), and deleted afterwards; the table alone does not reveal the password | same-user attacker during an unlock |
| T-10 | Path traversal or symlink attack during restore/apply [T] | Private staging, verified parent directories, live-root containment, checked source and destination identities, reviewed file hashes and an undo journal. The controller excludes its own plugin and private state. Core startup paths, configuration, must-use plugins and drop-ins require offline recovery. Protected locations are compared in every spelling a live target can have: through symbolic links, with repeated slashes, and in the case and Unicode variants that case-insensitive filesystems equate. No apply writes a snapshot's database spool, wherever the snapshot recorded it. | Malicious content in a legitimately authorized backup. Without the intl extension, a name that only Unicode normalization could tell apart from a protected name is refused. Duplicate sibling names are detected with simple case folding, so on a case-insensitive filesystem a crafted name can replace an unprotected file that it aliases; the review lists the snapshot's spelling. |
| T-11 | Attacker on the server deletes or corrupts backups (ransomware) [T, D] | recommend **append-only** on the repository server (rest-server `--append-only`, or the repository option of BorgBase) with retention mode `external`; `--keep-within` guard; prune-lite and forget only in `plugin` mode; stale-backup alerts of the server's operator, where offered, as independent monitoring | in `plugin` mode the credentials can delete; operator's documented choice |
| T-12 | Fake snapshots injected to expire real ones through count-based retention [T] | `keep-within 2d` default, and a floor the core enforces: `keep-within` of at least one day or `keep-last` of at least 1 (§13); forget selects only the snapshots with this site's host name that carry the tags `backups-for-restic` and `site:<hash>` and treats them as one group ([ADR 0010](adr/0010-retention-and-append-only.md)); monthly check | noise within the window |
| T-13 | Network attacker intercepts or modifies traffic [I, T] | only `https://`; TLS verified against WordPress' CA bundle or an uploaded CA certificate for a self-hosted server, never disabled; redirects disabled so credentials never follow a redirect to another host; every object is encrypted and authenticated end-to-end anyway | — |
| T-14 | Malicious or buggy format implementation corrupts the repository [T, D] | no home-made cryptography; differential tests against the restic CLI on every change (`restic check --read-data`, byte-identical restores, chunk boundary parity); known-answer vectors from `restic cat`, RFC 7914, RFC 8439 and libsodium; property tests for pack/index round trips; mutation testing as a merge gate and nightly fuzzing of every parser; self-review checklist and 24-hour cooling-off for `Crypto`, `Format` and `Engine` ([ADR 0024](adr/0024-governance-and-quality-assurance.md)) | **accepted**: no independent review before 1.0; design or side-channel flaws that differential tests cannot see. Safety nets: monthly checks and the plugin-independent CLI recovery path |
| T-15 | Resource exhaustion: CPU-second quotas, memory limits, disk quotas, request limits [D] | bounded ticks; streaming; packs built in memory up to the pack size (16 MiB by default, one open pack per blob type); cache cap; `cpu_throttle: gentle`; preflight checks free space and memory; one job at a time; sequential uploads; compact index records (about 50 to 80 bytes per blob), pack-by-pack index decoding and a cache read in authenticated pieces; a memory budget stops index loading with `index_too_large` and reduces backup packs to fit, down to 4 MiB ([ADR 0031](adr/0031-compact-index-and-memory-budget.md)) | hosts with extreme limits — surfaced by the Doctor. The index still grows with the number of blobs; raw index JSON and individual packs are decoded before the budget is checked. The separate lists built by prune and check still need bounded memory (phase 0.9, second part) |
| T-16 | Stale or foreign repository locks block backups [D] | locks refreshed every 5 minutes by the ticks of the job that holds them, and taken again when they are older than 25 minutes or gone; a backup or check then makes sure that no other client removed what it relies on, or starts over ([ADR 0030](adr/0030-lock-continuity.md)); own stale locks removed as restic does; a stale lock of another host blocks nothing, because stale locks are ignored when the plugin looks for conflicts, and it is never removed by the plugin: `restic unlock` from any computer removes it. An admin action for that (`unlock --yes`) is on the list without a milestone | other clients writing to the same repository |
| T-17 | Secrets undecryptable after salt rotation [D] | detected on load (`undecryptable`); the Doctor and Site Health turn critical and jobs fail with `secrets_undecryptable`. The way back is the Settings tab, where both passwords can be entered again: the REST password is tested against the server and saved, the repository password is checked by an unlock job. A site that defines both passwords in `wp-config.php` goes on by itself, because the cached key is derived again (§5.2) | operators without a stored kit — mandatory acknowledgement |
| T-18 | Salts not defined as constants (WordPress stores them in the DB) [I] | Doctor warning with instructions | — |
| T-19 | Malicious data from the server exhausts memory or time (huge index/tree JSON, deep nesting) [D] | limits that exist: JSON depth 64 (`Support\GoJson`), stored blob length ≤ 64 MiB and pack header ≤ 16 MiB (`Format\Pack\PackReader`), zstd frame windows and cumulative decoded size are checked before invoking the native decoder. The decoded object is limited to 128 MiB and to a fraction of remaining PHP memory (`Format\Compression`), scrypt parameters (`Crypto\ScryptParameters`), listing entries that are not object ids skipped; a parse error aborts with `repository_damaged`. The size of every answer is capped before it is read (`Backend\RestBackend`, enforced by the HTTP client): a whole file by its kind (config and key 64 KiB, lock 1 MiB, snapshot 16 MiB, index 128 MiB), a range by the length that was asked for, a listing 128 MiB, any other answer 64 KiB. A longer file is refused with `repository_damaged` | Index files are decoded one pack at a time and the accumulated index is checked against the memory budget. An index of up to 128 MiB is still read in full, and one pack may contain enough entries to exhaust a small host before the next budget check; phase 0.9, second part remains open. The cap counts bytes as they arrive: the plugin asks for uncompressed answers (`Accept-Encoding: identity`), but WordPress' HTTP library unpacks an answer that is compressed all the same, without a limit of its own. A hostile server can end a tick that way; it can also simply not answer, so it gains nothing beyond that. The library also goes on reading an answer that is longer than the cap and throws the rest away, so the cap bounds the memory for a body, not the time or traffic of such a request, and not the headers, which the library collects before any cap applies. The request's time limit ends a server that stops sending; one that keeps sending a little can hold a request open longer on the transport without cURL |
| T-20 | Information disclosure to low-privileged users via Site Health or REST [I] | admin-only by core and by capability checks; generic errors elsewhere | — |
| T-21 | On multisite, a site administrator backs up or restores the whole network [E] | activation refused on multisite in 1.0, network-wide and per site; runtime guard keeps the plugin inert if activated otherwise ([ADR 0026](adr/0026-multisite-deferred.md)) | — |
| T-22 | Races: two ticks, two jobs, two uploads of the same pack [T, D] | `flock` on `runner.lock` for ticks; lease with expiry; single-active-job invariant: the check for an active job and the write of a new one happen under an exclusive `flock` on `jobs/create.lock`; after its creation only the holder of `runner.lock` writes a job's `job.json`, and a request to cancel is a marker file of its own, `jobs/<id>/cancel-requested`, which a tick reads before every step, so neither can overwrite or lose the other; content-addressed names make duplicate uploads harmless (server answers 403 for an existing object, treated as success after a `HEAD` size check) | NFS without `flock` (documented) |
| T-23 | Plugin update introduces malicious code (maintainer account, CI, WordPress.org credentials) [T] | signed tags; SVN deployment only from CI in a protected environment that the owner approves per release; dedicated WordPress.org SVN password as an environment secret, rotated yearly and on suspicion; 2FA on the owner's WordPress.org and GitHub accounts; no runtime third-party code; Dependabot; post-deploy diff of the SVN tag against the built package | compromise of GitHub or WordPress.org infrastructure; **accepted**: bus factor 1 for releases ([ADR 0024](adr/0024-governance-and-quality-assurance.md)) |
| T-24 | WP-CLI `recovery-kit` output captured in shell history or logs [I] | interactive terminal required unless the operator passes `--force`. An explicitly selected user needs the backup-management capability. Delivery requires a successful audit write (§5.5) | shell access as the PHP user already implies full access |
| T-25 | Timing side channels in PHP code touching secrets [I] | Poly1305 without secret-dependent branches or table lookups, tags compared with `hash_equals` ([ADR 0027](adr/0027-poly1305-in-pure-php.md)); comparisons of ids with `hash_equals`; scrypt's Salsa20/8 is add-rotate-xor only; table lookups in the chunker depend on file data, not secrets | PHP's own variability — no known practical attack |
| T-26 | An administrator-configured server URL makes the site send requests to internal services (SSRF) [I, T] | only administrators configure the URL; HTTPS only; redirects disabled; responses are parsed as restic objects and never shown raw; errors show status codes only; the BorgBase preset restricts the host to `*.repo.borgbase.com` ([ADR 0025](adr/0025-repository-servers.md)) | administrators can usually run arbitrary code anyway |
| T-27 | An uploaded CA certificate weakens TLS for other requests [I, T] | the certificate applies only to requests to the configured repository server (per-request `sslcertificates`), never globally; PEM validated with `openssl_x509_parse`; stored in the private directory | — |
| T-28 | A request to test or save a server names another host, and the site sends the stored REST password there [I] | the stored REST password is used only for the host, port and user name it was saved for (`Connection\Setup::rest_password()`); a request for another server or user name has to carry the password, otherwise it is refused with `password_required` before anything is sent; a REST password defined in `wp-config.php` goes to another server only if the request carries that same password; saving a server without a user name deletes a stored REST password | an administrator who types the password for another server sends it there, which is the purpose of the form |

## 5. Secrets

### 5.1 Inventory

| Secret | Source | Rotation | Where used |
| --- | --- | --- | --- |
| Repository password | generated by the wizard (32 chars, CSPRNG, 192 bits) or entered for an existing repository | `restic key add`/`key remove` from any computer. The cached master key keeps working; the stored password, which the recovery kit prints, is then outdated. After setup it is replaced under Settings → Repository, confirmed with the administrator's WordPress password and checked by an unlock job, or through the constant `BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD`. An entered password is kept apart (`repository_password_candidate`, encrypted like the others) until the unlock job has opened the repository with it; a wrong one is dropped when that job ends, and the stored password and the cached key stay as they were | scrypt input during unlock; recovery kit |
| Repository master key | derived once from the password via the key file | changes only with `restic copy` to a new repository | every seal/open; cached in the secret store |
| REST password | the `.htpasswd` of the rest-server, or the BorgBase control panel | change it on the server. After setup the plugin takes the new one under Settings → Repository, where it is tested against the server before it is saved, or through the constant `BACKUPS_FOR_RESTIC_REST_PASSWORD` | `Authorization` header |
| REST username | the `.htpasswd` of the rest-server, or the BorgBase control panel; stored as a plain setting, not as a secret, and not masked in logs | with the password; after setup through the constant `BACKUPS_FOR_RESTIC_REST_USERNAME` | `Authorization` header |
| Download tokens | 256 random bits per download from `Storage\DownloadTokens` | Single use, one hour, at most 256 outstanding | An authenticated REST request issues a grant for the exact job, download kind and file path; for a restored file or archive only with the WordPress password and after an audit entry (§5.5). Only token and subject hashes plus expiry are stored in an owner-only file. The POST download redeems the grant atomically and reopens the checked staged file. Token and file name stay out of the URL. |

### 5.2 Storage: encrypted option, or constants

`EncryptedOptionStore` keeps a single option `backups_for_restic_secrets` (`autoload = no`)
with `name → ciphertext`. Ciphertext format
`v1.<base64url(nonce 24 B)>.<base64url(sodium_crypto_secretbox output)>`; the key is
`hash_hkdf('sha256', ikm, 32, 'backups-for-restic/secrets/v1', salt)` with `ikm` = the eight key
and salt constants read directly (not `wp_salt()`, which silently generates database-stored values)
and `salt` a random 32-byte value stored next to the ciphertext. `ConstantOverrideStore`
wins when `BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD` or `BACKUPS_FOR_RESTIC_REST_PASSWORD` is
defined. The cached master key is always in the option store (there is no constant for it),
and so is a repository password that was entered and is not confirmed yet.
Decryption failure yields `undecryptable` (`SecretsUndecryptable`), never an empty secret
([ADR 0006](adr/0006-secrets-at-rest.md)). After a salt change that also holds for the
cached master key. It is a cache: `RepositoryAccess::open_cached()` forgets an entry that
no longer decrypts, and the unlock step derives the key again from the password. With both
password constants defined the next job therefore goes on by itself. Stored passwords do
not come back: the job ends with `secrets_undecryptable` until both are entered again under
Settings → Repository ([disaster-recovery guide](06-disaster-recovery.md)).

### 5.3 In memory and in code

- `Secrets\Secret` is `final`, redacts itself in `__toString` and `__debugInfo`, and its
  `jsonSerialize` throws. `Crypto\MasterKey` is `final`, redacts itself in `__debugInfo`
  and refuses `serialize()`. `Secret` keeps its value inside
  a closure, so `var_export()` shows nothing and `serialize()` fails. The raw value is only
  available through `reveal()`, whose call sites are allow-listed by
  `tests/PHPStan/SecretRevealRule.php`: `Secrets\Secret`, `Secrets\EncryptedOptionStore`,
  `Backend\RestBackend` (basic auth header), `Connection\RepositoryAccess`,
  `Connection\ConnectionTester`, `Connection\Setup`, `Engine\KeyUnlock`,
  `Support\Redactor` and `WordPress\RecoveryKit`. A new call site fails CI until the list
  is changed in a reviewed pull request.
- `RestBackend` keeps the REST password as a `Secret`; job files and event lines pass
  through a `Redactor` that masks the registered secret values (§12).
- Functions taking raw secret strings are annotated `#[\SensitiveParameter]`, so PHP redacts
  them from stack traces on every supported version (floor 8.3,
  [ADR 0003](adr/0003-version-floors.md)). This matters on shared hosting, where PHP error
  logs often land inside the web root.
- Revealed copies are not wiped: `sodium_memzero()` is not called anywhere in the plugin.
  They live until PHP frees the string.
- The scrypt table file is created with mode `0600` in `kdf/<job-id>/`, a `0700` directory,
  is never the password itself, and is deleted when the derivation finishes; the job's
  `cleaning_up` step removes the whole directory on every path to the end.

### 5.4 Deliberate exposure points (complete list)

1. The `Authorization` header of HTTPS requests to the repository host (TLS protected). The
   stored REST password is sent only to the host, port and user name it was saved for
   (T-28).
2. The recovery kit, in the answers of three REST routes and on explicit WP-CLI export (§5.5, T-24).
3. The fields of the setup wizard in which an administrator types the REST password or the
   password of an existing repository, and the requests that carry them to the site. After
   the setup the Settings tab has the same two fields, to enter the passwords again; the
   repository password is accepted only together with the administrator's WordPress
   password. Neither field ever shows a stored value.
4. The `wp-config.php` constants, if the operator chose them.

Anything else that contains a secret is a bug.

### 5.5 Recovery kit

Site address, date, plugin version, repository URL, REST username and password, repository
password, repository format version, the restore recipe (four `export` lines and two restic
commands), a note on importing the database, and the CA certificate if one was uploaded
(`WordPress\RecoveryKit`). It is built for each request and never stored, and its response
carries `Cache-Control: no-store`.

- **During the setup** the answers of `POST /repository/create` and
  `POST /repository/connect` contain the kit, and the wizard shows it once with copy and
  download (`text/plain`). `POST /setup/complete` requires the checkbox "I have stored the
  recovery kit outside this server"; until then no backup and no maintenance job can be
  started, neither by the heartbeat nor through REST or WP-CLI (`setup_incomplete`).
- **In the admin area after setup**, the kit is shown only to an administrator who sends the WordPress
  password of the current user with the request (`wp_check_password`): through
  `POST /recovery-kit`, which the Settings tab uses, and equally through `create` and
  `connect`, which otherwise answer 403 `wrong_user_password`. The password is asked for in
  every request.
- If the settings name an uploaded CA certificate that is missing from the private
  directory, the kit is refused with `ca_certificate_missing`: without the certificate
  restic could not reach the server, and the kit would be useless.
- The local WP-CLI command requires completed setup. A selected WordPress user needs the
  backup-management capability. An invocation by the shell operator without a selected
  user is recorded as WP-CLI (user ID 0). A pipe or redirected file requires `--force`.
- Every delivery requires a durable audit entry with time, user and method (`create`, `connect`,
  `settings` or `cli`), the last twenty in the state option, and the action
  `backups_for_restic_recovery_kit_shown` fires for audit plugins. The Settings tab shows
  the latest one, so an administrator sees when somebody else looked at the kit. A refused
  request is not recorded.
- Not built, on purpose: a validity of five minutes after the WordPress password was
  entered. The kit's view has copy and download, so no second request is needed, and a
  window in which a session gets the kit without the password would only help whoever
  took the session over.

The same secrets can be taken from restored content: the eight salt constants in
`wp-config.php` are the key material of the secret store (§5.2), and the dump of the options
table holds its ciphertexts. The download of a restored file or of a restore's ZIP is
therefore guarded like the kit:

- Its grant (`POST /restores/{id}/download` with `kind` `file` or `archive`) needs the
  WordPress password of the current user in the JSON body of the same request. An
  application password, which authenticates the request, is not enough.
- Every grant requires a durable audit entry with time, user, the restore job, the kind
  and the path before the token is issued. The path is kept in Go's quoting, so that a line
  break, a control character or bytes that are not UTF-8 in a snapshot name cannot break
  the list or a log line; a path longer than 1 KiB is cut and marked. The last twenty stay
  in the state option, the Settings tab shows the latest five beside the deliveries of the
  kit, and the action `backups_for_restic_download_granted` fires for audit plugins. A
  refused request and a file that the restore does not hold are not recorded.
- What stays behind the capability alone: staging itself (`POST /jobs` with `type: restore`),
  which only writes the copy into the private directory (§8), and the two reports, the
  restore report (`entries.jsonl`: names, types, modes, times, sizes and link targets, which
  the snapshot routes show anyway) and the database rehearsal's report (table names, row
  counts, engines, collations, privileges, server versions and a digest of the content, no
  row values). The admin page fetches the rehearsal's report by itself for its preview. A
  download kind that is neither report counts as content.
- A restore job records the user who started it, and the job view shows it.

Every route that asks for the WordPress password asks through one check
(`WordPress\PasswordConfirmation`), and wrong passwords are counted per user
(`Storage\ConfirmationThrottle`):

- The first three wrong passwords in a row cost nothing but the refusal. After the fourth
  the next attempt waits one minute, and every further wrong password doubles the wait: 1,
  2, 4, 8, 16 and 32 minutes. From the tenth on, every wrong password locks the
  confirmation of that user for an hour. The right password forgets the count, and so does
  a day without an attempt.
- While a user waits, no password of theirs is checked at all, so the answer during a wait
  says nothing about the password. It is 429 `user_password_throttled` with a `Retry-After`
  header.
- An attempt is counted before its password is checked, in the state option under its
  file lock, so that requests sent at the same moment cannot share one try; the right
  password takes its count back. Where the count cannot be stored, no password is checked.
- The count is the user's own: what one account sends changes the count of no other, and
  only the right password, or a day of quiet, ends it early. A stolen session can lock its
  own account's confirmation for an hour at a time, not its login, and not anybody else's.
- Each wrong password fires core's `wp_login_failed` action with the login and a `WP_Error`
  of the code `incorrect_password`, as core's login does, so that login limiters and
  fail2ban rules count these attempts. An attempt without a password is refused and not
  counted, as by core.
- Residual: a limiter that blocks on the `authenticate` filter does not stop these checks
  (the plugin's own count does), and a two-factor plugin does not take part, because only
  the password is asked for. At the rates above a stolen session gets 32 guesses in the
  first day and 24 in each day after.

## 6. Cryptography and format implementation

- **Primitives**: `openssl_encrypt`/`openssl_decrypt` with `aes-256-ctr` and `aes-128-ecb`
  (for the Poly1305-AES nonce block only), `hash('sha256')`, `hash_pbkdf2('sha256')`,
  `random_bytes`, `hash_equals`. If `ext-openssl` is missing the plugin refuses to run; it
  is universal on hosting. The engine uses nothing from sodium.
- **Hand-written code** is limited to three algorithms PHP does not offer, all pure integer
  arithmetic:
  - Poly1305 ([ADR 0027](adr/0027-poly1305-in-pure-php.md)): poly1305-donna with 26-bit
    limbs, no secret-dependent branches or lookups; verified against RFC 8439 and 400
    libsodium vectors.
  - scrypt's ROMix/Salsa20/8: verified against RFC 7914 vectors and against key files that
    restic created and calibrated.
  - The Rabin chunker plus GF(2) polynomial arithmetic: verified against a vector from
    restic's chunker library and, indirectly, against `restic backup` (a plugin backup of
    files that restic backed up adds no data blob); not security critical.
- **Verification order** on read: object id (SHA-256 of ciphertext file) → Poly1305 tag →
  decrypt → blob id (SHA-256 of plaintext) → JSON shape and the limits of T-19. A failed
  tag aborts the job with `authentication_failed`, a wrong id or a malformed object with
  `repository_damaged`; the UI explains that the repository may be damaged and points to
  `restic check`. In a check job the same failures become findings and the check goes on.
- **Write order** on backup: encrypt → append to pack → upload pack (server verifies the
  SHA-256 name) → record pack in the checkpoint → write index → reference blobs in trees → write
  snapshot. A crash at any point leaves at most unreferenced data, never a snapshot that
  references missing blobs.
- **Conformance** ([ADR 0023](adr/0023-restic-cli-as-conformance-oracle.md)): every change to
  `Crypto`, `Format`, `Engine` runs the oracle suite: PHP-written repositories pass
  `restic check --read-data`; `restic restore` reproduces the fixture byte for byte; PHP reads
  and restores repositories written by restic (format version 1, and version 2 with
  `ext-zstd`);
  the unit suite decodes vectors recorded with restic 0.19.1 (`bin/record-vectors.sh`: the
  raw repository files, and the master key, config, snapshot, index and root tree as
  `restic cat` prints them).
- **Review** ([ADR 0024](adr/0024-governance-and-quality-assurance.md)): the project has one
  maintainer and no external audit before 1.0. Compensating controls: differential tests
  against the restic CLI, known-answer vectors, mutation testing as a merge gate, nightly
  fuzzing of every parser, a self-review checklist and a 24-hour cooling-off period for
  `Crypto`, `Format` and `Engine` changes. The residual risk is accepted (T-14).

## 7. Network

- Only `https://` repository URLs. The rest-server option accepts any host, port and path;
  the BorgBase preset requires a `*.repo.borgbase.com` host
  ([ADR 0025](adr/0025-repository-servers.md)).
- WordPress HTTP API with `sslverify => true`, `redirection => 0`, explicit timeouts, basic
  auth via header, plugin User-Agent, no cookies (`WordPress\WpHttpClient`). `sslverify` is
  passed as a request argument on every repository request, and the plugin has no setting
  that turns it off. Other code on the site can change request arguments through WordPress'
  HTTP filters, and snippets that switch certificate verification off for a whole site are
  common. While a repository request is under way, the client therefore registers its own
  callbacks last on `http_request_args`, `https_ssl_verify`, `https_local_ssl_verify` and
  the `http_api_curl` action, for this request's URL only, and puts verification, the
  certificate bundle and the ban on redirects back. The integration suite switches
  verification off in all three ways and expects the connection to a server with an
  unknown certificate to fail. This holds against careless code, not against code that
  means harm: such code runs in the same process and could read the passwords as well (§3).
- Reads, listings and deletions are retried on network errors, on 429 and on 5xx other than
  507; uploads on 429 and such 5xx, but not on network errors. Every other 4xx is final and
  mapped to a dedicated error (401/403 → credentials or append-only; 404 → missing; 400 →
  upload rejected), as is 507 (quota).
- A CA certificate can be uploaded for a self-hosted server with self-signed TLS; it applies
  only to requests to that server. Disabling verification has no equivalent in the plugin.
- Requests from the site to itself (the exposure probe, the loopback check and loopback
  chaining) follow core's `https_local_ssl_verify` filter, as core's own loopback requests
  do. They carry no secrets and never reach the repository server.
- The database dump opens a connection of its own to the site's database server, with the
  host, user, password and database that `$wpdb` connected with, or those of the `DB_HOST`,
  `DB_USER`, `DB_PASSWORD` and `DB_NAME` constants in `wp-config.php` where `$wpdb` does not
  expose them, and with the client flags of `MYSQL_CLIENT_FLAGS`
  (`WordPress\WpdbConnection::dedicated()`, [ADR 0021](adr/0021-database-streaming.md)).
  The plugin does not store the database password, and an error of that connection carries
  the server's error number, never its text. Before it connects, the adapter reads the TLS
  cipher of the site's connection. Where that connection is encrypted and the client flags do
  not ask for TLS (`MYSQLI_CLIENT_SSL`), for example because a database drop-in sets up the
  encryption itself, or where the cipher cannot be read because the status query returns no
  row, it opens no second connection, so that the account does not authenticate in the clear
  beside an encrypted site. An empty cipher means that the site's own connection is not
  encrypted: the adapter then tries to open the second connection with the same client
  flags, which encrypt it only if they ask for TLS. The dump reads through a second
  connection only if it could be opened and is shown to reach the same server and database
  as the site's and to be encrypted if the site's connection is. A connection that fails
  one of these checks is closed, and the dump then reads through `$wpdb`, as it does when no
  second connection was opened. With `MYSQLI_CLIENT_SSL` in the flags, the dump's connection
  verifies the server's certificate as those flags say, which can differ from TLS settings
  that a drop-in makes itself.

## 8. Filesystem

- The private directory and every directory below it are `0700`. `umask(077)` is in force
  only while `PrivateDirectory::ensure()` creates the directory, its deny files and its
  subdirectories; afterwards the previous umask is restored. Files get explicit modes where
  the plugin sets them: `Files::write_atomic()` (a temporary file in the same directory,
  `chmod 0600`, then `rename`) writes `job.json`, the dump state, the index cache and the
  checkpoints of unlock, forget, prune and check, and the CA certificate gets `0600` after it
  is written. Backup checkpoints and node files get `0600` before their content is written
  ([ADR 0032](adr/0032-run-checkpoint-files-and-cleanup.md)). The event log is created with
  the mode that the process umask gives; it relies on its `0700` directory. Nothing is
  written to the system temp directory.
- Besides the live files it changes, a live apply or undo writes one file outside the
  private directory, its maintenance marker: `.maintenance` in the WordPress root, a hard
  link to `maintenance.php` in the job's `0700` directory. The inode has mode `0644`, as
  core gives its own marker, because PHP processes of other system users read it in every
  request. It names the job, the plugin, two URLs and the SHA-256 of the control token; it
  holds no secret. Its content proof, `maintenance.sha256`, is `0600`. Every tick checks
  the private copy against the proof before it renews the marker, and cleanup removes the
  marker only while it is that inode with that content (ADR 0041). Where OPcache refuses to
  drop the compiled marker (`opcache.restrict_api`) on a server without timestamp checks,
  the job stops before its next change.
- The cache holds one file per repository, the merged index, sealed with the master key
  (encrypted and authenticated). A cache that fails authentication is ignored and rebuilt
  from the repository. The lengths between its sealed pieces are not authenticated; a
  length that no piece can have is treated the same way, before any memory is reserved
  for it.
- Packs are built in memory, not in files. The KDF table is the one sensitive temporary file
  and is deleted when the derivation finishes.
- Restore staging is plaintext by necessity. A restore job
  ([ADR 0037](adr/0037-restore-job.md)) stages into `restores/<job-id>/` of the private
  directory, after a preflight that refuses a directory that is served. The staging
  directory, `files/` in it and every entry below are owner-only
  ([ADR 0036](adr/0036-selected-file-staging.md), next point); a job that fails or is
  cancelled removes what it staged. A request to cancel that comes after the last file is
  staged ends only the archive or the database rehearsal, and the job keeps its complete
  staging until it expires like any other. The staged files of a finished restore expire
  ([ADR 0040](adr/0040-restore-staging-expiry.md)): `restore.staging_expiry_hours` after
  the job finished, 24 by default and 168 at most, they leave the list, and the next
  heartbeat deletes them, as it deletes at once what a job that did not succeed left
  behind. An administrator can remove them earlier (`DELETE /restores/{id}`). The removal
  takes a job ID and nothing else as a name, renames the directory before it deletes, and
  removes symbolic links without following them. Residual risk: until then an exposure of
  the private directory exposes the staged files too, for the configured hours and, where
  WP-Cron does not run, until the next request brings a heartbeat; a tree that the file
  system refuses to delete stays, unlisted, and is tried again at every heartbeat. So do
  the preview data and undo workspaces that the file system refuses to remove; neither
  keeps other staging from expiring, and a private directory that the configuration names
  through a symbolic link is used by its real path, so that its checks hold. Two
  states keep staged files past their expiry with no heartbeat to end them. A deactivated
  plugin has no heartbeat: what is staged stays until the plugin is activated again, whose
  first heartbeat removes what is due, or uninstalled. A staging directory whose job
  document this version cannot read, for example after a downgrade, is neither listed nor
  removed, because nothing proves that its job has ended; it goes with a version that can
  read the document, or with the uninstall.
  Active review, apply and undo jobs can pin their source while they run. Database
  rehearsal reports and ZIP downloads share staging's ownership and expiry rules.
- Who may do what with staging: staging needs the backup capability alone, because the
  staged copy stays in the private directory, readable by the PHP user only. Taking it out
  of there is guarded: the download of a restored file or ZIP needs the WordPress password
  and is recorded (§5.5); the restore report and the rehearsal's report hold metadata and
  need the capability alone. Applying files or tables to the live site needs the checks of
  T-01 and T-02.
- `restores/` and every staging directory in it (`restores/<job-id>/`) carry the deny files
  and the empty `index.php` of the private root. The restore job writes them when it creates
  the directory, and every request that prepares the private directory adds those that are
  missing, for staging from before; a directory set aside for removal and a link are left
  alone. They are defence in depth: where a web server ignores the deny files but lists
  directories, `index.php` keeps it from listing the job IDs and what a restore staged.
  The staged tree itself gets no such file, because it is the snapshot's content.
- Restore staging is owner-only whatever the snapshot recorded: every staged file is `0600`
  and every staged directory `0700`. A snapshot's `0644` or `0666` file and its `0755` or
  `0777` directory are never staged with those modes; the original mode is kept in the
  report (`entries.jsonl`) for the later apply step. `Restore\Files::open()` creates staged
  files, the report, the checkpoint and the local lock file under a umask of `077`, so that
  a request killed right after creating one leaves nothing wider, and sets `0600` again on
  every open, which also covers a filesystem whose default ACL overrides the umask.
- The request of a restore job is validated before the job exists: the snapshot ID is 64
  hexadecimal characters and never part of a local path, and the selected paths are
  snapshot paths, checked by the engine's `Selection`, that only choose entries of the
  snapshot. The staging directory is named by the job ID alone.
- The version 2 restore engine validates selected paths in a first pass, requires empty
  private staging, binds directory/file identities and checks parent components. It
  refuses existing symbolic/hard links and unexpected entries; snapshot links and special
  nodes are recorded outside the payload, never created. Local flock, creation intents and
  committed file/report offsets make interrupted ticks repeatable (ADR 0036). These checks
  rely on the caller keeping staging private; portable PHP cannot eliminate races with a
  local process that controls those directories. The live planner binds each operation
  to the verified source and destination. The worker rechecks the reviewed state before
  writing. Browser downloads require a single-use grant and a still-eligible staged file.
- A live file apply synchronises each journal record to disk before the live change it
  authorises, and each private copy before the record that names it (ADR 0041). Every job
  document is synchronised too, and a new job directory in `jobs/` before its first
  document, so that a power loss cannot leave a partial apply without the record of its
  undo journal. What a process of the same user changes in a live entry between the
  worker's last check and the rename or unlink is lost, and such a rename can replace an
  empty directory created at the same name in that moment. In a folder of more than 2,048
  names, a second spelling of a name that another process creates in the same second as
  one of the worker's own changes can stay hidden from the worker's rechecks for up to
  about one and a quarter seconds.
- The zip writer for downloads (`Storage\Zip\ZipWriter`, ADR 0038) reads staging the same
  way: it inspects every entry with `lstat()` and ends on a symbolic link, a second hard
  link or a special file. It refuses names that are not valid UTF-8 or that could leave the
  directory an archive is unpacked to (`..`, an absolute name, a drive letter, a backslash,
  a NUL). The unfinished archive, its checkpoint and the finished archive are `0600`.
- The private directory is excluded from backups except the virtual `spool/` paths. File
  apply never writes a snapshot's `spool/` to the live site, also after the private
  directory has moved; the database rehearsal reads the dump from staging.

## 9. WordPress layer

The admin menu, REST routes and worker authorization share the configured backup
capability (default `manage_options`). Live file and database operations apply the
additional checks in T-01 and T-02. Staging and rehearsal use the backup capability.
Every route that asks for the WordPress password asks through one check, which counts
wrong passwords per user, slows them down and reports them to `wp_login_failed` (§5.5).
Multisite is refused. The download handler accepts a one-time bearer grant issued through
the authenticated REST API; the grant of a restored file or ZIP also needs the WordPress
password and is recorded (§5.5). It uses POST, streams the verified descriptor and disables
caching. File-change maintenance admits the initiating control cookie or header to the
ordinary WordPress authorization checks for that job; a `rest_route` in a form body, by
which WordPress would route the request elsewhere, is refused. It also admits
`wp-cron.php` and the plugin's own WP-CLI command. Any other WP-CLI command runs with the
site's plugins, as during core's maintenance. An admitted request loads only this plugin,
releases only its cron events and cannot store the plugin list it booted with. During
maintenance the worker sees filters only from `wp-config.php` and must-use plugins, so a
`file_mod_allowed` or capability filter in a regular plugin or the theme applies at
confirmation and preflight only (ADR 0041). The plugin enqueues admin assets
only on its own screen and uses local WordPress scripts.

The routes and the command that read snapshots (`GET /snapshots…`, `ls`) treat every name,
link target, time and tag of a snapshot as untrusted: another client of the repository, or
whoever holds its password, chose them. A snapshot ID is 64 hex digits and a path passes
`Engine\Snapshot\Path::parts()` before the repository is asked; a path token is bounded
before it is decoded, and a path only ever selects entries inside the snapshot, never a
file of the site. A directory is answered in pages of at most 1000 entries. Text that is
not valid UTF-8 reaches a response only with replacement characters, and the command line
prints names, link targets and the host names of `snapshots` with control characters
quoted, so that a stored name cannot send escape sequences to a terminal ([ADR 0039](adr/0039-snapshot-routes.md)). The answers hold file
names and the site's manifest, no secret; like every route they need the capability. A
read creates a shared lock in the repository and removes it, which is its only write there;
on the site it writes encrypted trees and the index into the cache of the private directory.
The retention preview of the snapshot list decides with the policy of the forget job and
deletes nothing. The Snapshots tab of the admin page inserts every name, tag, link target
and manifest field as a text node, shows a link only as text, and asks for a directory by
the token the server issued. The paths ticked for a restore are kept in the page alone;
what a restore accepts is checked on the server when one is started.

## 10. Backup integrity and ransomware resistance

- Detect **append-only** automatically and recommend it; with append-only the retention mode
  is `external` ([ADR 0010](adr/0010-retention-and-append-only.md)). When the connection
  test finds that deletions are allowed, the wizard recommends to turn on append-only on the
  server: *"Then nobody who takes over this website can delete your backups."* On an
  append-only server it says: *"this site will never be able to delete backups."*
- `keep-within` default; monthly structural check with sampled data verification; a failed
  check blocks nothing but raises a persistent critical notice.
- Manifest records what was backed up. A reminder for restore drills does not exist yet and
  is planned for Milestone 3; an automated drill is on the backlog.
- Repository format version 1 for plugin-created repositories: no compression means no
  ambiguity about what the plugin can read back.

## 11. Supply chain

- **Runtime**: zero third-party PHP or JavaScript packages, no binaries, no downloads.
- **Development dependencies**: Composer packages are pinned by `composer.lock` and updated
  by Dependabot with CI gates, as are the GitHub Actions. PHPStan is the exception: it is
  installed from its release PHAR through an inline Composer package without a checksum,
  which Dependabot cannot update. The tools outside Composer are pinned by hand with SHA-256
  checksums: the restic CLI (0.20.0, 0.19.1, 0.18.1, 0.14.0), rest-server, Infection and WP-CLI in
  `bin/install-test-tools.sh`, WordPress for the test site in `bin/test-site.sh`. A version
  bump is a reviewed pull request; nothing picks up a new release by itself (planned for
  Milestone 3). The linter for the admin script comes from npm: ESLint and
  `@wordpress/eslint-plugin` with exact versions in `package.json`, and the about 400
  packages they bring pinned with their hashes in `package-lock.json`. CI installs them
  with `npm ci --ignore-scripts`, so no package runs a script while it is installed. Their
  versions are raised by hand as well.
- **CI**: actions pinned to commit SHAs, minimal permissions, no secrets for fork PRs; the
  restic CLI used as a test oracle is installed in CI from upstream releases with checksum
  verification. One job runs software that the project does not pin: the Plugin Check
  action itself is pinned, but it installs the current `@wordpress/env`, WordPress and
  Plugin Check when it runs. That job has a read-only token and no secret.
- **Distribution through WordPress.org** ([ADR 0017](adr/0017-distribution-via-wordpress-org.md)):
  updates only via WordPress.org; SVN deployment automated from signed tags in a protected
  environment with manual approval and a dedicated SVN password; 2FA on all committer
  accounts; post-release diff of the SVN tag; no packages on GitHub.
- **Repository hygiene**: branch protection with required status checks and no direct
  pushes, signed commits, `SECURITY.md`. The checks that exist are PHPCS, ESLint for the
  admin script, PHPStan, Plugin Check on the built package, the build of the translation
  template, the check that the commits of a pull request are signed off (it does not run
  on pull requests that Dependabot opened), the unit, database, conformance and
  integration suites and mutation testing
  ([`04-development-and-quality.md`](04-development-and-quality.md) §6). CodeQL for
  JavaScript is not set up yet and is planned for Milestone 3.

## 12. Logging and redaction

`Support\Redactor` masks the secret values it knows in what is written to job files
(`job.json`) and to event lines (`events.log`). It knows three values: the repository
password, the REST password, and the pair `user:password` of the REST credentials. Each is
replaced by `[redacted]` in three forms: as it is, URL-encoded and base64-encoded. The
base64 of the pair is what an `Authorization: Basic` header carries. A value of fewer than
four bytes is not registered, because replacing it would destroy ordinary text. The redactor
has no general pattern for `Authorization` headers or for credentials in a URL: a secret it
does not know passes through.

Diagnostic strings are masked before JSON encoding so that quoting or backslashes cannot
hide a password. The job's schema keys, states, IDs and restore selection must survive
a retry byte for byte. Those fields are operational inputs and must never carry
credentials. Error messages, warnings and current-path diagnostics are redacted.

It is a second line of defence: REST error payloads and e-mails do not pass through it,
because they are built from codes, times and counts, never from log lines or exception
texts. There is no HTTP log. Logs stay in the private directory, are capped at 1 MiB per
job, and are served only through the capability-checked endpoint. No telemetry, no external
logging.

## 13. Secure defaults enforced by the wizard

Three of the defaults are enforced by the core, whatever sends the request: the length of a
chosen repository password, the read-back of stored secrets and the retention floor.

| Default | Enforcement |
| --- | --- |
| HTTPS to the repository server | URL validation: `https://` only, for both server options |
| Strong repository password | generated (32 characters, 192 bits). Enforced by the core: a password that somebody chose for a new repository, in the request or in `wp-config.php`, must have at least 12 characters, else `Setup::create()` refuses with `password_too_short` |
| Recovery kit acknowledged | required before the first backup and before enabling the schedule; enforced by the core: jobs are refused with `setup_incomplete` until the setup is completed |
| Private directory protected | When the probe reads back its random token, the Doctor reports a critical finding, setup refuses with `environment_blocked` and backups stop. If the site cannot reach itself, the wizard requests the probe from the browser. An inconclusive result allows backups. Plaintext staging requires a verified denial at every public URL or verified storage outside the document root. |
| Secrets decryptable | enforced by the core: `EncryptedOptionStore::set()` reads every secret back after it stored it; if the secret is not there or comes back changed, saving fails with `secrets_undecryptable` |
| Retention floor | enforced by the core (`Schema::retention_floor()`): `retention.keep.within` of at least one day, or `retention.keep.last` of at least 1. The settings refuse a change below it with `retention_floor`; a forget job that finds such a pair stored fails with `configuration` and deletes nothing |
| Sensible schedule | daily backup at a randomised minute, weekly prune-lite, monthly check |
| Notifications on | e-mail on failure to `admin_email` |
| Supported repository | version 1, or version 2 with `ext-zstd`. Without the extension a version 2 repository is refused when it is unlocked, in the wizard as in every job, with `repository_unsupported_version`; the Doctor reports the same as a critical finding, which keeps the setup from being completed |
| Append-only | detected automatically and recommended; retention switches to `external` when present |

## 14. Disclosure policy

`SECURITY.md`: private reports via GitHub Security Advisories, acknowledgement within 72
hours, coordinated disclosure, credits in the changelog. Supported versions: the latest minor
release. `readme.txt` links to the policy.

## 15. Pre-release security checklist

- [ ] `reveal(` call sites equal the allow-list.
- [ ] Banned-function sniff passes (`exec`, `shell_exec`, `system`, `passthru`, `popen`,
      `proc_open`, `eval`, `unserialize`, `extract`).
- [ ] The release passes `tests/Integration/AccessTest.php`, including anonymous,
      delegated-role and registered-route coverage, and its packaged download test.
- [ ] Conformance suite green against the pinned restic releases (0.20.0, 0.19.1, 0.18.1, 0.14.0)
      and the latest release at that time.
- [ ] Covered mutation score of `Crypto`, `Format` and `Engine\Forget` at or above 90 %;
      nightly fuzzing without open crashes; self-review checklist
      ([`04-development-and-quality.md`](04-development-and-quality.md) §9.1) completed for
      every security-relevant change.
- [ ] Live smoke tests pass against a TLS rest-server with a private CA and against
      operator-provisioned BorgBase repositories in normal and append-only mode.
      [Operator smoke procedure](../tests/Live/README.md).
- [ ] Nonce-uniqueness and MAC-failure tests green; tests of the size limits green (the
      limits are in T-19).
- [ ] Log fixtures from integration runs contain no secret value.
- [ ] On Apache and nginx the real WordPress HTTP probe identifies both protected and
      exposed storage, removes its temporary file and permits plaintext staging only for
      protected storage. [Native server test](../tests/Hosting/private-directory.sh).
- [ ] The release passes file-plan and apply tests for symlinks, `..`, outside-root destinations and protected startup paths.
- [ ] Append-only run against `rest-server --append-only`: a forget or prune job ends as
      `skipped: append_only`, retention switches to `external`, and lock release still works.
- [ ] Plugin Check reports no errors; remaining warnings are the documented ones.
- [ ] `readme.txt` discloses the external service (the repository server, and BorgBase for
      its preset) accurately; deployed SVN tag matches the built package.
