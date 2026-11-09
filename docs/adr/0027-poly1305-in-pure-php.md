# 0027 · Poly1305 in pure PHP; the engine needs no sodium
Status: accepted (revises 0002 and 0018)
Date: 2026-11-09

## Context
restic authenticates every encrypted file with Poly1305-AES. ADR 0018 assumed that PHP
offers Poly1305 through libsodium, either `ext-sodium` or WordPress' bundled
`sodium_compat`. Milestone 0 showed that this is wrong: PHP's sodium extension does not
expose libsodium's `crypto_onetimeauth()`, OpenSSL's Poly1305 is not reachable from PHP, and
`sodium_compat` keeps its Poly1305 in internal classes that are not a public API. The only
primitive-level choice left is to compute Poly1305 in PHP.

## Decision
- `Crypto\Poly1305` implements RFC 8439 Poly1305 following poly1305-donna with five 26-bit
  limbs. Every intermediate product fits into PHP's 64-bit integers, there are no
  secret-dependent branches or table lookups, the final reduction selects with a mask, and
  tags are compared with `hash_equals()`.
- The engine therefore needs `ext-openssl` (AES-256-CTR, AES-128-ECB), `ext-hash` (SHA-256,
  PBKDF2), `ext-json`, `ext-mbstring` and `random_bytes()`, and nothing from sodium.
  `ext-sodium` is no longer recommended. The WordPress layer still encrypts stored secrets
  with `sodium_crypto_secretbox()`, which WordPress polyfills (ADR 0006); speed does not
  matter there.
- Verification: the RFC 8439 test vector; 400 vectors generated with the system libsodium
  (`tests/Fixtures/vectors/poly1305-libsodium.json`) with message lengths from 0 to 9000
  bytes around the 16-byte block and 4 KiB slice boundaries, all-ones keys and messages for
  maximal carries, and keys with a zero `r`; every repository file recorded from restic; the
  conformance suite in both directions; and mutation testing of `Crypto` at the 90 % gate
  (ADR 0024).

## Consequences
- The project now carries three hand-written primitives: scrypt's ROMix (ADR 0020), the
  Rabin chunker (ADR 0019) and Poly1305. All three are pure integer arithmetic with
  known-answer vectors from independent implementations, and restic itself checks the
  result in CI. The statement "no custom cryptographic primitives" becomes "no home-made
  cryptography": AES and SHA-256 come from OpenSSL, and the three primitives PHP lacks are
  textbook algorithms tested against reference implementations.
- Measured cost on PHP 8.3 without JIT: 62 MB/s for Poly1305, faster than the chunker that
  feeds it (docs/benchmarks/2026-11-milestone-0.md). No host needs an extension for speed.
- One fewer runtime difference between hosts: the same code path runs everywhere.

## Alternatives considered
- **`sodium_compat` internals** (`ParagonIE_Sodium_Core_Poly1305`): not a public API, can
  change in any WordPress release, and is itself pure PHP, so nothing is gained.
- **Requiring a PECL extension**: excludes most shared hosting.
- **Computing the MAC through `sodium_crypto_aead_*`**: those constructions use Poly1305 with
  keys derived inside libsodium; restic's Poly1305-AES key cannot be injected.
