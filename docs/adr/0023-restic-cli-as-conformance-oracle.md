# 0023 · The restic CLI is the conformance oracle in CI
Status: accepted
Date: 2026-11-09

## Context
Reimplementing a repository format carries one dominant risk: writing something that looks
right but that the reference implementation rejects or restores differently. The reference
implementation is freely available and scriptable, and it has `cat` subcommands that expose
every internal object.

## Decision
- CI installs restic (checksum-verified upstream release; development tooling only) and a
  local `rest-server`, and runs the **conformance suite** on every change to `Crypto`,
  `Format`, `Backend`, `Engine` and nightly:
  1. PHP creates a repository and backs up fixture trees (ASCII and Unicode names, empty
     files, symlinks, large files, a 300 MB database fixture); `restic check --read-data`
     must pass; `restic restore` must reproduce the fixtures byte for byte; `restic
     snapshots --json`, `ls --json` and `cat` outputs must match the PHP view.
  2. restic creates repositories (format 1; format 2 without compression) and backs up the
     same fixtures; PHP must list, read and restore them identically and pass its own check.
  3. Chunk boundary parity: blob ids of files backed up by restic equal those produced by the
     PHP chunker.
  4. Forget parity: `restic forget --dry-run --json` equals the PHP policy result on a
     generated snapshot calendar.
  5. Append-only run: forget and prune against `rest-server --append-only` yield
     `repository_append_only`; locks are still released.
- Vectors for unit tests are recorded with `restic cat key|masterkey|config|pack|blob|tree|snapshot|index|lock`
  and stored under `tests/Fixtures/restic/<version>/` with the producing commands.
- Matrix: restic 0.18.x and the latest release; a stored format-1 repository from restic 0.14
  for reading tests. A conformance failure blocks releases.

## Consequences
- The plugin's compatibility claim is verified continuously instead of asserted.
- CI needs Docker and a few minutes more per run; developers can run the suite locally with
  `composer test:conformance`.
- New restic releases are picked up by a scheduled job that bumps the matrix.

## Alternatives considered
- **Unit tests only**: cannot detect misreadings of the format specification.
- **A Go test harness**: more machinery; `restic cat` already exposes what is needed.

## As built (2026-11)
The suite in `tests/Conformance/` differs from the list in the decision:

- Step 1 compares `restic snapshots --json` (host name, tags, paths) and reads
  `restic diff` after an incremental backup. No test compares `ls --json` or `cat` output.
- Step 2: restic 0.19.1 and 0.14.0 each create a format 1 repository during the test; no
  repository of restic 0.14 is stored. The plugin restores from it and deduplicates against
  it, and restic checks the result. The plugin's own check runs only on repositories the
  plugin wrote. Format 2 is tested with restic's default compression, in the CI job that
  installs `ext-zstd`; a format 2 repository without compressed files does not occur
  ([ADR 0019](0019-repository-format-and-chunker.md)).
- Steps 3 and 4 run in the unit suite against recorded vectors: a chunker vector from
  restic's chunker library and eleven recorded results of `restic forget --dry-run --json`.
  The conformance suite tests chunk parity with the CLI indirectly: a plugin backup of files
  that restic has backed up adds no data blob.
- Step 5: the probe, the release of locks and a refused forget are tested against
  `rest-server --append-only`; the refusal of the prune run only against the in-memory
  backend. Forget and prune jobs on that server end as `skipped: append_only`, not with the
  error `repository_append_only`.
- Vectors: `bin/record-vectors.sh` records master key, config, snapshot, index, the root
  tree and a raw copy of the repository into `tests/Fixtures/restic-<version>/`; one
  version exists, 0.19.1. `bin/record-forget-vectors.sh` records the forget results.
- CI needs no Docker. `bin/install-test-tools.sh` downloads the pinned, checksum-verified
  programs: restic 0.19.1, 0.18.1 and 0.14.0 and rest-server 0.14.0.
- No scheduled job bumps the matrix. A new restic release enters it through a pull request
  that changes the pins; picking releases up automatically is planned for Milestone 3.
- The suite runs on every change under `src/` and `tests/`, not only in the four
  namespaces, and it holds tests the list does not name: prune and check compared with
  restic, whole jobs without WordPress, and the rest-server answers of
  [ADR 0025](0025-repository-servers.md).
