# 04 · Development, tooling and quality

How the repository is organised, which tools guard it, how tests are layered, and how a
release reaches WordPress.org. A contributor should clone, run `composer install`,
`composer check` and get a green result in minutes. The conformance suite needs the restic
CLI and rest-server, which `bin/install-test-tools.sh` downloads and verifies; no Docker.

## 1. Repository layout

```
backups-for-restic/
├── backups-for-restic.php   plugin header, constants, autoloader include, Plugin::boot()
├── uninstall.php
├── readme.txt                       (M1) WordPress.org readme: the listing's source of truth (description, installation, FAQ, external services, changelog)
├── README.md, CHANGELOG.md, LICENSE, SECURITY.md, CONTRIBUTING.md
├── composer.json / composer.lock    dev tooling only; PSR-4 for tests
├── phpcs.xml.dist, phpstan.neon.dist, phpunit.xml.dist, phpunit-integration.xml.dist, infection.json5.dist, .editorconfig, .gitattributes, .distignore
├── .wordpress-org/                  (M1) listing assets synced to SVN `assets/`: banners, icon, screenshots
├── src/                             production code (see 02-architecture §3); src/WordPress/ is the only WordPress-facing part
│   └── autoload.php                 ~20-line PSR-4 autoloader for the BackupsForRestic\ namespace
├── assets/js/, assets/css/          admin.js (classic script on wp-api-fetch, no bundler) and a scoped stylesheet
├── languages/                       (M1) backups-for-restic.pot only; language packs come from translate.wordpress.org
├── tests/
│   ├── Unit/                        PHPUnit, no WordPress, no database, no network
│   ├── Conformance/                 PHPUnit + restic CLI + rest-server: format parity, server behaviour
│   ├── Fuzz/                        built-in fuzzer and its targets, one per parser (ADR 0028)
│   ├── Support/                     test doubles, the restic and rest-server harness, fixture generators
│   ├── PHPStan/                     PHPStan extensions for the analysis of src/
│   ├── Database/                    the dump against a real MySQL or MariaDB, imported with the mariadb client
│   ├── Integration/                 a real WordPress from bin/test-site.sh: wizard over REST, backups through wp-cron.php, a killed tick
│   ├── E2E/                         (M2) Playwright against wp-env
│   └── Fixtures/                    restic vectors per version, Go and libsodium vectors, fuzz regressions
├── bin/                             install-test-tools.sh, test-site.sh, record-vectors.sh, record-forget-vectors.sh, bench.php, fuzz.php; (release) build-package.sh
├── docs/                            design documents, ADRs, benchmarks/
└── .github/                         workflows ci.yml, conformance.yml, integration.yml, fuzz.yml; dependabot.yml; later live-servers.yml, e2e.yml, deploy-wordpress-org.yml, codeql.yml
```

Entries marked (M1) or (M2) arrive with that milestone ([roadmap](05-roadmap.md)).

## 2. Toolchain (development only)

| Tool | Purpose |
| --- | --- |
| PHP 8.3–8.5, 8.6 once released (CI matrix; 8.3 without JIT is the performance baseline) | runtime floor 8.3 ([ADR 0003](adr/0003-version-floors.md)) |
| Composer 2 | dev dependencies and scripts; no `vendor/` in the release |
| `wp-coding-standards/wpcs` 3, PHP_CodeSniffer | WordPress Coding Standards |
| `phpstan/phpstan` 2 + `szepeviktor/phpstan-wordpress` + strict rules | static analysis at level `max`, architecture rules, `reveal()` allow-list |
| `phpunit/phpunit` | unit, conformance and integration tests |
| Infection 0.35 (PHAR, pinned and checksum-verified by `bin/install-test-tools.sh`) with pcov | mutation testing of `Crypto`, `Format` and `Engine\Forget`; merge gate ([ADR 0024](adr/0024-governance-and-quality-assurance.md)) |
| Built-in fuzzer (`bin/fuzz.php`, `tests/Fuzz/`) | structure-aware fuzzing of every parser that reads server data ([ADR 0028](adr/0028-built-in-fuzzer.md)) |
| **restic CLI** 0.19.1, 0.18.1 and 0.14.0 (upstream releases, checksum-verified by `bin/install-test-tools.sh`) | conformance oracle ([ADR 0023](adr/0023-restic-cli-as-conformance-oracle.md)); never shipped |
| **rest-server** 0.14.0 (upstream release, checksum-verified), with TLS, normal, `--append-only` and `--max-size` | local repository server for conformance and integration tests |
| (M1) `yoast/phpunit-polyfills`, `wp-phpunit/wp-phpunit`, `brain/monkey` | WordPress test library and function mocking |
| (M1) `@wordpress/env`, (M2) `@playwright/test` | Docker WordPress, browser tests |
| (M1) Plugin Check (`wp plugin check`) 2.x | the WordPress.org review team's scanner; required check |
| (release) `10up/action-wordpress-plugin-deploy` (pinned SHA) | SVN deployment of tagged releases |

