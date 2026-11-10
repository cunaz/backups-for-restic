# Appendix A · Optional restic-binary engine (future option)

Status: design preserved for a possible post-1.0 feature. Not part of 1.0
([ADR 0018](adr/0018-native-php-engine.md)). Governed by [ADR 0016](adr/0016-no-binary-provisioning.md)
(the plugin never downloads or bundles executables) and [ADR 0017](adr/0017-distribution-via-wordpress-org.md).

## Why it might come back

On a VPS with `proc_open` and a system-installed restic, the binary is faster than PHP
(chunking in Go, parallel uploads), supports compression (repository format 2 with zstd)
and full `prune` with repacking. The native engine's seams (`Backend\Backend`,
`Engine\BlobStore`, `Jobs\Step`) allow a second engine implementation that delegates the
heavy operations to the binary while the job model, settings, secrets, UI and database
streaming stay the same. A repository created by the native engine (format version 1) is
usable by the binary without migration; the reverse requires that the binary keeps the
repository at format version 1, because in format 2 restic compresses index and snapshot
files even with `--compression off`, or that `ext-zstd` is available to PHP.

## Process model (summary of the former design)

- One `proc_open` call site; commands are argument arrays with an allow-listed environment
  (`RESTIC_REPOSITORY` without credentials, `RESTIC_REST_USERNAME`, `RESTIC_REST_PASSWORD`,
  `RESTIC_PASSWORD`, `RESTIC_CACHE_DIR`, `TMPDIR`, `PATH`, `HOME`, `LANG`). Secrets never
  appear in `argv`.
- Web contexts start restic **detached** through a constant POSIX `sh` script whose variable
  parts are positional parameters only:

  ```
  /bin/sh -c 'out=$1 err=$2 status=$3 pidfile=$4; shift 4
    ( "$@" >"$out" 2>"$err" </dev/null & echo $! >"$pidfile"; wait $!; echo $? >"$status" ) </dev/null >/dev/null 2>&1 &' \
    backups-for-restic-launch <stdout> <stderr> <status> <pid> <restic> <args…>
  ```

  The outer shell returns at once; the status file appears when restic exits. Liveness is
  checked via `/proc/<pid>/stat` with a start-time fingerprint; timeouts send SIGTERM (restic
  ≥ 0.17 releases its lock) then SIGKILL. WP-CLI runs restic attached with pipes.
- Always `--json`; exit codes 0/1/2/3/10/11/12/130 mapped to the job error taxonomy;
  `--retry-lock`, `--http-user-agent`, `-o rest.connections`, `--pack-size`, `--compression`
  configurable; never `--insecure-tls`, `--insecure-no-password`, `self-update`,
  `forget --unsafe-allow-remove-all`.
- Binary discovery: constant → setting → well-known directories; validation of ownership,
  mode and `version --json` ≥ 0.18.0.

## What would change in the product

- Settings: an `engine` selector (`native` | `binary`), the binary path, and the tuning
  options above.
- Doctor: binary checks and the install guide (text only).
- Threat model: the process-execution threats of the earlier design (argument injection,
  process listing, binary provenance) return and are mitigated as summarised above.
- Documentation: a "Turbo mode" page for VPS operators.
