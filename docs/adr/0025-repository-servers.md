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
    for self-signed TLS, used only for requests to that server. The wizard sends the
    certificate along with the connection test: the test runs on the site's server, which
    could not verify the repository server without it. A test uses it from a file of
    its own in the private directory, removed after the test, and stores nothing; it becomes the server's certificate when the server is saved, under the same lock and put back as it was if the save fails, together with the settings, the REST password and the cached key that the save changed, as far as the database takes these writes; an address that `wp-config.php` defines does not get the certificate of another address that was tested,
    so a test that fails never leaves the saved server with a certificate that is not its
    own. Saving a server of the preset removes a stored certificate.
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
  documentation describes the BorgBase preset and records separate evidence for hosted runs. `readme.txt` discloses it
  under "External services", as WordPress.org requires, and names self-hosted servers as the
  alternative. The name implies no affiliation (ADR 0015).
- Run a weekly and manually dispatched smoke test on disposable private TLS rest-server
  fixtures. For pre-release provider verification, dispatch the same workflow on the
  candidate commit using dedicated operator-provisioned normal and append-only
  repositories. Record the provider, mode, commit and successful run.

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

The local private-repository smoke run also verified byte-for-byte restore, REST v2
pack sizes and ranges, lock creation/removal, exact cleanup of normal fixture objects
and append-only snapshot retention across two runs.

On 2026-11-20 the hosted smoke ran against dedicated normal and append-only BorgBase
repositories with public TLS verification. In both modes the plugin verified the
repository ID, backed up and restored a random 32 KiB file, and checked the REST v2
pack size and ranged bytes. The normal repository returned to its original inventory
after this smoke test. The append-only repository refused deletion and retained both
smoke snapshots. A subsequent WordPress 7.1.5 run wrote 2 GiB of uploads and 300 MiB of
SQL values to the normal repository. Independent restic 0.20.0 reads verified the full
WordPress backup and the two append-only snapshots. The repositories held one and two
snapshots respectively and both released all locks.
The HTTP status table above records the separate local rest-server tests.
[Release acceptance](../benchmarks/2026-11-20-release-candidate.md).

## Consequences
- A larger audience (self-hosters) with modest extra support, contained by documentation
  for rest-server setups.
- A configured hosted job can detect changes in its provider. The generic code path
  remains available for any compliant server.
- New threats: administrator-configured URLs (SSRF) and uploaded CA certificates, handled
  in the threat model (T-26, T-27).
- Server incompatibilities surface in the connection test, before any data is written.

## Alternatives considered
- **BorgBase only, or self-hosted servers only through a constant**: declined by the owner.
- **Asking BorgBase to confirm behaviour**: declined by the owner; replaced by live tests.
- **Plain HTTP on private networks**: credentials and metadata in clear text; rejected.

## As built (2026-11)
- The private smoke workflow has a self-contained local fixture and an opt-in hosted job.
  It opens existing hosted repositories by authenticated ID, checks the original inventory,
  and deletes only its own writes on a normal fixture. Append-only fixtures retain their
  recorded smoke history. [Smoke runner and operator setup](../../tests/Live/README.md)
- "TLS verification is never disabled" is enforced against the site's own code since
  2026-11-11. Snippets that switch verification off for every request of a site are common
  (a filter on `https_ssl_verify` or on the request arguments, a line in the `http_api_curl`
  action). While a repository request is under way, `WordPress\WpHttpClient` registers its
  own callbacks last on these hooks, scoped to the request's URL, and puts verification of
  certificate and host name, the certificate bundle and the ban on redirects back. The
  alternative, a client of our own beside WordPress' HTTP API, was rejected: it would lose
  the proxy settings and transports a host has configured there. The limit is stated in
  the class: it holds against careless code, not against code that means harm, which
  could as well read the passwords. An integration test runs with such a snippet active.
