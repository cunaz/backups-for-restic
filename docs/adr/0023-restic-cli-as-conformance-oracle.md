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
