# 0033 · Prune validates the server pack listing on disk before deleting
Status: accepted
Date: 2026-11-12

## Context
Removing packs that no index file lists requires enumerating the server's data directory.
The [restic REST protocol](https://github.com/restic/restic/blob/v0.19.1/doc/REST_backend.rst)
returns one JSON array and has no paging operation. Loading a permitted 128 MiB response,
decoding it, and adding every unindexed ID to the prune checkpoint exceeds shared-host PHP
memory limits. Retrying that same planning step cannot make progress.

## Decision
1. `Backend::list_to_file()` downloads the raw v1/v2 listing into a private working file.
   The HTTP adapter streams directly to disk. The file has mode 0600 before response bytes
   arrive; retries truncate a temporary file and publication uses an atomic rename. The
   existing 128 MiB response bound, TLS checks, no-redirect rule and HTTP retry policy stay.
2. WordPress uses its HTTP API's `stream` and `filename` arguments. An observer on the
   particular Requests hook manager hashes the bytes that its transport intends to retain;
   comparing that digest with the persisted file catches unchecked short writes in
   WordPress' HTTP library. Its socket transport leaves chunk framing in streamed files,
   so the adapter removes that framing with bounded buffers. Local write failures become
   `StorageFailed` (`environment`); malformed or interrupted HTTP responses are transport
   failures. Both WordPress transports are covered by the integration suite in CI.
3. `ListingFile` validates up to 256 entries per step with an 8 KiB read buffer and a
   checkpointed byte offset. Individual entries and uninterrupted whitespace are limited
   to 64 KiB. This comfortably accommodates REST names and sizes while bounding work on
   malicious extra fields. Non-object names are ignored, as in `Backend::list()`.
4. `PackTable` stores names on disk in a fixed-size hash table. Its capacity is a power of
   two derived from the raw listing size, at least twice the maximum possible number of
   valid IDs. Each slot uses 41 bytes: occupancy/classification flag, 32-byte ID, 8-byte size.
   A private random salt prevents chosen-name collision chains; probes have a hard bound.
   Duplicate names update their slot, so the final size wins without a PHP deduplication set.
5. The table starts as a sparse private file. New slot payloads are flushed before their
   occupied flag is committed. Parsing and classification batches may be replayed after a
   killed request. Cursor and counts advance only after successful writes. The complete
   listing, including its closing bracket and trailing bytes, is validated before any
   index rewrite or pack deletion. Classification scans bounded slot batches, and deletion
   advances a slot cursor without copying unindexed IDs into the JSON checkpoint.
6. A durable `download` phase precedes replacement of either working file. If lock-gap
   revalidation starts marking again, the next plan enters that phase before replacing the
   old files. Earlier cursors cannot be resumed against a new table. The existing exclusive
   lock, used-pack checks and snapshot/index revalidation remain deletion prerequisites.
   The prune checkpoint version advances to 3; no released version wrote version 2.

## Consequences
- The new server-listing and unindexed-pack path has bounded PHP memory use. A regression
  plans 200,000 unindexed packs with 12 MiB of additional PHP allowance, resumes every step,
  begins deleting, preserves live packs and keeps its checkpoint below 128 KiB.
- This does **not** bound all prune memory: the index, tree frontier, live/listed sets and
  indexed-unused checkpoint remain part of the full-job memory work in milestone 3 §0.9.
- Disk replaces PHP memory. At the maximum listing size the table has 4,194,304 slots,
  about 164 MiB of logical size, alongside the 128 MiB listing. Atomic replacement and
  socket decoding temporarily need additional space, especially after a restarted plan.
  Sparse-file allocation helps where supported; failed writes must still stop the job.
- The REST response itself must finish within one HTTP request. Parsing, classification
  and deletion then resume in small ticks; protocol paging cannot be emulated safely.
- No SQLite extension, subprocess, external sort or per-pack working file is required.

## Alternatives considered
- Moving the existing whole-list operation to another phase leaves its peak memory intact.
- A PHP set of unindexed names or a JSON checkpoint array merely moves the same growth.
- An append-only deletion file without deduplication could delete a duplicate twice and
  misreport its size. A sorted external merge would work, but adds merge runs and cursors;
  the listing byte cap gives this fixed table a simpler allocation bound.

## As built (2026-11)
Classification also counts the distinct live packs present in the validated table. Only
after its final batch may that count be compared with the number of live packs; a mismatch
stops the run before any index rewrite or deletion. A missing live pack is repository
damage, and an unindexed pack may hold the remaining recovery copy of its data. Repeated
listing entries occupy one slot and cannot mask an absent live name. The count is
checkpointed with the slot cursor, so interrupted batches replay without double counting.
This adds the `present_live` field and advances the checkpoint to version 4; versions 2 and
3 were development revisions, never released. Full-job memory limits remain open as above.
