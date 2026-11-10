# 0002 · Runtime dependencies: PHP and WordPress only
Status: accepted (revised for 0018 and 0027)
Date: 2026-11-09

## Context
The project asks for few dependencies, a codebase that humans can audit, security by design,
distribution through WordPress.org and operation on shared hosting. Every third-party
package at runtime is code the maintainers must review; every external program is something
shared hosting does not have.

## Decision
- Runtime requirements: PHP ≥ 8.3 with `ext-openssl`, `ext-json`, `ext-mbstring` and
  `ext-hash` (always present); `ext-curl` recommended for the WordPress HTTP API; WordPress
  ≥ 6.7. The engine uses nothing from sodium (ADR 0027); stored secrets use
  `sodium_crypto_secretbox()`, which `ext-sodium` or WordPress' bundled `sodium_compat`
  provides (ADR 0006). Optional when present: `ext-zstd`, needed to read format 2
  repositories that restic created (ADR 0019).
- **No external programs**: no restic binary, no `proc_open`, no shell, no `mysqldump`, no
  `ssh`. The plugin starts no process.
- No Composer packages in production; Composer is development tooling only. A hand-written
  PSR-4 autoloader ships in `src/autoload.php`.
- No JavaScript packages in production; plain ES modules and core's `wp.apiFetch`.

## Consequences
- Installs and runs on every hosting plan that runs WordPress and allows outbound HTTPS.
- The plugin carries its own implementation of the restic format (ADR 0018) with the testing
  burden that implies (ADR 0023).
- Archives for downloads use PHP's `ZipArchive` when available, otherwise per-file downloads.

## Alternatives considered
- **Composer autoloader with zero packages**: `vendor/` noise for nothing.
- **Bundling or downloading restic**: excluded by WordPress.org guideline 8 and by the target
  audience (ADR 0016).
- **Action Scheduler**: large dependency for a problem solved by one file-based state
  machine (ADR 0004, 0018).

## As built (2026-11)
- The admin interface is one classic script, `assets/js/admin.js`, not ES modules. ADR 0012
  gives the reason: WordPress delivers translations to classic script handles. The decision
  stands: no JavaScript package ships.
- Nothing uses `ZipArchive`: downloads from a snapshot do not exist yet. They come with
  restore in Milestone 3.
