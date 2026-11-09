# 03 · Security design and threat model

Security is a design input, not a review step. This document lists what we protect, from
whom, and which control addresses each threat. Every control has an owner in the module map
of [`02-architecture.md`](02-architecture.md) and a test in the quality plan.

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
4. **Fail closed**: unverifiable preconditions block the operation; an authentication failure
   on any repository object is an error, never a warning.
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
| **REST credentials** (BorgBase or rest-server) | high | — | medium | Read/write (or append-only) access to the repository; regenerable on the server |
| Site files incl. `wp-config.php` (in snapshots) | high | high | high | DB credentials, salts |
| Database content (in snapshots, in restore staging) | high | high | — | user data, hashed passwords, sessions |
| Snapshots and packs in the repository | high (ciphertext) | critical | critical | Ransomware target |
| Local caches (index set, tree packs) | medium | high | — | Encrypted; integrity matters for dedup decisions |
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
 Repository server: BorgBase or own rest-server (ciphertext, sizes, timings; enforces append-only)
```

| Actor | Capability | Trusted? |
| --- | --- | --- |
| Unauthenticated visitor | HTTP requests to any web-reachable file | no |
| Low-privileged WordPress user | authenticated, no `manage_options` | no |
| Administrator | everything in wp-admin | yes, but phishable and fallible |
| Code running as the PHP user | read `wp-config.php`, files, memory of the same process | **no**, but beyond our control; we limit blast radius |
| Other OS users on a shared host | depends on file modes; cannot read our `0700` directory | no |
| Database-only attacker | reads/writes the options table | no |
| Network attacker | observes or modifies traffic to BorgBase | no |
| Repository server operator (BorgBase or self-hoster) | stores ciphertext; could delete or withhold data; enforces append-only | partially (availability), not for confidentiality |
| Supply chain (our dependencies, CI, WordPress.org credentials) | could inject code | must be verified |

## 4. Threats and controls

STRIDE class in brackets. "Residual" is what remains after the control.

| ID | Threat | Control(s) | Residual |
| --- | --- | --- | --- |
| T-01 | Unauthorised user starts a backup/restore, changes settings, downloads staged files [E, T] | One `Capability` helper used by every page, route and form; nonces; REST permission callbacks; one-time download tokens; no public endpoint | compromised admin account |
| T-02 | CSRF on destructive actions (restore, apply, prune, unlock) [T] | nonces; `POST` with typed confirmation words; core `SameSite` cookies | — |
| T-03 | Secrets leak through the database (SQL injection elsewhere, leaked DB dump) [I] | repository password, REST password and cached master key encrypted with a key derived from `wp-config.php` salts (§5); `autoload = no`; constants in `wp-config.php` as an alternative | attacker with DB **and** `wp-config.php` |
| T-04 | Secrets leak via logs, notices, e-mails, REST responses, HTML forms [I] | `Secret` type redacts itself; `Redactor` scrubs every output; secret fields are write-only; recovery kit only after re-authentication; tests assert no secret substring in any log fixture | browser extensions on the admin's machine |
| T-05 | Web access to the private directory (caches, checkpoints, staging, KDF checkpoint) [I, T] | directory outside the web root recommended and supported; otherwise `.htaccess`, `web.config`, `index.php`, `0700`; **active HTTP probe** fails the Doctor if the directory is served; caches encrypted with the master key; staging and import files `0600` and short-lived | servers that serve dotfiles and ignore modes, caught by the probe |
| T-06 | Nonce reuse or weak randomness breaks confidentiality of AES-CTR [I] | nonces, salts, ids and passwords from `random_bytes()` (CSPRNG) only; one nonce per sealed object; unit test asserts distinct nonces across a batch; no deterministic nonce derivation anywhere | OS CSPRNG failure |
| T-07 | Tampered or corrupted repository objects accepted (malicious BorgBase, MITM, disk error) [T] | Poly1305 tag verified before any decryption (constant time); blob id (SHA-256 of plaintext) verified after decryption; pack and file ids verified on download; index and tree JSON validated against expected shapes; `authentication_failed` aborts the job | — |
| T-08 | Weak password brute-forced from a leaked key file [I] | generated passwords are 192-bit random; user-chosen passwords ≥ 12 characters; scrypt with `N ≥ 16384, r = 8` (restic's default); key files live only on BorgBase | weak user passwords remain weak |
| T-09 | Cached master key or KDF checkpoint read from disk [I] | master key only in the encrypted secret store (DB), never on disk; the scrypt ROMix table is written `0600` to the private directory only during an unlock and deleted afterwards; the table alone does not reveal the password | same-user attacker during an unlock |
| T-10 | Path traversal or symlink attack during restore/apply [T] | staging first; `realpath` containment; symlinks in staging refused; `wp-config.php` and the plugin directory skipped unless chosen; dry-run report | malicious content inside a legitimate backup (documented) |
| T-11 | Attacker on the server deletes or corrupts backups (ransomware) [T, D] | recommend BorgBase **append-only** with retention mode `external`; `--keep-within` guard; prune-lite and forget only in `plugin` mode; BorgBase stale alerts as independent monitoring | in `plugin` mode the credentials can delete; operator's documented choice |
| T-12 | Fake snapshots injected to expire real ones through count-based retention [T] | `keep-within 2d` default; forget scoped by host and tags; monthly check | noise within the window |
| T-13 | Network attacker intercepts or modifies traffic [I, T] | only `https://`; TLS verified against WordPress' CA bundle or an uploaded CA certificate for a self-hosted server, never disabled; redirects disabled so credentials never follow a redirect to another host; every object is encrypted and authenticated end-to-end anyway | — |
| T-14 | Malicious or buggy format implementation corrupts the repository [T, D] | no home-made cryptography; differential tests against the restic CLI on every change (`restic check --read-data`, byte-identical restores, chunk boundary parity); known-answer vectors from `restic cat`, RFC 7914, RFC 8439 and libsodium; property tests for pack/index round trips; mutation testing as a merge gate and nightly fuzzing of every parser; self-review checklist and 24-hour cooling-off for `Crypto`, `Format` and `Engine` ([ADR 0024](adr/0024-governance-and-quality-assurance.md)) | **accepted**: no independent review before 1.0; design or side-channel flaws that differential tests cannot see. Safety nets: monthly checks and the plugin-independent CLI recovery path |
| T-15 | Resource exhaustion: CPU-second quotas, memory limits, disk quotas, request limits [D] | bounded ticks; streaming; pack temp ≤ 16 MiB; cache cap; `cpu_throttle: gentle`; preflight checks free space and memory; one job at a time; sequential uploads | hosts with extreme limits — surfaced by the Doctor |
| T-16 | Stale or foreign repository locks block backups [D] | locks refreshed every 5 minutes by ticks/heartbeat; own stale locks removed as restic does; foreign stale locks removable by explicit admin action with warning; never automatic | other clients writing to the same repository |
| T-17 | Secrets undecryptable after salt rotation [D] | detected on load (`undecryptable`); Site Health critical; re-entry from the recovery kit; constants unaffected | operators without a stored kit — mandatory acknowledgement |
| T-18 | Salts not defined as constants (WordPress stores them in the DB) [I] | Doctor warning with instructions | — |
| T-19 | Malicious data from the server exhausts memory or time (huge index/tree JSON, deep nesting) [D] | size caps (index file 128 MiB, tree blob 32 MiB, snapshot 1 MiB, lock 64 KiB), JSON depth limit, blob length ≤ 32 MiB, header length ≤ 16 MiB; parse errors → `authentication_failed`-class abort | — |
| T-20 | Information disclosure to low-privileged users via Site Health or REST [I] | admin-only by core and by capability checks; generic errors elsewhere | — |
| T-21 | On multisite, a site administrator backs up or restores the whole network [E] | activation refused on multisite in 1.0, network-wide and per site; runtime guard keeps the plugin inert if activated otherwise ([ADR 0026](adr/0026-multisite-deferred.md)) | — |
| T-22 | Races: two ticks, two jobs, two uploads of the same pack [T, D] | `flock`; lease with expiry; single-active-job invariant; content-addressed names make duplicate uploads harmless (server answers 403 for an existing object, treated as success after a `HEAD` size check) | NFS without `flock` (documented) |
| T-23 | Plugin update introduces malicious code (maintainer account, CI, WordPress.org credentials) [T] | signed tags; SVN deployment only from CI in a protected environment that the owner approves per release; dedicated WordPress.org SVN password as an environment secret, rotated yearly and on suspicion; 2FA on the owner's WordPress.org and GitHub accounts; no runtime third-party code; Dependabot; post-deploy diff of the SVN tag against the built package | compromise of GitHub or WordPress.org infrastructure; **accepted**: bus factor 1 for releases ([ADR 0024](adr/0024-governance-and-quality-assurance.md)) |
| T-24 | WP-CLI `recovery-kit` output captured in shell history or logs [I] | refuses non-TTY output without `--force`; logged as an event (who, when) | shell access as the PHP user already implies full access |
| T-25 | Timing side channels in PHP code touching secrets [I] | Poly1305 without secret-dependent branches or table lookups, tags compared with `hash_equals` ([ADR 0027](adr/0027-poly1305-in-pure-php.md)); comparisons of ids with `hash_equals`; scrypt's Salsa20/8 is add-rotate-xor only; table lookups in the chunker depend on file data, not secrets | PHP's own variability — no known practical attack |
| T-26 | An administrator-configured server URL makes the site send requests to internal services (SSRF) [I, T] | only administrators configure the URL; HTTPS only; redirects disabled; responses are parsed as restic objects and never shown raw; errors show status codes only; the BorgBase preset restricts the host to `*.repo.borgbase.com` ([ADR 0025](adr/0025-repository-servers.md)) | administrators can usually run arbitrary code anyway |
| T-27 | An uploaded CA certificate weakens TLS for other requests [I, T] | the certificate applies only to requests to the configured repository server (per-request `sslcertificates`), never globally; PEM validated with `openssl_x509_parse`; stored in the private directory | — |

