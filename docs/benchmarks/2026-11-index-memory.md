# Benchmark · Memory of the index

Measured on 2026-11-10 with `bin/bench-index.php` for
[ADR 0031](../adr/0031-compact-index-and-memory-budget.md). Repeat it after changes to
`Format\Index` or `Engine\IndexCache` and add a dated file next to this one.

```bash
php -d opcache.enable_cli=0 -d memory_limit=-1 bin/bench-index.php
```

The script stores index files of 50 000 blobs, as restic writes them, in a repository in
memory and loads the index three ways. The peaks are above the memory in use before the
load; the index files themselves are not counted, because on a host they are on the
server. A coverage extension slows every number down; switch it off.

## Machine

| Item | Value |
| --- | --- |
| CPU | Intel Xeon @ 2.10 GHz, 4 vCPUs (the engine uses one) |
| PHP | 8.3.6 (Ubuntu build), CLI, 64-bit, without OPcache and JIT |

## Before: one array element per blob

Measured with the code before the change (scripts of the same kind, not kept).

| Blobs | Index in memory | Reading the cache, peak |
| --- | --- | --- |
| 250 000 | 37.6 MB (157 bytes per blob) | about 80 MB |
| 500 000 | 75.1 MB | about 160 MB |
| 1 000 000 | 150.1 MB | about 320 MB |
| 2 000 000 | 300.2 MB | about 640 MB |

The cache was read as one sealed string, opened into a second one, copied without its
header into a third and then turned into the array: 53 bytes per blob three times, besides
the array. One index file of 50 000 blobs (5.9 MB of JSON) took 46 MB to decode, 32 MB of
that for the JSON decoder alone.

## After: records in buckets, a cache in pieces

| Blobs | Index in memory | From the index files | First load through the cache | Load from the cache file |
| --- | --- | --- | --- | --- |
| 250 000 | 19.2 MB (80 bytes per blob) | peak 27.8 MB, 1.7 s | peak 27.6 MB, 1.9 s | peak 22.7 MB, 0.2 s |
| 500 000 | 32.8 MB (68) | peak 42.0 MB, 3.6 s | peak 41.9 MB, 3.9 s | peak 36.3 MB, 0.5 s |
| 1 000 000 | 61.3 MB (64) | peak 70.4 MB, 7.1 s | peak 70.4 MB, 7.7 s | peak 64.8 MB, 0.9 s |
| 2 000 000 | 114.6 MB (60) | peak 123.9 MB, 14.5 s | peak 123.9 MB, 16.4 s | peak 118.3 MB, 1.7 s |

A record is 48 bytes; the rest is the cost of 131 072 bucket strings, which weighs less the
fuller they are. A job loads the index from the cache file in every tick, so the last
column is what a tick pays.

## What a backup needs besides the index

Measured with files of 40 MB of random bytes and 3000 small files, packs counted but not
kept, one copy of every pack made as an HTTP client does.

| Pack size | Peak above the start |
| --- | --- |
| 4 MiB | 25.1 MB |
| 8 MiB | 42.8 MB |
| 16 MiB (default) | 55.2 MB |
| 32 MiB | 61.6 MB |

`Engine\MemoryBudget` reckons with 20 MiB and three times the pack size.

## What follows for a host with 128 MB

WordPress and the plugin's own code take 30 to 40 MB in a tick. With the default pack size
about 35 MB are left for the index, enough for half a million blobs. Beyond that the
backup makes its packs smaller; with packs of 4 MiB the index may take about 60 MB, a
million blobs. A larger index ends the job on its first tick with the error
`index_too_large`.