Composer scripts: `lint`, `fix`, `analyse`, `test:unit`, `test:database`,
`test:conformance`, `test:integration`, `test` (unit, database and conformance), `check`
(lint + analyse + unit), `mutation`, `fuzz`, `bench`. pcov slows
every PHP process it is loaded into, roughly threefold for the unit suite, so enable it only
for coverage and mutation runs.

## 3. Coding standards

- **WordPress Coding Standards** for everything WordPress-facing, with documented
  deviations in `phpcs.xml.dist`: PSR-4 file and class naming under `src/`, short arrays,
  `declare(strict_types=1)`, native filesystem functions inside the private directory (each
  call site commented).
- **Tab indentation** as WordPress prescribes (`.editorconfig` enforces it); line length 100.
- **Naming** as WordPress prescribes: `snake_case` for functions, methods, variables and
  properties, `PascalCase` for classes and enums, `UPPER_CASE` for constants. Parameter names
  avoid reserved words (`$parent_id`, not `$parent`).
- **Banned functions** (custom sniff): `exec`, `shell_exec`, `system`, `passthru`, `popen`,
  `proc_open`, `eval`, `unserialize`, `extract`, `create_function` in production code.
  Base64 for restic's key files goes through `Support\Bytes`, which decodes strictly.
- **Prefixes**: everything global uses `backups_for_restic_` / `backups-for-restic`; classes live in
  `BackupsForRestic\`; `PrefixAllGlobals` configured accordingly.
- **Cryptography and format code**: `Crypto` and `Format` are `final`, dependency-free,
  documented with the byte layouts they implement and a pointer to the restic source they
  mirror. Changes follow the solo-maintainer regime of
  [ADR 0024](adr/0024-governance-and-quality-assurance.md): self-review checklist, 24-hour
  cooling-off before merge, green conformance run and mutation gate.
- `final` by default, immutable value objects, enums for closed sets, one exception base
  class, no static mutable state, docblocks explain *why*.
- JavaScript: ES2020 modules, ESLint with `@wordpress/eslint-plugin`, no frameworks.

## 4. Static analysis configuration

PHPStan level `max`, strict rules, WordPress stubs. Architecture rules: WordPress-free
namespaces may not reference WordPress functions; `Engine` may not depend on `Jobs` or
adapters; `Admin`/`Rest`/`Cli` may not reference each other; `reveal()` only in the allow-list.
No baseline on `main`.

## 5. Tests as specification

### 5.1 Unit tests (fast, pure, no WordPress)

| Area | What is asserted |
| --- | --- |
| `Crypto\Cipher` | seal/open round trip; vectors: objects produced by `restic cat` open correctly; tampered tag, truncated input and wrong key fail closed; nonces unique across a batch |
| `Crypto\Scrypt` | RFC 7914 vectors; restic key files from the fixture matrix open; checkpoint/resume yields the same key as a single pass; file-backed table deleted afterwards |
| `Format\KeyFile`, `Config`, `Snapshot`, `Lock` | parse and emit restic fixtures; unknown fields preserved where restic preserves them; size caps enforced |
| `Format\Chunker` | boundaries equal restic's on random and structured fixtures (ids from `restic cat snapshot`/`tree`); polynomial generation produces irreducible degree-53 polynomials; table computation matches restic's |
| `Format\Pack` | writer/reader round trip; header layout byte-exact against `restic cat pack`; data and tree blobs never mixed; header size limit |
| `Format\Index` | JSON ⇄ entries; 50 000-blob limit; merge and lookup; cache encryption round trip |
| `Format\Tree` | ordering and uniqueness enforced; Go quoting of names (control characters, quotes, backslashes, invalid UTF-8); mode bit mapping for files, dirs, symlinks, setuid/setgid/sticky; byte parity with restic trees for ASCII fixtures |
| `Engine\Forget` | parity with recorded `restic forget --dry-run --json` results over generated calendars for every keep flag |
| `Engine\Prune`, `Engine\Check` | against `InMemoryBackend`: unreferenced packs deleted, index rewritten, partially used packs kept; corrupted pack detected |
| `Engine\Backup` steps | resumability: every step interrupted at every checkpoint produces the same snapshot as an uninterrupted run; unchanged-file detection rules |
| `Backend\RestBackend` | request shapes (method, path, headers, Range), status mapping (400/401/403/404/507), retry policy, no credentials in URLs |
| `Database\Dump` | format byte-exact for fixtures; streaming into the chunker; per-table trailer; cursor resumption |
| `Database\Restore\Importer` | trailer validation, line splitting, refusal of truncated files |
| `Jobs\Runner` | state machine, lease takeover, budget exhaustion, loopback re-arm, single-active-job invariant |
| `Secrets`, `Support\Redactor`, `Backup\Roots/Excludes`, `Restore\Apply`, `Settings\Schema` | as in the security document |

### 5.2 Conformance suite (restic CLI + rest-server)

Defined in [ADR 0023](adr/0023-restic-cli-as-conformance-oracle.md): PHP-written
repositories pass `restic check --read-data` (restic 0.19.1, 0.18.1 and 0.14.0) and restore
byte-identically, including a 300 MB streamed database dump; repositories written by restic
0.19.1 and 0.14.0 are read, restored and deduplicated against by PHP; format 2 repositories
need `ext-zstd`, which a second CI job installs; a key calibrated by restic unlocks; restic
respects the plugin's lock; append-only behaviour and every server status code of
[ADR 0025](adr/0025-repository-servers.md) are asserted against a running rest-server.
Chunk-boundary and forget parity run in the unit suite against recorded vectors. Runs on
every change under `src/` and `tests/`, and nightly. Vectors are re-recorded with
`bin/record-vectors.sh` when a new restic version enters the matrix.

### 5.3 Database and integration tests

The **database suite** dumps tables that exercise every code path (keyset and offset
paging, composite and missing keys, binary data, `BIT`, a latin1 column, NUL bytes, line
breaks, quotes, emoji, a generated column, a view) with forced stops between pages, imports
the files with the `mariadb` client exactly as the disaster-recovery guide says, and compares
`CHECKSUM TABLE` results. The conformance suite's `BackupJobTest` runs the whole backup job
without WordPress against a TLS rest-server and the database.

The **integration suite** (`phpunit-integration.xml.dist`) needs a real WordPress:
`bin/test-site.sh install` downloads a pinned, checksum-verified WordPress, creates its
database, links the plugin and writes an application password; `bin/test-site.sh start`
serves it with PHP's built-in server, `max_execution_time = 30`, `memory_limit = 128M` and
`set_time_limit()` disabled. The tests talk to the site only over HTTP: the wizard through
the REST API, a backup driven only by requests to `wp-cron.php`, `restic check --read-data`
and restore, a database import with identical checksums, a scan for secrets in job files and
REST responses, and a web server killed in the middle of a tick. `BFR_SITE_FIXTURE_MB` and
`BFR_DB_FIXTURE_MB` set the fixture sizes; the nightly CI run uses 2048 and 300.

### 5.4 Benchmarks

`bin/bench.php` measures the chunker, Poly1305, sealing (AES-256-CTR plus Poly1305),
SHA-256, scrypt for keys the plugin creates and, with `--slow`, for a key restic
calibrated, and the CPU time of an end-to-end backup of a 256 MB tree into an in-memory
repository. The baseline is PHP 8.3 without OPcache and JIT; a JIT run is recorded for
comparison. Results are committed to `docs/benchmarks/`; the gates in architecture §16 are
checked in CI and the script exits with status 1 when one fails.

### 5.5 End-to-end (Playwright)

Wizard completion, backup with visible progress to "succeeded", snapshot browsing, single-file
restore and download, settings changes, Site Health entries.

### 5.6 Mutation testing and fuzzing

Infection runs on `Crypto`, `Format` and `Engine\Forget` in every CI run; the covered
mutation score indicator must stay at or above 90 % (`infection.json5.dist`). The built-in
fuzzer ([ADR 0028](adr/0028-built-in-fuzzer.md)) drives every parser that reads server data:
config, key file, master key, snapshot, lock, index, tree, unpacked files, pack header and
its length, the encryption envelope, the REST listing, Go string quoting, RFC 3339 times,
durations and ids. The unit suite runs 2000 deterministic inputs per parser; `fuzz.yml`
runs two minutes per parser every night. Every finding is saved with a replay command and
becomes a regression input in `tests/Fixtures/fuzz/<target>/`. Both compensate for the
missing second reviewer and external audit
([ADR 0024](adr/0024-governance-and-quality-assurance.md)).

### 5.7 Live smoke tests

`live-servers.yml` runs weekly, on manual dispatch and before every release. Against two
BorgBase free-tier repositories (normal and append-only) it runs init or connect, a backup
of a small fixture, the v2 listing, ranged reads, the append-only probe, lock creation and
deletion, a refused forget in append-only mode and a restore. Against a rest-server with
TLS, a self-signed CA and `--private-repos` it runs the same flow. Credentials are repository
secrets available only to scheduled and manually dispatched runs on `main`. BorgBase's
behaviour is verified this way instead of by vendor statements
([ADR 0025](adr/0025-repository-servers.md)).

## 6. Continuous integration

| Workflow | Trigger | Jobs |
| --- | --- | --- |
| `ci.yml` | push, pull request | PHPCS and a JavaScript syntax check; PHPStan with the boundary and reveal rules; unit tests on PHP 8.3 (without OPcache and JIT), 8.4 and 8.5, and 8.6 once released; the database suite against MySQL; mutation testing; later Plugin Check on the built package, `readme.txt` consistency, POT freshness |
| `conformance.yml` | changes under `src/` or `tests/`, nightly | oracle suite with the restic CLI and rest-server; the format 2 tests again with `ext-zstd`; benchmarks with gates |
| `fuzz.yml` | nightly, manual dispatch | two minutes of fuzzing per parser; findings uploaded as an artifact |
| `integration.yml` | push, pull request, nightly, manual dispatch | the integration suite against WordPress 7.1 on PHP 8.3; nightly and on request with the acceptance sizes; later WordPress latest-1 and PHP 8.5 |
| (later) `live-servers.yml` | weekly, manual dispatch, before every release | live smoke tests against BorgBase free-tier repositories and a TLS rest-server (§5.7) |
| (M2) `e2e.yml` | UI changes, nightly | Playwright |
| (later) `codeql.yml` | push, weekly | JavaScript analysis |
| (release) `deploy-wordpress-org.yml` | tag `v*` | build with `.distignore`, Plugin Check, manual approval, SVN deploy (`trunk`, `tags/x.y.z`, `assets/`), post-deploy diff, GitHub release notes only |

Workflows marked with a milestone arrive with the code they test. Actions are pinned to
commit SHAs and kept current by Dependabot; permissions are read-only unless a job needs
more; fork pull requests get no secrets. Required checks for merging: lint, analyse, unit
and mutation testing; conformance when `Crypto`, `Format`, `Backend` or `Engine` are
touched; integration once it has passed for the first time; Plugin Check with the release package.

## 7. Release process (WordPress.org)

WordPress.org is the only place users obtain the plugin
([ADR 0017](adr/0017-distribution-via-wordpress-org.md)). Git tags are the source of truth
for code; the SVN repository on WordPress.org is the distribution artefact, written only by
CI.

1. Update `CHANGELOG.md` and the `== Changelog ==` section of `readme.txt`; bump the version
   in the plugin header, `readme.txt` (`Stable tag`) and `Plugin::VERSION`; verify `Tested up
   to`, `Requires PHP`, `Requires at least`; attach the benchmark results.
2. Pull request "Release x.y.z" with the completed release checklist (self-review, live
   smoke tests green, benchmarks attached); CI runs Plugin Check on the built package and
   the full conformance suite.
3. Signed tag `vx.y.z` on `main`. `deploy-wordpress-org.yml` builds the package with
   `.distignore`, waits for the manual approval of the protected environment, then commits
   to SVN `trunk`, creates `tags/x.y.z` and syncs `.wordpress-org/` to `assets/`. Credentials:
   the WordPress.org username and a dedicated SVN password (generated in the WordPress.org
   profile, never the account password), stored as environment secrets.
4. A post-deploy job checks out the SVN tag and diffs it against the built package; any
   difference fails the release and alerts the maintainers.
5. A GitHub release entry carries the notes (supported WordPress/PHP ranges, restic CLI
   versions used as oracle, security notes, upgrade notes) but no package.
6. Semantic versioning: breaking changes to settings, dump format or CLI output are major.
   WordPress.org serves whatever `Stable tag` points to, so a hotfix is a new patch tag.

**First submission.** WordPress.org reviews a complete plugin uploaded as a zip. Expect
several weeks and questions about direct filesystem access, the cryptographic code and the
external service; the answers are prepared in the `readme.txt` FAQ ("why does this plugin
contain encryption code?" → it implements restic's published format with PHP's OpenSSL and
hash functions, plus Poly1305, scrypt and restic's chunker, which PHP does not offer, as
textbook code tested against reference implementations) and in code comments. The slug
`backups-for-restic` ([ADR 0015](adr/0015-naming-and-license.md)) becomes permanent at
approval. Pre-release builds are never published; testers build from source with
`bin/build-package.sh`.

## 8. Documentation standards

- `README.md` (GitHub): what, why, requirements, links; installation points to WordPress.org.
- `readme.txt` (WordPress.org): description, installation, FAQ with review-relevant answers,
  **External services** section (BorgBase endpoint, what data is sent, links to BorgBase's
  terms and privacy policy), screenshots, changelog. Reviewed like code.
- `docs/`: architecture, security, development, roadmap, disaster recovery, appendix, ADRs,
  benchmarks. Updated in the same pull request as the code they describe.
- Format documentation: every `Format` class header cites the restic design document
  section and source file it mirrors, with the restic version used for verification.
- User documentation (`docs/user/`): setup on shared hosting (cron options with panel
  screenshots), expectations for the first backup, hosting matrix, FAQ.

## 9. Contribution workflow

`main` protected; short-lived branches; conventional commit subjects; PR template asks for
tests, docs, security impact and, for `Crypto`/`Format`/`Engine`, a conformance run link.
Issue templates include Doctor output. DCO sign-off. Definition of done: code + tests + docs
+ changelog + green CI. The maintainer works through pull requests and the same checks as
everyone else; nobody pushes to `main` directly.

## 10. Licensing and attribution

Plugin GPL-2.0-or-later (`License` / `License URI` in `readme.txt`, header, `LICENSE`).
restic (BSD-2-Clause) and `rest-server` are development dependencies only; trademarks
acknowledged in `readme.txt`. The format implementation is original work that follows a
published specification; it copies no restic source code.