## 5. Secrets

### 5.1 Inventory

| Secret | Source | Rotation | Where used |
| --- | --- | --- | --- |
| Repository password | generated by the wizard (32 chars, CSPRNG, 192 bits) or entered for an existing repository | `restic key add`/`key remove` from any computer; the plugin re-unlocks with the new password | scrypt input during unlock; recovery kit |
| Repository master key | derived once from the password via the key file | changes only with `restic copy` to a new repository | every seal/open; cached in the secret store |
| REST password | BorgBase control panel | regenerate in BorgBase, paste into settings | `Authorization` header |
| REST username | BorgBase control panel; treated as sensitive | with the password | `Authorization` header |
| Download tokens | random per staged file | one-time, 1 h | download URL |

### 5.2 Storage: encrypted option, or constants

`EncryptedOptionStore` keeps a single option `backups_for_restic_secrets` (`autoload = no`)
with `name → ciphertext`. Ciphertext format
`v1.<base64url(nonce 24 B)>.<base64url(sodium_crypto_secretbox output)>`; the key is
`hash_hkdf('sha256', ikm, 32, 'backups-for-restic/secrets/v1', salt)` with `ikm` = the eight key
and salt constants read directly (not `wp_salt()`, which silently generates database-stored values)
and `salt` a random 32-byte value stored next to the ciphertext. `ConstantOverrideStore`
wins when `BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD` or `BACKUPS_FOR_RESTIC_REST_PASSWORD` is
defined. The cached master key is always in the option store (there is no constant for it);
if the password constant is defined, the plugin re-derives and re-caches after a salt change
without asking. Decryption failure yields `undecryptable`, never an empty secret
([ADR 0006](adr/0006-secrets-at-rest.md)).

