# 0025 · Repository servers: BorgBase and self-hosted restic REST servers, no vendor affiliation
Status: accepted (owner decisions 2026-11-09)
Date: 2026-11-09

## Context
The owner decided that self-hosted restic REST servers are officially supported in the
wizard next to BorgBase, and that the plugin is never presented together with BorgBase: it
simply works with it, and the vendor is not contacted. BorgBase's public documentation
describes its restic endpoint as nginx in front of restic's rest-server with
per-repository HTTP credentials and an append-only option. The conformance and integration
suites already run against rest-server.

## Decision
- **Wizard step "Repository server"** with two options:
  - **BorgBase**: paste the repository URL from the BorgBase control panel; the host must
    match `*.repo.borgbase.com`; enter the REST username and password. Help texts point to
    the relevant control-panel settings.
  - **Own restic REST server**: any `https://` URL with host, port and path (for example
    rest-server with `--private-repos`); username and password optional; an optional CA
    certificate (PEM, validated with `openssl_x509_parse`, stored in the private directory)
    for self-signed TLS, used only for requests to that server.
- **Weighting**: BorgBase is a convenience preset that is simply selectable, not a headline
  feature. If the preset does not fit, the user pastes any repository URL into the generic
  option. Positioning leads with restic-compatible, encrypted backups in pure PHP on shared
  hosting.
- **HTTPS only** for both options. Plain HTTP is refused even on private networks
  (rest-server offers `--tls`). TLS verification is never disabled.
- **One code path**: both options use the same backend. Profiles differ only in URL
  validation, CA handling and help texts. The wizard's connection test checks
  authentication, Range reads, listing (v2 with v1 fallback, as restic does) and append-only
  (ADR 0010) before the first backup.
- **No vendor relationship**: no contact, partnership, listing or co-marketing. The
  documentation mentions BorgBase factually as a tested provider. `readme.txt` discloses it
  under "External services", as WordPress.org requires, and names self-hosted servers as the
  alternative. The name implies no affiliation (ADR 0015).
- **Empirical verification instead of vendor statements**: Milestone 0 and a weekly and
  pre-release live smoke test (`live-servers.yml`) use two BorgBase free-tier repositories
  (normal and append-only) and a TLS rest-server with a self-signed CA and
  `--private-repos`. Observed BorgBase behaviour (status codes, limits, append-only details)
  is recorded in this ADR as it is measured.

## Observed behaviour
rest-server 0.14.0. Every row is asserted against a running server by the conformance suite
(`test_rest_server_answers_map_to_typed_exceptions` and `test_append_only_server`), and the
plugin maps every row to a typed exception.

| Situation | rest-server 0.14.0 | Plugin |
| --- | --- | --- |
| Wrong or missing credentials | 401 | `Unauthorized` |
| Missing object, GET or HEAD | 404 | `NotFound`, or no size |
| Ranged GET | 206 with the requested bytes | the bytes |
| Listing with `Accept: application/vnd.x.restic.rest.v2` | JSON list of name and size | sizes by name |
| DELETE in append-only mode | 403 for every type except locks, checked before the file exists | `AppendOnlyRefused` |
| DELETE of a missing object | 200, deletes are idempotent | success |
| Upload of an object that already exists | 403 | success if the sizes match, as after a lost response |
| Upload of a config that already exists | 403 | `AlreadyExists` |
| Upload whose SHA-256 does not match its name | 400 | `UploadRejected` |
| Upload beyond `--max-size`, or disk full | 507 | `QuotaExceeded`, not retried |

Because the append-only check comes first, deleting a random, non-existent snapshot name is
a safe probe: 403 means append-only, 200 means deletions are allowed.

**BorgBase: not yet measured.** The live checks need two free-tier repositories (normal and
append-only) and their credentials from the owner. Until then the BorgBase preset relies on
BorgBase's documented use of rest-server behind nginx.

## Consequences
- A larger audience (self-hosters) with modest extra support, contained by documentation
  for rest-server setups.
- BorgBase changes are detected by our tests, not announced to us; the generic code path
  keeps the plugin usable with any compliant server.
- New threats: administrator-configured URLs (SSRF) and uploaded CA certificates, handled
  in the threat model (T-26, T-27).
- Server incompatibilities surface in the connection test, before any data is written.

## Alternatives considered
- **BorgBase only, or self-hosted servers only through a constant**: declined by the owner.
- **Asking BorgBase to confirm behaviour**: declined by the owner; replaced by live tests.
- **Plain HTTP on private networks**: credentials and metadata in clear text; rejected.

## As built (2026-11)
- No live check exists. There is no workflow `live-servers.yml`, Milestone 0 measured
  rest-server only, and no test has used a hosted repository. Every statement about
  BorgBase's answers is therefore "not yet measured", in this record and in the other
  documents, and BorgBase is a preset, not a tested provider. The measurements need an
  account at BorgBase and are planned for Milestone 3.
- Until they exist, no test notices a change in BorgBase's behaviour. The connection test
  of the wizard is the only check a user's repository gets.
