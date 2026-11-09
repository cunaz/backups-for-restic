# 0018 · A native PHP implementation of the restic repository format is the core engine
Status: accepted (supersedes 0004; demotes the binary engine to Appendix A; primitives revised by 0027)
Date: 2026-11-09

## Context
The target audience is **shared hosting**: no shell, `proc_open` usually disabled, no system
cron, PHP time and memory limits, CPU quotas. The plugin is distributed through WordPress.org,
which also forbids downloading executables (ADR 0016). A design that runs the `restic`
binary therefore excludes exactly the users the project is for.

The restic repository format is open, documented and stable (format version 2 dates from
2022), independent implementations exist (for example rustic in Rust), and its primitives
are mostly available in PHP: SHA-256, AES-256-CTR and AES-128 via OpenSSL, PBKDF2 via
`hash_pbkdf2`, CSPRNG via `random_bytes`. scrypt's ROMix loop, the Rabin chunker and
Poly1305 must be written by hand; PHP does not expose Poly1305 at all (ADR 0027). The REST
protocol is a handful of HTTP requests.

## Decision
- The plugin **implements the restic repository format in PHP** (`Crypto`, `Format`,
  `Backend`, `Engine` namespaces) and performs backup, restore, forget, prune-lite and check
  itself. It needs no external program.
- Repositories created by the plugin are ordinary restic repositories (format version 1,
  ADR 0019) that the restic CLI reads, checks and restores. The CLI is the conformance oracle
  in CI (ADR 0023), never a runtime dependency.
- All engine operations are resumable steps of the tick-driven job model (ADR 0011, 0014);
  nothing runs outside a bounded PHP request except in WP-CLI `--wait` mode.
- The engine's seams (`Backend`, `BlobStore`, `ResumableStep`) keep a future binary engine
  possible for VPS operators (Appendix A); it is not part of 1.0.
- Scope limits are explicit: no compression (ADR 0019), one-time unlock cost (ADR 0020),
  prune without repacking (ADR 0022).

## Consequences
- Works on every host that runs WordPress and allows outbound HTTPS. "Install and go" on
  WordPress.org.
- Roughly doubles the code compared with orchestrating a binary; the `Crypto`/`Format`
  namespaces need vectors, property tests, mutation testing and fuzzing. There is no second
  reviewer and no external audit before 1.0: an accepted risk with compensating controls
  ([ADR 0024](0024-governance-and-quality-assurance.md)).
- First backups are CPU-bound in PHP: about 80 CPU seconds per gigabyte on a 2.1 GHz vCPU
  without JIT (docs/benchmarks/2026-11-milestone-0.md). Spread over ticks on shared hosting,
  multi-gigabyte sites still take hours of wall time; subsequent backups touch only changed
  files. Expectations are set in the wizard.
- A bug in the format code can damage a repository; the oracle suite and the monthly check
  are the safety net, and the plain-restic recovery path is unaffected by plugin bugs.
- Compression and full prune are unavailable in the native engine; both are documented as
  CLI operations from another computer.

## Alternatives considered
- **Binary engine only** (the previous plan): excludes shared hosting.
- **Hybrid with the binary as default and PHP as fallback**: both engines would have to ship
  in 1.0 and be tested; the binary adds nothing for the target audience.
- **Pull-based external runner** (restic on another machine fetching site data through an
  authenticated endpoint): exposes all site data through a new endpoint; a large attack
  surface for a different product.
- **A different, PHP-friendly backup format**: would not be restic, would not work with
  BorgBase's restic endpoint, and would lose the CLI recovery path.