### 5.3 In memory and in code

- `Secrets\Secret` and `Crypto\MasterKey` are `final` and redact themselves in
  `__toString` and `__debugInfo`; `jsonSerialize` throws. `Secret` keeps its value inside
  a closure, so `var_export()` shows nothing and `serialize()` fails. The raw value is only
  available through `reveal()`, whose call sites are allow-listed by
  `tests/PHPStan/SecretRevealRule.php`: `Secrets\Secret`, `Secrets\EncryptedOptionStore`,
  `Backend\RestBackend` (basic auth header), `Connection\RepositoryAccess`,
  `Connection\ConnectionTester`, `Connection\Setup`, `Engine\KeyUnlock`,
  `Support\Redactor` and `WordPress\RecoveryKit`. A new call site fails CI until the list
  is changed in a reviewed pull request.
- `RestBackend` keeps the REST password as a `Secret`; job files and logs pass through a
  `Redactor` that replaces every known secret, also URL- or base64-encoded.
- Functions taking raw secret strings are annotated `#[\SensitiveParameter]`, so PHP redacts
  them from stack traces on every supported version (floor 8.3,
  [ADR 0003](adr/0003-version-floors.md)). This matters on shared hosting, where PHP error
  logs often land inside the web root.
- `sodium_memzero()` on revealed copies where the API allows it (hygiene, not a guarantee).
- The scrypt table file is written with `umask(077)`, is never the password itself, and is
  deleted in the same tick that finishes the derivation (or by the heartbeat if the job dies).

