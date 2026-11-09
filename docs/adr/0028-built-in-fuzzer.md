# 0028 · A built-in, structure-aware fuzzer instead of php-fuzzer
Status: accepted (revises the fuzzing tool in 0024)
Date: 2026-11-09

## Context
ADR 0024 asks for fuzzing of every parser that reads server data and named
`nikic/php-fuzzer` as the tool. Most of those parsers take JSON: config, key files,
snapshots, locks, index files, trees, the server's listing. For them, `json_decode()` sits in
front of the code that matters. Byte-level mutations, even coverage-guided ones, mostly
produce invalid JSON and stop there; the shape validation behind `json_decode()` sees few of
them. php-fuzzer would also be another development dependency to review and pin.

## Decision
- `bin/fuzz.php` with `tests/Fuzz/` is a small mutation fuzzer without dependencies. Every
  target is one parser with seed inputs recorded from restic.
- Mutations are byte-level (bit flips, interesting bytes and tokens, insertions, deletions,
  duplications, truncation, splicing, number replacement) and, for JSON targets,
  structure-aware: replace any value with another type or an edge value, remove keys, add
  unknown keys, duplicate list elements, reverse strings, negate numbers.
- A finding is any outcome other than acceptance or a `PluginException`: a `TypeError`, a
  `ValueError`, a PHP warning (turned into an exception) or an input that takes longer than a
  second. Instead of coverage feedback, every input that causes a not yet seen behaviour
  (acceptance, or a rejection with a new message) joins the corpus.
- The unit suite runs every target for 2000 deterministic inputs and replays each past
  finding from `tests/Fixtures/fuzz/<target>/`. CI fuzzes every target for two minutes each
  night with the run id as seed.

## Consequences
- No new development dependency; the fuzzer runs wherever the unit tests run.
- Not coverage-guided: deep binary formats would be found more slowly than with
  php-fuzzer. The only binary parsers are the pack header and the encryption envelope, both
  short, fixed-layout and length-checked.
- Already paid off in Milestone 0: it reproduces a type confusion in the server listing
  (an object name like `12` became an integer array key) within 2000 inputs.

## Alternatives considered
- **`nikic/php-fuzzer`**: coverage-guided, but byte-level only and an extra dependency.
- **Property tests only**: they check stated properties; fuzzing finds the unstated ones.
