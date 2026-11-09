# 0020 · Unlocking: resumable pure-PHP scrypt once, then a cached master key
Status: accepted
Date: 2026-11-09

## Context
restic key files derive the user key with scrypt (`N`, `r`, `p` stored in the key file, 64-byte
salt). PHP's high-level `sodium_crypto_pwhash_scryptsalsa208sha256` requires a 32-byte salt
and hides `N`, `r`, `p`, so it cannot be used. A pure-PHP scrypt with restic's typical
`N = 32768…131072, r = 8` needs 32–128 MiB of table memory and tens of seconds to minutes of
CPU on shared hosting, far beyond one request.

## Decision
- `Crypto\Scrypt` implements scrypt with `hash_pbkdf2('sha256')` for the outer steps and a
  hand-written ROMix/Salsa20/8 loop; the `V` table is **file-backed** in the private
  directory (`0600`), so memory stays small regardless of `N`; the loop is **resumable**: the
  job checkpoints `(phase, i, X)` at the end of each tick and continues in the next one.
- The derivation runs **once** per repository password. The resulting master key is cached
  in the encrypted secret store (ADR 0006); later operations skip the KDF entirely. The cache
  is invalidated when the password changes or becomes undecryptable.
- Repositories created by the plugin use `N = 16384, r = 8, p = 1` (restic's own defaults
  when calibration is unavailable; accepted by restic). Existing repositories keep their
  parameters; the UI shows unlock progress and an estimate.
- Test vectors: RFC 7914 scrypt vectors and key files produced by `restic init` on the CI
  matrix must open in PHP.

## Consequences
- The first unlock may take seconds (plugin-created repositories) to several minutes
  (repositories created by restic on fast machines); afterwards there is no KDF cost.
- The master key is a stored secret of the same class as the password; it lives only in the
  encrypted option, never on disk.
- A temporary table file of 16–128 MiB exists during an unlock; deleted afterwards.

## Alternatives considered
- **Adding a plugin-owned key with cheap parameters to the repository**: modifies the
  repository, equal security to caching, more moving parts.
- **`ext-scrypt`** (PECL): practically never installed on shared hosting.
- **Asking for the password on every run**: unattended backups would be impossible.
- **Lower `N` for new repositories**: pointless security loss for a two-second gain; passwords
  the plugin generates are random anyway.