### 5.4 Deliberate exposure points (complete list)

1. The `Authorization` header of HTTPS requests to the repository host (TLS protected).
2. The recovery kit view (admin UI after re-authentication; WP-CLI on a TTY).
3. The settings form input when an administrator types a new value.
4. The `wp-config.php` constants, if the operator chose them.

Anything else that contains a secret is a bug.

### 5.5 Recovery kit

Repository URL, REST username and password, repository password, repository format version,
plugin version, date, and a three-line restore recipe. Shown once in the wizard with copy and
download (`text/plain`, not stored); checkbox "I have stored the recovery kit outside this
server" required before the first backup. Later viewing requires the current user's WordPress
password (`wp_check_password`), valid for 5 minutes, logged as an event.

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
  - The Rabin chunker plus GF(2) polynomial arithmetic: verified against `restic backup`
    chunk boundaries and restic's chunker test vectors; not security critical.
- **Verification order** on read: object id (SHA-256 of ciphertext file) → Poly1305 tag →
  decrypt → blob id (SHA-256 of plaintext) → JSON shape and size caps. Any failure aborts the
  job with `authentication_failed`; the UI explains that the repository may be damaged and
  points to `restic check`.
- **Write order** on backup: encrypt → append to pack → upload pack (server verifies the
  SHA-256 name) → record pack in the checkpoint → write index → reference blobs in trees → write
  snapshot. A crash at any point leaves at most unreferenced data, never a snapshot that
  references missing blobs.
- **Conformance** ([ADR 0023](adr/0023-restic-cli-as-conformance-oracle.md)): every change to
  `Crypto`, `Format`, `Engine` runs the oracle suite: PHP-written repositories pass
  `restic check --read-data`; `restic restore` reproduces the fixture byte for byte; PHP reads
  and restores repositories written by restic (format version 1, and version 2 with
  `ext-zstd`);
  `restic cat` vectors for keys, config, packs, blobs, trees, snapshots, index and locks
  decode identically.
- **Review** ([ADR 0024](adr/0024-governance-and-quality-assurance.md)): the project has one
  maintainer and no external audit before 1.0. Compensating controls: differential tests
  against the restic CLI, known-answer vectors, mutation testing as a merge gate, nightly
  fuzzing of every parser, a self-review checklist and a 24-hour cooling-off period for
  `Crypto`, `Format` and `Engine` changes. The residual risk is accepted (T-14).

## 7. Network

- Only `https://` repository URLs. The BorgBase preset requires a `*.repo.borgbase.com` host;
  the rest-server option accepts any host, port and path
  ([ADR 0025](adr/0025-repository-servers.md)).
- WordPress HTTP API with `sslverify => true` (never overridable), `redirection => 0`,
  explicit timeouts, basic auth via header, plugin User-Agent, no cookies.
- Retries only for idempotent operations and only on network errors or 5xx; 4xx are final
  and mapped to dedicated errors (401/403 → credentials or append-only; 404 → missing; 400 →
  upload rejected; 507 → quota).
- A CA certificate can be uploaded for a self-hosted server with self-signed TLS; it applies
  only to requests to that server. Disabling verification has no equivalent in the plugin.
- Requests from the site to itself (the exposure probe, the loopback check and loopback
  chaining) follow core's `https_local_ssl_verify` filter, as core's own loopback requests
  do. They carry no secrets and never reach the repository server.

## 8. Filesystem

- Private directory `0700`, `umask(077)` for everything the plugin writes; atomic writes
  (`tempnam` in the same directory → `rename`); never the system temp directory.
- Caches hold ciphertext (tree packs as stored on the server, index set encrypted with the
  master key) and are validated by SHA-256 on load.
- Pack temp files contain ciphertext only. The KDF table is the one sensitive temporary file
  and is deleted immediately after use.
- Restore staging and database import files are plaintext by necessity: `0600`, expire after
  24 hours, deleted after a successful apply/import.
- Symlinks are never followed when applying restores; `realpath` containment on every
  destination; `lstat` to detect links in staging.
- The private directory is excluded from backups except the virtual `spool/` paths.

## 9. WordPress layer

Capabilities via one helper (`manage_options`, filterable; multisite refused in 1.0);
nonces on every form; REST permission callbacks; validate-then-reject for anything that
reaches the engine or the filesystem; escape on output; no `$_GET`/`$_POST` outside request
adapters; `ABSPATH` guard in every file; `uninstall.php` guarded by `WP_UNINSTALL_PLUGIN`;
no `eval`, dynamic includes, `unserialize` or remote code; assets enqueued only on the
plugin's screens; no third-party assets or CDNs.

