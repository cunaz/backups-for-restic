# 0001 · Talk to the repository server through restic's REST protocol over HTTPS
Status: accepted (amended by 0018: implemented in PHP; by 0025: BorgBase and self-hosted servers)
Date: 2026-11-09

## Context
restic's REST protocol is served by restic's own `rest-server` and by BorgBase, whose public
documentation describes its restic endpoint as nginx in front of rest-server ("Borg uses
SSH, while Restic uses HTTP"). Append-only mode is enforced by the server: deletions are
answered with HTTP 403 except for lock files. BorgBase offers SFTP only for importing and
exporting raw repositories.

## Decision
Use the restic REST protocol exclusively, implemented in PHP on the WordPress HTTP API
(ADR 0018), for BorgBase and self-hosted rest-server alike (ADR 0025): `POST ?create=true`,
`HEAD`/`GET`/`POST`/`DELETE` on `/{config,data,index,keys,locks,snapshots}/{name}`, listing
with `Accept: application/vnd.x.restic.rest.v2` (v1 fallback as restic does), `Range` reads.
Credentials travel in the `Authorization` header, never in the URL. Plain `http://` is
rejected; TLS is verified against WordPress' CA bundle or an uploaded CA certificate for a
self-hosted server; redirects are disabled.

## Consequences
- No external program at all: no `ssh`, no keys, no `known_hosts`, no `restic` binary.
- Credentials never appear in URLs, error messages or logs.
- Append-only is enforced server-side and detected by the plugin (ADR 0010).

## Alternatives considered
- **SFTP**: needs an SSH client and keys; BorgBase does not offer it for restic.
- **rclone, S3 and other backends**: additional programs or protocols; out of scope.
- **BorgBackup**: SSH-only transport, not requested.
