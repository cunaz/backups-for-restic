# 0019 · Repository format version 1 for new repositories; exact restic chunker
Status: accepted (revised after the Milestone 0 measurements)
Date: 2026-11-09

## Context
Repository format version 2 adds zstd compression for blobs and for index, snapshot and lock
files. PHP has no zstd support without the rare `ext-zstd` extension, so a version-2
repository written by restic cannot be read by the native engine. Milestone 0 showed that
this holds even with `restic backup --compression off`: in format 2, restic compresses
index and snapshot files unconditionally (restic 0.19.1, `SaveUnpacked`). A version-2
repository without compressed files does not occur in practice. For chunking, restic uses a Rabin content-defined chunker with a
per-repository polynomial; any split into blobs ≤ 8 MiB is format-valid, but only the exact
algorithm deduplicates against snapshots written by restic.

## Decision
- The plugin **creates repositories with format version 1**: no compression anywhere; every
  file remains readable by the plugin and by every restic release since 0.9.
- Connecting to an existing repository is allowed for version 1, and for version 2 only when
  `ext-zstd` is available; otherwise the wizard refuses with an explanation. In version 2
  the plugin writes uncompressed blobs and plain-JSON unpacked files, which restic accepts;
  the conformance suite verifies this in its zstd job.
- The documentation states plainly: **do not run `restic migrate upgrade_repo_v2` or
  `prune --repack-uncompressed` on a repository used by the plugin** unless `ext-zstd` is
  installed; the Doctor checks the repository version on every unlock.
- The chunker is restic's: Rabin fingerprint, 64-byte window, degree-53 polynomial from the
  repository config, minimum 512 KiB, maximum 8 MiB, split mask `(1 << 20) − 1`; the
  polynomial for new repositories is generated with the same GF(2) arithmetic and Ben-Or test.
- A benchmark gate in Milestone 0 (≥ 5 MB/s without JIT) decides whether a documented
  fallback is enabled: chunk only files larger than 8 MiB and the database stream, store
  smaller files as one blob each. The fallback is format-valid and costs only cross-engine
  deduplication of mid-sized files. Measured: 24 MB/s without JIT, so the fallback stays
  off (docs/benchmarks/2026-11-milestone-0.md).

## Consequences
- Database dumps and PHP code are stored uncompressed (uploads are already compressed
  media). Deduplication still removes unchanged content.
- Operators who later move to the restic CLI can upgrade the repository format once they no
  longer use the native engine.
- Chunk-boundary parity with restic is testable and tested.

## Alternatives considered
- **Version 2 with uncompressed writes**: valid, but one CLI command away from an
  unreadable repository.
- **Implementing zstd in PHP**: out of scope by an order of magnitude.
- **A simpler chunker (fixed-size or Gear)**: faster, but loses deduplication parity with
  restic and complicates the fallback story; kept only as the measured fallback above.

## As built (2026-11)
- The refusal is in the unlock step, which the wizard's unlock job and every other job
  run (`Jobs\Backup\BackupSteps::unlock()`): a repository of format 2 on a server without
  `ext-zstd` ends the job with `repository_unsupported_version` once its key is known,
  before anything else is read or written. The wizard shows that error and cannot be
  completed, because the Doctor is then critical.
- The Doctor does not read the repository itself. Its `zstd` check uses the setting
  `repository.format_version`, which the unlock step writes from the repository's config
  every time a job opens it (`Connection\RepositoryRecord`), also right before the refusal.
  A repository that is upgraded to format 2 from another computer is therefore noticed by
  the next job. The recovery kit and the site manifest name the same version.
- The fallback chunking rule was never written, and no switch for it exists. The gate that
  would have called for it was met with 24 MB/s.
