# 0031 · A compact index and a memory budget
Status: accepted
Date: 2026-11-10

## Context
Every job holds the index of the whole repository in memory: backups to find what is
stored already, prune and check to find the pack of every tree. The index was a PHP array
with one element per blob. The documents put that at about 100 bytes per blob and derived
that 640 000 blobs fit into the 64 MB of NFR-03; nothing had been measured, and no test
used more than a few thousand blobs.

Measured on PHP 8.3 ([benchmark](../benchmarks/2026-11-index-memory.md)):

- The array cost 157 bytes per blob: 75 MB for 500 000 blobs, 150 MB for a million.
- Reading the index cache needed far more for a moment: the sealed file, its plaintext, a
  copy without the header and the array, about 316 bytes per blob. Under a limit of 128 MB
  the plugin stopped working at roughly 300 000 blobs.
- One index file of 50 000 blobs, the size at which restic starts a new one, is 6 MB of
  JSON and took 46 MB to decode.
- A backup needs room besides the index: about 20 MiB for the file being read, and three
  times the size of a pack, which exists as its blobs, as the finished pack and as the
  request that uploads it. With packs of 16 MiB that is 55 MB.

A site with 150 000 files and some history has more than 300 000 blobs. When PHP's memory
limit is reached, the request ends at once: no checkpoint, no message, and the next tick
ends the same way.

## Decision
1. **The index is kept as fixed-size records in buckets** (`Format\Index\IndexSet`). A
   record is the 32 bytes of the blob ID and four unsigned 32-bit numbers: pack number,
   offset, length and uncompressed length. Records are concatenated in one string per
   bucket; there are 65 536 buckets for data blobs and as many for tree blobs. Two bytes of
   the ID choose the bucket, `strpos()` finds the ID in it, and a match counts only where a
   record starts. This costs 60 to 80 bytes per blob and is as fast as the array.
2. **Which two bytes choose the bucket is random for every index** and stored with its
   cached form. Someone who can place files on the site can choose a few bits of their blob
   IDs by trial. With fixed bytes they could put thousands of blobs into one bucket and make
   every lookup and addition slow; with unknown positions they would have to fix whole IDs.
3. **Offsets and lengths are 32-bit.** restic's largest pack is 4 GiB. An index entry beyond
   that is refused as a damaged index instead of stored.
4. **The cache file is a row of sealed pieces** of about one megabyte
   (`Engine\IndexCache`), each with its sealed length in front. The first holds the names
   of the covered index files, the others the index as `IndexSet::export()` gives it: a
   head with the numbers of packs and blobs, the pack IDs, then whole buckets. Reading and
   writing need one piece besides the index. Every piece is sealed with the repository key
   like before, and its plaintext starts with a random mark, the same in all pieces of one
   file, and the number of the piece. A file whose pieces were dropped, repeated, reordered
   or mixed with another file's, or whose totals do not match, is thrown away and the index
   read from the repository again. The covered index names are compared with the current
   listing before any cached entries are imported. If a file disappeared, the stale
   entries cannot consume the budget of the smaller index that replaced them.
   A replacement that exceeds the disk cap leaves an older in-limit cache intact, so the
   next load can reuse its valid coverage and download only newer index files. A cache
   already larger than the configured cap is removed before loading.
5. **Index files are decoded pack by pack** (`IndexFile::packs()`). The text is cut into
   the elements of its `packs` list by looking only at quotes, backslashes and brackets.
   What is left, with a placeholder for each element, and each element by itself go through
   the same strict JSON decoder as every other repository file, so nothing is accepted that
   the decoder would refuse in the whole document. The fuzzer compares both ways of
   decoding on every input.
6. **A job has a memory budget** (`Engine\MemoryBudget`, from PHP's `memory_limit` as it is
   during the tick). The index stops loading with the error `index_too_large` once it
   leaves less than 20 MiB and three packs of 4 MiB, the smallest pack size. A backup makes
   its packs smaller than configured when three of them do not fit beside the index, down
   to 4 MiB, and says so in the job's log. Before appending another blob, the writer checks
   the resulting pack size including encryption and the header entry; a nonempty pack is
   uploaded first when the addition would reach the target. One indivisible blob can be
   larger than the target and is uploaded alone.

   The backup preflight uses the same limit parser as the budget. A check propagates a
   memory refusal instead of treating it as index damage and trying again without the
   cache. Its fallback for genuinely damaged index files enforces the budget too, both
   after adding each pack and after the readable index has loaded.

   PHP holds against its limit the memory it has reserved from the system, not the memory
   in use: it reserves blocks of 2 MiB and keeps a block as long as anything in it is in
   use. The records of an index are small and fit into the free parts of such blocks; a
   pack of several megabytes needs memory of its own. So the budget reads the memory in
   use for every pack while an index loads, which costs nothing and stops an index that is
   far too large early. Once the index has loaded, and when it chooses the pack size, it
   reads the reserved memory, after PHP has given back the blocks that are free altogether.
   In a process that has run for a while the two figures differ by tens of megabytes: the
   unit suite showed 24 MiB in use and 36 to 62 MiB reserved.

## Consequences
- Measured after the change: 33 MB for 500 000 blobs, 61 MB for a million, 115 MB for two
  million; loading from the cache peaks 4 MB above that and takes 0.5, 0.9 and 1.7 seconds.
  The [repeat measurement](../benchmarks/2026-11-12-index-memory.md) agrees on memory use.
  With a WordPress baseline of 30 to 40 MiB, the estimated capacity under 128 MiB is roughly
  a million blobs with small packs. Full-job measurements at that count remain open.
- An accumulated index beyond the budget ends the job with a message an administrator can
  act on. Raw index JSON and the entries of one pack are still decoded before the budget
  is checked, so a large individual object can exhaust memory before that check.
- The cache file has a new layout. A cache of the old layout is not recognised and is built
  again, once.
- NFR-03 is worded with these numbers. They hold for backups. Prune and check still decode
  an index file as a whole when they examine or rewrite it, and the sets of tree and pack
  IDs in their checkpoints cost about 150 bytes per ID; both follow as their own change
  (plan package 0.9, second part).
- Pure-PHP sealing of the cache costs about a second per million blobs in every tick. A
  faster cipher for local files is possible later; it would add a second construction to
  review.

## Alternatives considered
- **A sorted file on disk with a sparse table in memory.** Memory would no longer grow
  with the repository, but every lookup would read and authenticate a block from disk,
  tens of seconds of pure-PHP MAC per backup, or the cache would have to stay unencrypted.
- **Shorter records with truncated IDs.** A truncated ID that matches by chance or by
  design makes the backup skip a blob that is not stored.
- **A keyed hash for the bucket.** PHP's fast hashes are not designed against chosen
  inputs, and the one that is, SipHash, needs the sodium extension, which the engine does
  not require ([ADR 0027](0027-poly1305-in-pure-php.md)).
- **Only a preflight estimate.** It would say clearly that a site is too large; it would
  not make the plugin work for it.
