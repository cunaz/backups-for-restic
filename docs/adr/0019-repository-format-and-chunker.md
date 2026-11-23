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
- Before calling ext-zstd, the reader checks frame boundaries, block sizes, the decoding
  window and the declared output size. For frames without a declared size it bounds the
  output from their blocks. It accepts concatenated frames and skips complete padding
  frames. Pack entries must also decode to the size in the pack header and match their
  SHA-256. The absolute limit is 128 MiB per object. On a host with a PHP memory limit,
  what decoding a frame takes must also fit into the memory that the limit leaves, less
  2 MiB for allocation rounding and for reporting the refusal.
  A job that reaches either limit reports `decompression_limit`. The exception is an index
  file that cannot be decoded beside the memory in use: the job's memory budget reports it
  as `index_too_large` ([ADR 0031](0031-compact-index-and-memory-budget.md)).
- What decoding takes was measured with ext-zstd 0.18.0 and libzstd 1.5.7 on PHP 8.3.36
  and 8.3.37. A frame that declares its size, as restic writes every object of 256 bytes or
  more, is decoded in one pass into a buffer of that size. The buffer is then copied into
  the returned string. At 8, 21.7 and 64 MiB the peak was exactly twice the size, and the
  size remained afterwards; there is no third copy. A frame without a declared size goes
  through a 128 KiB buffer into a string that grows by reallocation. From 1 to 64 MiB its
  peak in use was 1.06 to 1.50 times the output. In reserved memory it varied from run to
  run and reached 1.86 times, when the string could not grow in place. libzstd allocates
  the window of such a frame outside PHP's limit.
  The reader therefore counts twice a declared size, and for a frame without one, four
  times the larger of its window and the output its blocks allow. A frame that is not the
  whole input is copied out first. The output of a later frame is appended to what came
  before, which can copy that once. Both count as well.
- The first version of this check divided the memory left by four for every frame, while
  the sealed file, its plaintext and a copy without the encoding byte were still in memory.
  That refused the index files that restic 0.14.0 to 0.16.x write in compressed
  repositories. These releases close an index file at 150,000 blobs
  (`indexMaxBlobsCompressed`), 21.7 MiB of JSON and 7.5 MiB as zstd at level 3. Now the
  sealed file and the plaintext are let go before decoding, and the decoder works beside
  one copy of the compressed bytes. Such files were loaded through `Repository::load_index()`
  with the job's budget under `memory_limit=128M` on PHP 8.3.37, with memory filled to a
  baseline first.
  The baselines move in steps of 2 MiB, as PHP reserves memory. In every tick of the CI
  memory runs, WordPress 7.1.5 had reserved 34 MiB once it was loaded.

  | Version | One file: loads up to a baseline of | Peak at 34 MiB | Two files: loads up to |
  | --- | --- | --- | --- |
  | Before the frame check (`9bb0902`) | 62 MiB; a fatal out-of-memory error from 64 MiB | 100.0 MiB | 48 MiB; fatal from 50 MiB |
  | First frame check (`acaecdc`) | 16 MiB; `decompression_limit` from 18 MiB | refused | refused at every baseline down to 4 MiB |
  | Now | 62 MiB; `index_too_large` from 64 MiB | 84.9 MiB | 52 MiB; `index_too_large` from 54 MiB |

- A data blob of nearly 8 MiB that does not compress was decoded beside an index of 500,000
  blobs, as restore and check read it, with the memory reserved before the read as the
  measure. The first frame check refused it from 78.5 MiB, which that index and the 34 MiB
  baseline reach. The code before the frame check decoded it up to 94.5 MiB and ended with a
  fatal error from 96.5 MiB. Now it decodes up to 92.5 MiB and is refused with
  `decompression_limit` above that. From 104.5 MiB, decrypting the blob alone exhausts the
  limit in all three versions.
