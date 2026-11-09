# Benchmark · Milestone 0 engine core

Measured on 2026-11-09 with `bin/bench.php`. The script exits with status 1 when a
Milestone 0 gate fails ([roadmap](../05-roadmap.md)). Repeat it on new hardware or after
changes to `Crypto`, `Format` or `Engine` and add a dated file next to this one.

```bash
php -d opcache.enable_cli=0 bin/bench.php --slow
```

```bash
php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M bin/bench.php
```

The first line of the output states whether JIT is really on. PHP silently disables JIT when
a coverage extension is loaded; with pcov installed, add `-d pcov.enabled=0`.

## Machine

| Item | Value |
| --- | --- |
| CPU | Intel Xeon @ 2.10 GHz, 4 vCPUs (the engine uses one) |
| Memory | 16 GB |
| PHP | 8.3.6 (Ubuntu build), CLI |
| Backend | `InMemoryBackend`, so network and disk writes are excluded |

A cloud vCPU is in the same class as the CPU share of a typical shared-hosting account.
Slower hosts scale roughly linearly; the gates leave a wide margin. Repeated runs on this
machine varied by up to a quarter, for example 45 to 62 MB/s for Poly1305, which the
margins easily absorb.

## Results

The baseline is PHP 8.3 without OPcache and without JIT, which is what most shared hosts
run for CLI and many run for web requests.

| Measurement | No JIT | JIT tracing | Gate (no JIT) |
| --- | --- | --- | --- |
| Rabin chunker | 24.4 MB/s | 124.0 MB/s | ≥ 5 MB/s, passed |
| Poly1305, pure PHP | 62.3 MB/s | 143.2 MB/s | none |
| Seal: AES-256-CTR plus Poly1305 | 52.1 MB/s | 103.7 MB/s | none |
| SHA-256 | 261.1 MB/s | 260.0 MB/s | none |
| scrypt N=16384, r=8, p=1 (keys the plugin creates) | 1.4 s | 0.4 s | ≤ 30 s, passed |
| scrypt N=32768, r=8, p=6 (a key restic calibrated) | 17.9 s | not measured | none |
| Backup of 256 MB in 8 MiB files plus 2000 small files | 12.3 MB/s | 30.7 MB/s | none |
| Backup CPU time, extrapolated to 1 GB | 1.3 min | 0.5 min | ≤ 15 min, passed |

## Reading the numbers

- **The chunker dominates.** Per megabyte of new data, a backup spends about 41 ms in the
  chunker, 19 ms sealing and 8 ms hashing (blob id and pack id). Unchanged files cost
  almost nothing because the parent snapshot lets the engine skip them.
- **Poly1305 in pure PHP is not a bottleneck.** PHP's sodium extension does not expose
  `crypto_onetimeauth`, so the engine computes Poly1305 itself. At 62 MB/s it is faster
  than the chunker that feeds it.
- **Unlocking is a one-time cost.** A key the plugin creates opens in about a second and a
  half. A key that restic calibrated on a fast machine takes tens of seconds, spread over
  ticks by the resumable scrypt, and the master key is cached afterwards
  ([ADR 0020](../adr/0020-unlocking-and-kdf.md)).
- **The fallback chunking rule is not needed.** The roadmap reserved it for a chunker below
  5 MB/s ([ADR 0019](../adr/0019-repository-format-and-chunker.md)).
- **First backups of large sites take ticks, not minutes of wall time per tick.** At about
  12 MB/s of CPU-bound throughput, a 10 GB site needs about 13 minutes of CPU for its
  first backup. With 20-second ticks that is about 40 ticks, plus upload time.
