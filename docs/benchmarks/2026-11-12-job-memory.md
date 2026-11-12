# Full-job memory, 2026-11-12

These measurements exercise valid repository history, traversal, pack reading, actual prune
deletion and an additional backup. The local results below use the standalone engine, without
WordPress. The CI matrix measures WordPress separately. Its observed results below apply to
this fixture and site configuration; they do not establish a capacity for every installation.

## Fixture and method

`tests/Support/MemoryRepository.php` builds a real restic directory layout. Each data blob has
a distinct 64-byte payload. Every leaf holds a file with up to 11 blob references; parent trees
have up to 64 children. Packs hold up to 40 blobs and index files about 50,000. This deliberately
creates many trees and packs rather than disguising a large index behind a single tree.
One additional indexed pack is unreachable, so prune must rewrite an index and delete data.

| Initial blobs | Tree blobs | Live data blobs | Unused blobs |
| ---: | ---: | ---: | ---: |
| 500,000 | 42,275 | 457,724 | 1 |
| 1,000,000 | 84,546 | 915,453 | 1 |
| 2,000,000 | 169,090 | 1,830,909 | 1 |

The producer uses 512M in its own process. Repository bytes remain on disk, outside every
measured PHP process. A backup then adds a fresh 16 MiB random file; check traverses all
snapshots and reads 10% of packs; prune removes the unused pack. These are cardinality
fixtures with small historical payloads, not a throughput test for terabytes of site data.

Each tick is a fresh PHP 8.3.36 process with `memory_limit=128M` and a 15-second cooperative
deadline. The reported peak is the maximum absolute `memory_get_peak_usage(true)` across
the operation's ticks. It includes bootstrap and restored checkpoints; no peak is reset and
no baseline is subtracted. The standalone baseline reserved 4 MiB. Concurrent local test
work makes elapsed time and tick counts unsuitable for a performance comparison.

The baseline is main `9bae8fd6bacc260d48b7fd84cc524fb8238b1c35`, with the same fixture and
measurement helpers. Backup starts cold and check uses its index cache. The old standalone
prune uses its default uncached engine path; the new prune receives the cache through its
new lazy-cache argument. The comparison therefore includes this change of engine cache
access and is not an isolated measurement of the index encoder, nor of the old WordPress
prune adapter, which preloaded its cache before calling the engine.

## Observed standalone results

| Initial blobs | Operation | Baseline peak MiB / outcome | New peak MiB / outcome |
| ---: | --- | --- | --- |
| 500,000 | Backup | 73.9 / completed | 77.5 / completed |
| 500,000 | Check | 64.5 / completed | 53.0 / completed |
| 500,000 | Prune | 83.6 / completed | 52.8 / completed |
| 1,000,000 | Backup | 104.5 / completed | 103.8 / completed |
| 1,000,000 | Check | 108.0 / completed | 106.0 / completed |
| 1,000,000 | Prune | 111.6 / `index_too_large` during marking | 96.3 / completed |
| 2,000,000 | Backup | fatal memory exhaustion | 109.6 / `index_too_large` |
| 2,000,000 | Check | fatal memory exhaustion | 110.1 / `index_too_large` |
| 2,000,000 | Prune | fatal memory exhaustion | 111.6 / `index_too_large` |

The old two-million-blob runs exhausted 128 MiB while `Cipher::open()` allocated the plaintext
of the next normal index file, before another pack reached the existing budget check. Its
prune was rerun after clearing only the dead runs' locks in the disposable fixture: otherwise
it correctly refused the locks left by the crashed backup/check. A fatal run cannot report
its final peak, so no peak is claimed for those cells.

The new successful checks report zero errors and warnings. Both successful prunes delete the
single unused pack. Backup's payload is random, so chunk boundaries and its peak vary between
runs; the small backup difference is not evidence of an improvement or regression. The large
tree/pack sets and JSON checkpoints remain in memory and explain much of check's remaining
cost at one million blobs.

Reproduce locally with a new directory for each size:

```sh
php -d memory_limit=512M bin/bench-job-memory.php create /tmp/bfr-memory-500k 500000
php -d memory_limit=128M bin/bench-job-memory.php tick /tmp/bfr-memory-500k backup 15
```

Repeat the `tick` command in a fresh process until `done` is true, then do the same for
`check` and `prune`. Exit 1 with `IndexTooLarge` is a capacity refusal; a fatal exit is a
failure of the memory boundary. Use separate directories for the one- and two-million sizes.

## WordPress acceptance in CI

The `memory` matrix in `.github/workflows/integration.yml` creates a separate WordPress and
TLS rest-server for each size. Setup and job creation use the plugin's REST API. Each tick
loads the real WordPress and calls `Cron::tick()` in a fresh PHP process with 128M,
`max_execution_time=30`, `set_time_limit` disabled and CLI opcache disabled. It runs the real
job steps, including database dump, file backup, cache, recording and cleanup. A coordinator
with 512M creates the history but never runs a measured job itself.

The memory matrix installs the site with `BFR_DISABLE_WP_CRON=1`, which sets
`DISABLE_WP_CRON` in its `wp-config.php`. The coordinator refuses to create jobs unless
that setting is active. Together with disabled plugin loopback chaining, this prevents
HTTP setup/job requests from spawning heartbeat ticks outside the measured workers.
Earlier runs without this setting cannot establish a WordPress memory peak.

The fixture and final repository must pass `restic check --read-data`. All three jobs at
500k must succeed. At 1M and 2M, a job must either succeed or record the explicit
`index_too_large` failure; crashes, other failures, changed PHP limits, unexpected check
errors, a prune that deleted nothing, and leftover locks fail CI. Success at the larger sizes
is recorded as such; a refusal is not counted as a completed backup/check/prune.

The workflow uploads `wordpress-memory-<size>` artifacts with the absolute used/reserved
peaks, bootstrap baseline, state, result and elapsed time of every tick, plus one summary per
operation and worker error logs. The upload explicitly includes hidden files because these
listed report/log paths are inside `.tools`; it fails if none of them are found.

## Observed WordPress results

The [integration run on `7ea0b0f`](https://github.com/cunaz/backups-for-restic/actions/runs/43198840915)
passed all three sizes with automatic WP-Cron and plugin loopback chaining disabled. These
absolute reserved peaks come from the operation summaries in the job logs, rounded to one
decimal place. Each row describes the plugin job outcome; a capacity refusal satisfies the
acceptance gate at the two larger sizes but does not complete the requested operation.

| Initial blobs | Operation | Peak MiB | Outcome |
| ---: | --- | ---: | --- |
| 500,000 | Backup | 101.4 | completed |
| 500,000 | Check | 106.1 | completed |
| 500,000 | Prune | 88.6 | completed |
| 1,000,000 | Backup | 111.6 | `index_too_large` |
| 1,000,000 | Check | 111.6 | `index_too_large` |
| 1,000,000 | Prune | 111.6 | `index_too_large` |
| 2,000,000 | Backup | 111.6 | `index_too_large` |
| 2,000,000 | Check | 111.6 | `index_too_large` |
| 2,000,000 | Prune | 111.6 | `index_too_large` |

Every operation released its repository lock, and the fixture and final repository passed
`restic check --read-data`. This run did not publish the detailed artifacts: the upload action
excluded the hidden `.tools` directory. The summaries remain in the job logs; publication of
the per-tick reports needs verification after the upload correction.

At 128M this WordPress fixture completes 500k blobs and refuses 1M and 2M. The standalone
one-million success is not a WordPress capacity claim. Larger in-memory tree/pack sets,
checkpoints and decoded-object/decompression bounds remain open in plan package 0.9.
