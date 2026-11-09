# 0006 · Secrets encrypted with a salts-derived key; constants override; cached master key
Status: accepted (revised for 0018/0020)
Date: 2026-11-09

## Context
The plugin needs three secrets for unattended operation: the restic repository password,
the repository master key derived from it (so that the expensive scrypt derivation runs once,
ADR 0020), and the BorgBase REST password. They must survive restarts, must not be readable
from a database leak alone, must never appear in logs or URLs, and the operator must be able
to recover.

## Decision
- Secrets are stored in one option (`backups_for_restic_secrets`, `autoload = no`) encrypted
  with `sodium_crypto_secretbox` using a key derived via HKDF-SHA256 from the `wp-config.php`
  salt constants and a random per-installation salt stored alongside the ciphertext.
- `wp-config.php` constants (`BACKUPS_FOR_RESTIC_REPOSITORY_PASSWORD`,
  `BACKUPS_FOR_RESTIC_REST_PASSWORD`, …) override stored values; the UI shows such fields as
  read-only. The cached master key is always stored in the option (no constant); it is
  re-derived from the password when it becomes undecryptable.
- The REST password is used only as an HTTP `Authorization` header to the repository host;
  the master key only inside `Crypto\Cipher`.
- A `Secret` value type prevents accidental serialisation and logging; `reveal()` call sites
  are allow-listed by static analysis.
- The wizard produces a recovery kit and requires acknowledgement; re-display requires the
  administrator's password.

## Consequences
- A database-only compromise yields ciphertext. A `wp-config.php` compromise yields
  everything — unavoidable and stated in the threat model.
- Rotating WordPress salts makes stored secrets undecryptable; the plugin detects this and
  asks for re-entry (constants avoid the issue).
- Sites whose salts are not constants get a Doctor warning.

## Alternatives considered
- **Plaintext in options**: unacceptable.
- **Key file in the private directory**: equivalent to directory protection, weaker than
  salts against DB leaks, one more thing to protect.
- **Operator passphrase on each run**: incompatible with unattended backups.
- **Adding a plugin-owned key file to the repository with cheap KDF parameters** instead of
  caching the master key: modifies the repository and offers no security advantage over the
  encrypted cache.