## 10. Backup integrity and ransomware resistance

- Detect **append-only** automatically and recommend it; with append-only the retention mode
  is `external` ([ADR 0010](adr/0010-retention-and-append-only.md)). Wizard text: *"Append-only means an attacker who takes over this server cannot
  delete your backups. In exchange, cleaning up old snapshots happens from another computer."*
- `keep-within` default; monthly structural check with sampled data verification; a failed
  check blocks nothing but raises a persistent critical notice.
- Manifest records what was backed up; quarterly restore drill reminder; automated drill on
  the backlog.
- Repository format version 1 for plugin-created repositories: no compression means no
  ambiguity about what the plugin can read back.

## 11. Supply chain

- **Runtime**: zero third-party PHP or JavaScript packages, no binaries, no downloads.
- **Development dependencies** pinned by lock files, updated via Dependabot with CI gates.
- **CI**: actions pinned to commit SHAs, minimal permissions, no secrets for fork PRs; the
  restic CLI used as a test oracle is installed in CI from upstream releases with checksum
  verification.
- **Distribution through WordPress.org** ([ADR 0017](adr/0017-distribution-via-wordpress-org.md)):
  updates only via WordPress.org; SVN deployment automated from signed tags in a protected
  environment with manual approval and a dedicated SVN password; 2FA on all committer
  accounts; post-release diff of the SVN tag; no packages on GitHub.
- **Repository hygiene**: branch protection with required status checks and no direct
  pushes, signed commits, CodeQL for JavaScript, PHPStan, PHPCS, Plugin Check and mutation
  testing as required checks, `SECURITY.md`.

## 12. Logging and redaction

`Support\Redactor` knows every current secret and masks `Authorization` values and
`https://user:pass@` patterns; applied to events, HTTP logs, exceptions, REST error payloads
and e-mails. Logs stay in the private directory, are capped, and are served only through the
capability-checked endpoint. No telemetry, no external logging.

## 13. Secure defaults enforced by the wizard

| Default | Enforcement |
| --- | --- |
| HTTPS to BorgBase | URL validation |
| Strong repository password | generated; user-entered ≥ 12 characters |
| Recovery kit acknowledged | required before the first backup and before enabling the schedule |
| Private directory protected | HTTP probe must pass |
| Secrets decryptable | round-trip after saving |
| Retention floor | `keep_within ≥ 1d` or `keep_last ≥ 1` |
| Sensible schedule | daily backup at a randomised minute, weekly prune-lite, monthly check |
| Notifications on | e-mail on failure to `admin_email` |
| Supported repository | version 1, or version 2 with `ext-zstd` |
| Append-only | detected automatically and recommended; retention switches to `external` when present |

## 14. Disclosure policy

`SECURITY.md`: private reports via GitHub Security Advisories, acknowledgement within 72
hours, coordinated disclosure, credits in the changelog. Supported versions: the latest minor
release. `readme.txt` links to the policy.

## 15. Pre-release security checklist

- [ ] `reveal(` call sites equal the allow-list.
- [ ] Banned-function sniff passes (`exec`, `shell_exec`, `system`, `passthru`, `popen`,
      `proc_open`, `eval`, `unserialize`, `extract`).
- [ ] Every REST route has a `permission_callback` (test enumerates routes); every form a nonce.
- [ ] Conformance suite green against restic 0.18.x and the latest release.
- [ ] Covered mutation score of `Crypto` and `Format` at or above 90 %; nightly fuzzing
      without open crashes; self-review checklist completed for every security-relevant change.
- [ ] Live smoke tests green against BorgBase free-tier repositories (normal and
      append-only) and a TLS rest-server with a self-signed CA.
- [ ] Nonce-uniqueness and MAC-failure tests green; size-cap tests green.
- [ ] Log fixtures from integration runs contain no secret value.
- [ ] HTTP probe behaves on Apache and nginx fixtures.
- [ ] Apply refuses symlinks, `..` and outside-root destinations.
- [ ] Append-only integration run: forget → `repository_append_only`; lock release still works.
- [ ] Plugin Check reports no errors; remaining warnings are the documented ones.
- [ ] `readme.txt` discloses the external service (BorgBase) accurately; deployed SVN tag
      matches the built package.
