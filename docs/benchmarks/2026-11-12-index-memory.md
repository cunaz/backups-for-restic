# Benchmark · Compact index after integration with main

Measured on 2026-11-12 while bringing `feat/compact-index` up to `main` for phase 0.9.
This repeats the index measurements of [2026-11-10](2026-11-index-memory.md) for
[ADR 0031](../adr/0031-compact-index-and-memory-budget.md).

```bash
php -d opcache.enable_cli=0 -d memory_limit=-1 bin/bench-index.php 500000 1000000 2000000
```

## Environment and scope

- QEMU Virtual CPU version 2.5+, x86_64, two virtual CPUs.
- PHP 8.3.36, CLI, without OPcache or JIT.
- Other validation processes ran concurrently. Elapsed times describe this run only;
  they are not a speed comparison with the earlier machine.
- The benchmark loads repository indexes and their encrypted cache. The memory columns
  exclude the baseline, including the index files held by the in-memory test backend.
  The script labels the columns MB but divides bytes by 1,048,576; the table uses MiB.

## Results

| Blobs | Index in memory | From index files | First load through cache | Load from cache file |
| --- | --- | --- | --- | --- |
| 500,000 | 32.8 MiB, 68 bytes per blob | peak 42.1 MiB, 23.5 s | peak 42.0 MiB, 27.6 s | peak 36.2 MiB, 3.3 s |
| 1,000,000 | 61.3 MiB, 64 bytes per blob | peak 70.4 MiB, 15.1 s | peak 70.4 MiB, 47.7 s | peak 64.8 MiB, 4.4 s |
| 2,000,000 | 114.6 MiB, 60 bytes per blob | peak 123.9 MiB, 100.0 s | peak 123.9 MiB, 62.7 s | peak 118.3 MiB, 7.4 s |

The command completed successfully and checked the number of blobs after direct loading
and cache loading at each size. Memory use agrees with the earlier measurements; a warm
cache needs about 3 to 4 MiB above the loaded index.

## What this does not measure

This is an index benchmark with the PHP memory limit disabled, not a complete WordPress
backup, prune or check under 128 MiB. The estimate of roughly one million blobs under that
limit depends on the WordPress baseline and the room reserved for backup packs. Unit tests
exercise refusal when an index exceeds its budget and selection of smaller packs, but
full-job measurements at these blob counts remain part of phase 0.9.

Raw index JSON and the entries of one pack exist before their memory can be checked. The
separate sets in prune and check checkpoints also remain outside the compact index work.
