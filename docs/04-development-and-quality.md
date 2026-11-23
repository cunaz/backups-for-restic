# 04 · Development, tooling and quality

How the repository is organised, which tools guard it, how tests are layered, and how a
release reaches WordPress.org. A contributor should clone, run `composer install`,
`composer check` and get a green result in minutes. The conformance suite needs the restic
CLI and rest-server, which `bin/install-test-tools.sh` downloads and verifies; no Docker.
The linter of the admin script needs Node.js and `npm ci`.

## 1. Repository layout

```
backups-for-restic/
├── backups-for-restic.php   plugin header, constants, autoloader include, Plugin::boot()
├── uninstall.php
├── readme.txt                       WordPress.org readme: the listing's source of truth (description, installation, FAQ, external services, changelog)
├── README.md, CHANGELOG.md, LICENSE, SECURITY.md, CONTRIBUTING.md
├── composer.json / composer.lock    dev tooling only; PSR-4 for tests
├── package.json / package-lock.json ESLint and its WordPress rulesets, dev tooling only; nothing from npm ships
├── phpcs.xml.dist, phpstan.neon.dist, phpunit.xml.dist, phpunit-integration.xml.dist, infection.json5.dist, eslint.config.mjs, .editorconfig, .gitattributes, .distignore, .coderabbit.yaml
├── .wordpress-org/                  listing assets synced to SVN `assets/`: banners, icon, screenshots
├── src/                             production code (see 02-architecture §3); src/WordPress/ is the only WordPress-facing part
│   └── autoload.php                 ~20-line PSR-4 autoloader for the BackupsForRestic\ namespace
├── assets/js/, assets/css/          admin.js (classic script on wp-api-fetch, no bundler) and a scoped stylesheet
├── languages/                       not in Git: `composer pot` writes backups-for-restic.pot here (§5.8); language packs come from translate.wordpress.org
├── build/                           not in Git: bin/build-package.sh writes the package here (§5.8)
├── tests/
│   ├── Unit/                        PHPUnit, no WordPress, no database, no network
│   ├── Conformance/                 PHPUnit + restic CLI + rest-server: format parity, server behaviour, whole jobs without WordPress
│   ├── Fuzz/                        built-in fuzzer and its targets, one per parser (ADR 0028)
│   ├── Mutation/                    PHPUnit configuration of the mutation run
│   ├── Support/                     test doubles, the restic and rest-server harness, fixture generators
│   ├── PHPStan/                     PHPStan extensions for the analysis of src/
│   ├── Database/                    the dump against a real MySQL or MariaDB, imported with the mariadb client
│   ├── Integration/                 a real WordPress from bin/test-site.sh: who may call the routes, wizard over REST, backups through wp-cron.php, a killed tick, cancelling, deactivation, schedules, an append-only server, WP-CLI, the multisite refusal, uninstall
│   ├── E2E/                         browser tests with Playwright against the test site: wizard, first backup, dashboard, settings, recovery kit, cancel
│   └── Fixtures/                    restic vectors per version, Go and libsodium vectors, fuzz regressions
├── bin/                             install-test-tools.sh, test-site.sh with its router, diagnostics and the filters that switch TLS verification off for a test, record-vectors.sh, record-forget-vectors.sh, bench.php, fuzz.php, build-package.sh
├── docs/                            design documents, ADRs, guides/, benchmarks/
└── .github/                         workflows ci.yml, conformance.yml, integration.yml, fuzz.yml; dependabot.yml; pull_request_template.md; ISSUE_TEMPLATE/bug_report.md; codeql.yml; live-servers.yml; deploy-wordpress-org.yml
```

The [release-candidate report](benchmarks/2026-11-20-release-candidate.md) records the local test results. The [roadmap](05-roadmap.md) tracks the hosted provider and directory approval gates.

## 2. Toolchain (development only)

| Tool | Purpose |
| --- | --- |
| PHP 8.3, 8.4, 8.5 and 8.6 (CI matrix, performance baseline on PHP 8.3 without JIT) | runtime floor 8.3 ([ADR 0003](adr/0003-version-floors.md)) |
| Composer 2 | Installs the development dependencies and checks for native Sodium, which the unit suite uses outside WordPress. The release package omits `vendor/`. |
| `wp-coding-standards/wpcs` 3, PHP_CodeSniffer | WordPress Coding Standards |
| `phpstan/phpstan` 2 + `szepeviktor/phpstan-wordpress` + strict rules | static analysis at level `max`, with three rules of the project: the WordPress-free core, the dependencies of the engine and the `reveal()` allow-list (§4) |
| `phpunit/phpunit` 12 | unit, database, conformance and integration tests |
| Node.js 22 with npm | runs ESLint; `node --check assets/js/admin.js` stays as a syntax check that needs no packages |
| ESLint 10 with `@wordpress/eslint-plugin` 28 (two packages with exact versions in `package.json`; `package-lock.json` pins the about 400 packages they bring) | lints `assets/js` with the rulesets `esnext`, `custom` and `i18n` (§3); development only, nothing from npm ships |
| Infection 0.35 (PHAR, pinned and checksum-verified by `bin/install-test-tools.sh`) with pcov | mutation testing in six gates (§5.6): `Crypto`, `Format`, `Engine\Forget` and the database import filter as the merge gate ([ADR 0024](adr/0024-governance-and-quality-assurance.md)), the file restore code, ZIP downloads and, with a database, the import and exchange |
| Built-in fuzzer (`bin/fuzz.php`, `tests/Fuzz/`) | structure-aware fuzzing of every parser that reads server data ([ADR 0028](adr/0028-built-in-fuzzer.md)) |
| **restic CLI** 0.20.0, 0.19.1, 0.18.1 and 0.14.0 (upstream releases, checksum-verified by `bin/install-test-tools.sh`) | conformance oracle ([ADR 0023](adr/0023-restic-cli-as-conformance-oracle.md)); never shipped |
| **rest-server** 0.14.0 (upstream release, checksum-verified), with TLS, normal, `--append-only` and `--max-size` | local repository server for conformance and integration tests |
| WordPress 7.1.5, 7.0.9 and 6.7.12 (checksum-pinned in `bin/test-site.sh`, selectable with `BFR_WP_VERSION`) | the real WordPress of the integration suite, served by PHP's built-in server. The project uses no WordPress test library, no function mocking and no `@wordpress/env`: the adapters are tested against a running WordPress or not at all ([ADR 0029](adr/0029-wordpress-free-core.md)) |
| Plugin Check 2.2.0 (pinned and checked file by file by `.github/actions/check-package`) | the WordPress.org review team's scanner, in CI on the built package, through WP-CLI in a test site (§5.8) |
| WP-CLI 2.12.0 (PHAR, pinned and checksum-verified by `bin/install-test-tools.sh wp-cli`) | `wp i18n make-pot` for the translation template (§5.8); it needs no WordPress for that |
| `@playwright/test` 1.63.0 (pinned in `package.json`) | the browser tests of §5.5; its browser is installed in CI by the same version |
| CodeQL | JavaScript analysis of the shipped admin interface on changes and weekly |
| Git, GnuPG and Subversion | Verify the release signature, deploy the package and compare the exported SVN tree with the artifact |

Composer scripts: `lint`, `fix`, `analyse`, `test:unit`, `test:database`,
`test:conformance`, `test:integration`, `test` (unit, database and conformance), `check`
(lint + analyse + unit), `mutation`, `fuzz`, `bench`, `pot` (builds the package and the
translation template). The one npm script is `lint:js`; `npm ci` installs what it needs.
`composer check` does not run it, so that the PHP checks need no Node.js. pcov slows
every PHP process it is loaded into, roughly threefold for the unit suite, so enable it only
for coverage and mutation runs.

## 3. Coding standards

- **WordPress Coding Standards** (`WordPress-Extra` and `WordPress-Docs`) for `src/`,
  `tests/` and `bin/`, with documented deviations in `phpcs.xml.dist`: PSR-4 file and class
  naming, short arrays, no Yoda conditions, `declare(strict_types=1)`, and native filesystem
  functions: the WordPress-free namespaces are exempt from the `AlternativeFunctions` sniff,
  and the few call sites in `src/WordPress` carry a comment.
- **Tab indentation** as WordPress prescribes (`.editorconfig` sets it, PHPCS checks it).
  No line length is enforced: neither `phpcs.xml.dist` nor `.editorconfig` has a limit, and
  564 of the 20 461 lines in `src/` are longer than 100 characters. Whether to enforce one
  is not decided. Markdown is wrapped by hand at about 100 characters.
- **Naming** as WordPress prescribes: `snake_case` for functions, methods, variables and
  properties, `PascalCase` for classes and enums, `UPPER_CASE` for constants. Parameter names
  avoid reserved words (`$parent_id`, not `$parent`).
- **Banned functions** in production code: `exec`, `shell_exec`, `system`, `passthru`,
  `popen`, `proc_open`, `unserialize`, `extract` and `create_function` through
  `Generic.PHP.ForbiddenFunctions` in `phpcs.xml.dist`, `eval` through `Squiz.PHP.Eval`, and
  backticks through `Generic.PHP.BacktickOperator`. Tests and `bin/` are exempt from the
  function list, because they start restic and rest-server.
  Base64 for restic's key files goes through `Support\Bytes`, which decodes strictly.
- **Prefixes**: everything global uses `backups_for_restic_` / `backups-for-restic`; classes live in
  `BackupsForRestic\`; `PrefixAllGlobals` configured accordingly.
- **Cryptography and format code**: `Crypto` and `Format` are `final`, dependency-free,
  documented with the byte layouts they implement and, where one exists, the name of the
  restic type they mirror. Changes follow the solo-maintainer regime of
  [ADR 0024](adr/0024-governance-and-quality-assurance.md): self-review checklist (§9.1),
  24-hour cooling-off before merge, green conformance run and mutation gate.
- `final` by default, immutable value objects, enums for closed sets, one exception base
  class, no static mutable state, docblocks explain *why*.
- JavaScript: one classic script in ES2020, `assets/js/admin.js`; no modules, no frameworks,
  no build step ([ADR 0012](adr/0012-admin-ui-without-build-step.md)). ESLint checks it with
  the rulesets `esnext`, `custom` and `i18n` of `@wordpress/eslint-plugin`
  (`eslint.config.mjs`, `npm run lint:js`), and a warning fails like an error. What the
  configuration decides beyond the rulesets, each with its reason in the file:
  - Every translation call names the text domain as the literal `backups-for-restic`. The
    `i18n` ruleset also asks for translator comments, for literal strings instead of
    variables and for placeholders that match.
  - Syntax newer than ES2020 is a parse error, the file is read as a classic script, and
    only `wp`, `window`, `document`, `Blob`, `Node` and `URL` are known globals.
  - The preset `recommended` is not used: it runs Prettier whenever that package is
    installed, which npm always does for two packages the plugin depends on, and Prettier
    would rewrite about two thirds of the script. The rulesets `react` and `jsx-a11y` and
    the import rules have no subject in a script without JSX and modules.
  - One rule differs from the ruleset: the script writes `function () {` with a space, as
    the PHP code writes its closures, not `function() {`.

## 4. Static analysis configuration

PHPStan level `max`, strict rules, WordPress stubs, no baseline (`phpstan.neon.dist`). The
project adds three rules in `tests/PHPStan/`:

- `WordPressFreeCoreRule`: no namespace except `WordPress` may call a WordPress function or
  use a WordPress class with `new`, a static call, a class constant or `instanceof`
  ([ADR 0029](adr/0029-wordpress-free-core.md)).
- `EngineDependencyRule`: the stack `Exception`, `Support`, `Crypto`, `Format`, `Backend`,
  `Engine` depends in one direction only. A layer may use itself and the layers before it
  in this list, and nothing else of the plugin: not `Jobs`, not the adapters in `WordPress`,
  not `Settings`. Two classes outside the stack are on the rule's list: `Secrets\Secret`,
  used in `Support` (`Redactor`), in `Backend` (`RestBackend`) and in `Engine`
  (`KeyUnlock`), and `Storage\Files`, used in `Engine`. The list allows a helper for the
  whole layer, so another class of `Support` may take a `Secret` without a change of the
  list; where a secret is revealed stays the business of `SecretRevealRule`. The two
  classes are held to the rule themselves: `Secret` may use nothing of the plugin, `Files`
  only `Exception`. The rule reads the `use` statements of a file and every name in its
  code; a name that stands only in a comment and needs no import is not seen. A new
  dependency needs a reviewed change of the list ([architecture](02-architecture.md) §3).
- `SecretRevealRule`: `Secret::reveal()` only in the classes on its list.

A fourth extension, `UnpackReturnTypeExtension`, only gives `unpack()` a precise return type
for integer formats. None of the rules has a unit test.

One rule of the first design does not exist: a rule between `Admin`, `Rest` and `Cli` has
no subject any more, because all adapters live in the one namespace `WordPress`.

## 5. Tests as specification

### 5.1 Unit tests (fast, pure, no WordPress)

| Area | What is asserted |
| --- | --- |
| `Crypto\Cipher` | seal/open round trip; a file that restic encrypted opens; every modified byte, a short input and a zero nonce are rejected; nonces are fresh for every object |
| `Crypto\Poly1305` | RFC 8439 vectors and 400 libsodium vectors |
| `Crypto\Scrypt` | RFC 7914 vectors; the key of the restic 0.19.1 fixture opens; parameters beyond the limits are refused; checkpoint/resume yields the same key as a single pass; a lost table file stops the derivation; the file-backed table is deleted afterwards |
| `Format\KeyFile`, `Config`, `Snapshot`, `Lock` | the restic 0.19.1 fixtures round-trip byte for byte; unknown versions, other KDFs, short salts and mistyped fields are rejected; a lock is stale after 30 minutes |
| `Format\Chunker` | the 17 boundaries of a 24 MiB stream equal a vector from restic's chunker library (`tests/Fixtures/vectors/generate-go-vectors.go.txt`); boundaries do not depend on read sizes; irreducibility and polynomial generation match recorded vectors. Parity with `restic backup` itself is tested in the conformance suite, indirectly (§5.2) |
| `Format\Pack` | writer/reader round trip; the headers of the fixture's packs match its index and every blob verifies; all four entry types; limits on blob and header length; corrupted packs rejected |
| `Format\Index` | JSON ⇄ entries byte for byte; merge and lookup; the binary form of the cache round-trips and a damaged cache is refused. The split of index files at 50 000 blobs has no test |
| `Format\Tree` | ordering and uniqueness enforced when a tree is written; Go quoting of names (control characters, quotes, backslashes, invalid UTF-8); mode bit mapping for files, dirs, symlinks, setuid/setgid/sticky; every tree of the restic fixture re-encodes byte for byte |
| `Engine\Forget` | parity with 11 recorded `restic forget --dry-run --json` results over 150 snapshots in four time zones. The recorded policies use `--keep-last`, `--keep-hourly`, `--keep-daily`, `--keep-weekly`, `--keep-monthly`, `--keep-yearly`, `--keep-within`, `--keep-within-daily`, `--keep-within-weekly` and `--keep-tag`. `--keep-within-hourly` has a hand-written case; `--keep-within-monthly` and `--keep-within-yearly` have none |
| `Engine\Prune`, `Engine\Check` | against `InMemoryBackend`: unreferenced packs deleted, index rewritten, partially used packs kept; corrupted pack detected |
| `Engine\Backup` and `Restore` | an interrupted backup produces the same snapshot tree as an uninterrupted one; unchanged files are reused, changed ones read again, identical files share blobs; restore refuses names that leave the target |
| `Engine\Locking`, `KeyUnlock`, `IndexCache` | leftovers of a dead refresh, conflicts between runs and hosts; unlock resumed across ticks and key files; the cache fetches only new index files and respects its limit |
| `Backend\RestBackend` | request methods, paths, headers and ranges, status mapping, credential handling, retry of reads and streamed listings, identical upload bytes across five attempts and delays of 0.5, 1, 2 and 4 seconds. Ambiguous uploads, configuration writes and quota errors are checked for a single attempt |
| `Jobs\Runner`, `JobType`, `JobError`, `TickReport`, `RetentionGuard` | steps in order across ticks; cleanup after success, failure and cancellation; back-off of retryable errors; lease and runner lock; one active job; redacted job files; the error codes; which ticks chain; the switching rules of the retention modes |
| `Storage\Zip\ZipWriter`, `ZipRecords`, `Crc32`, `Storage\DownloadTokens` | archives read back by `unzip -t`, `unzip -l` and an unpacking where `unzip` is installed, by `ZipArchive` where the extension is loaded, and always by a reader of the central directory in the test; the same bytes whether written in one call, in calls that stop at once, by a new object per call or after a kill between two checkpoints; zip64 with lowered limits; links, second hard links and unsafe names refused. Tokens: redeemed once, wrong token or subject, expiry, a full and a damaged store, no token in the file, every access under the lock |
| `Secrets`, `Settings`, `Storage`, `Support`, `Doctor`, `Scheduling\SchedulePolicy`, `Notifications\Notifier`, `Connection\ServerProfile` | their own test classes under `tests/Unit/` |
| Fuzz targets | 2000 deterministic inputs per parser and the saved regression inputs (§5.6) |

`Support\Redactor` has dedicated tests for raw, URL-encoded and Base64-encoded
credentials, including Basic authentication. The conformance and database suites cover
backup roots, exclusions and the database dump. The database suite also imports into
shadow tables. The file-plan tests exercise path policy and preconditions, and the
WordPress integration suite verifies live application and undo (§5.2–5.3).

### 5.2 Conformance suite (restic CLI + rest-server)

Defined in [ADR 0023](adr/0023-restic-cli-as-conformance-oracle.md): PHP-written
repositories must pass `restic check --read-data` on all four releases and restore
byte-identically, including a 300 MB streamed database dump. PHP also reads, restores
and deduplicates against repositories written by each pinned restic release. Format 2 repositories
need `ext-zstd`, which a second CI job installs; a key calibrated by restic unlocks; restic
respects the plugin's lock; append-only behaviour and every server status code of
[ADR 0025](adr/0025-repository-servers.md) are asserted against a running rest-server.
"Byte-identically" means: for every file the SHA-256 of its content, its permission bits and
its modification time to the second; for every directory its permission bits; for every
link its target. Ownership and directory times are not compared.

Prune and check are compared with restic as well: the plugin deletes the packs that
`restic prune` counts as unused, and its check reports the damaged and the deleted pack that
`restic check` reports. `BackupJobTest`, `MaintenanceJobTest` and `RetentionSimulationTest`
run whole jobs (settings, encrypted secrets, runner, many short ticks) without WordPress
against a TLS rest-server.

Chunk-boundary and forget parity run in the unit suite against recorded vectors (§5.1). The
chunker's parity with the restic CLI is tested here indirectly: after restic backed up the
fixture, a plugin backup of the same files must add no data blob, which holds only if both
cut the same chunks. Runs on every change under `src/` and `tests/`, and nightly. Vectors
recorded with restic 0.19.1 can be regenerated with `bin/record-vectors.sh` and
`bin/record-forget-vectors.sh`. The live conformance matrix uses the versions pinned in
`bin/install-test-tools.sh`.

### 5.3 Database and integration tests

Set `BFR_SITE_MEMORY_MIB=96` for both `bin/test-site.sh install` and `start` to run
the HTTP fixture at the supported minimum. The default is 128 MiB. When running multiple fixtures, pass the same `BFR_SITE_TOOLS_DIR` and `BFR_SITE_PORT` to the installer, server and PHPUnit, and give each fixture its own `BFR_DB_NAME`. The launcher sets
both the PHP ceiling and WordPress's administrative ceiling, so cron uses the same limit.

The **database suite** dumps tables that exercise every code path (keyset and offset
paging, composite and missing keys, binary data, `BIT`, a latin1 column, NUL bytes, line
breaks, quotes, emoji, a generated column, a view) with forced stops between pages, imports
the files with the `mariadb` client exactly as the disaster-recovery guide says, and compares
`CHECKSUM TABLE` results. It also changes tables while they are dumped: rows deleted and
inserted before the cursor for keys of every comparable type, a row deleted and inserted
again within one tick, a table without a key, a column added between ticks and during one,
and DDL that waits for the tick's metadata lock. It checks TIMESTAMP values across daylight
saving time, a latin1 connection, definitions with Cyrillic, CJK and Nordic text and a row
above 16 MiB. Each test names the connection it reads through. On the site's connection and
on the dump's own run the import of every code path, a column added between ticks, a table
without a key, the TIMESTAMP values, the latin1 connection, column names of digits only, a
row limit left on the session, a table that another connection keeps locked and a table of
rows of 3 MiB. Only on the dump's own run a row deleted and inserted again within one tick,
DDL that waits for the metadata lock, a definition that changes at every tick and a row too
large for the memory limit, which depend on its snapshot or its single read of a table
without a key. Only on the site's connection run keys of every type and rows changed
before the cursor, a DOUBLE key in exponent notation, resumed against uninterrupted dumps, a
change within one tick, the row above 16 MiB and the definitions in other scripts.
`DedicatedConnectionTest` checks the opening of the dump's own connection against a
stand-in for `wpdb`, its encryption, the end of a table's transaction under every
`completion_type`, a lock wait and an abandoned read. The conformance suite's
`BackupJobTest` runs the whole backup job without WordPress against a TLS rest-server and
the database.

The CI database matrix uses MySQL 8.4.13 and MariaDB 11.4.13, pinned by image digest. Every
test of the suite skips when no server answers, so CI compares the skipped tests with
`tests/Skips` (§6): none may skip on MySQL, and on MariaDB exactly the eight cases of the
atomic exchange, which is MySQL-only by design. Shadow-import tests compare table
checksums, including invisible columns and expression defaults, and check that live tables
stay unchanged. A parent sends SIGKILL at six DDL/transaction boundaries, then resumes the
import and verifies duplicate rows exactly once. Foreign table collisions, an active
transaction, session restoration, truncation warnings and wrong INSERT destinations are
also exercised ([ADR 0035](adr/0035-transactional-shadow-import.md)).

The **integration suite** (`phpunit-integration.xml.dist`) uses real WordPress installations.
`bin/test-site.sh install` downloads a pinned, checksum-verified WordPress, creates its
database and writes an application password. The CI matrix sets `BFR_TEST_PLUGIN_DIR`
to the built package and installs a physical copy in both the single site and the multisite
network. A local run without the variable links the checkout. `bin/test-site.sh start`
serves it with PHP's built-in server and several workers, `max_execution_time = 30`,
`memory_limit = 128M` and `set_time_limit()` disabled. A router script enforces "deny all"
`.htaccess` files as Apache would. Two notes on that server:

- The JIT is switched off (`opcache.jit=disable`). With PHP 8.3's tracing JIT, which
  `setup-php` turns on in CI, the server's processes died with SIGSEGV in backup ticks.
- A tick raises its own memory limit to `WP_MAX_MEMORY_LIMIT` (architecture §4.2). The test
  site's `wp-config.php` therefore sets that constant to 128M; without it ticks would run
  with 256M and the 128M of the acceptance criterion would not be in force.

The current WordPress row on PHP 8.3 also runs `tests/Hosting/private-directory.sh`.
It serves only a temporary document root on loopback. The packaged plugin checks its
private directory through WordPress HTTP on Apache with and without working overrides,
and on nginx with and without an explicit deny rule. The test checks the staging decision
and removal of probe files after each request.

The tests reach the site over HTTP, as a browser and a cron service do. Where an
administrator acts outside the plugin's own interface (deactivating or deleting it, creating
a user) they run PHP inside the site's WordPress on the command line, and for what only the
files show (a job's lease, its log) they read the private directory. `tests/Support/TestSite.php`
is that access. The classes share one site and one repository, so
`phpunit-integration.xml.dist` names the files in order. A class whose tool or site is not
installed, or does not answer, skips in `setUpBeforeClass()`. No skip is expected in this
suite: the configuration fails a run in which a test skips (`failOnSkipped`), and because
PHPUnit does not count a class that skips in `setUpBeforeClass()` there, CI also compares the
JUnit log with the empty list `tests/Skips/integration.txt` (§6). After the suite, and after the
browser tests of §5.5, `bin/test-site.sh check-logs` fails the job when `.tools/php-errors.log`
or the site's `wp-content/debug.log` holds an entry that names a file of the plugin: a notice,
warning or deprecation reaches neither a response nor a test, only these logs. The classes:

- **`AccessTest`**: every route without credentials and as an editor; its list of routes is
  compared with what WordPress has registered, so a new route cannot go untested; the files
  of the private directory are not served. The download of restored content needs the
  WordPress password in the JSON body and the reports do not; wrong passwords of one user
  are counted, slowed down, locked and reported to `wp_login_failed` while another user is
  unaffected.
- **`WordPressSiteTest`**: the wizard through the REST API against a TLS rest-server with its
  CA certificate, then a backup driven only by requests to `wp-cron.php`, `restic check
  --read-data`, a restore of the fixture's uploads directory, a database import with
  identical checksums of three tables, and a search for both passwords in the files under
  `jobs/` and in four REST responses; a tick that is killed in the backup step, shown by
  the lease it left in the job document, after which the backup goes on from its
  checkpoint; refused settings; prune and check jobs with the dashboard data and the cache
  route.
- **`JobControlTest`**: the recovery kit only with the WordPress password; a running backup
  that refuses a new server, a new repository, another password, clearing the cache and a
  second job, and is cancelled with its lock released; deactivation, which ends the running
  backup without any further tick; a wrong repository password and its repair; a security
  key replaced in `wp-config.php` and the two passwords entered again; repository
  requests that verify certificates while a must-use plugin switches verification off for
  the site (`bin/test-site-hostile-filters.php`); the probe of the private directory as a
  browser makes it, on a site that the same file keeps from requesting itself, where a
  refusal counts only with the plugin's stylesheet requested beside it; a setup
  that is not completed, and a backup that does not run, while the private directory is
  served.
- **`RestoreJobTest`**: after a backup of its own, a restore job started with `POST /jobs`
  stages one directory with bytes identical to the source, leaves no lock in the repository
  and nothing that the web server hands out; invalid snapshot IDs and paths are refused
  with 400 and a code; a schedule slot that comes due while a restore is active stays
  unhandled and starts its backup once the restore has ended.
- **`RestoreStagingTest`**: single-use downloads of a staged file, ZIP and report, the first
  two only with the WordPress password and each recorded with user, job, kind and path; the
  user that the restore job records; the deny files of `restores/` and of a staging
  directory; a finished restore is listed by `GET /restores` with what its
  job recorded and `DELETE /restores/{id}` removes it, with 400, 404 and 409 for a name
  that is no job ID, a job without staging and a restore that has not ended; staging whose
  job document says it ended before the configured hours leaves the list, and a heartbeat
  deletes it.
- **`ScheduleTest`**: the heartbeat, run through `wp-cron.php`, records the first slot,
  starts a backup whose slot has come and forget after it, prune and check on their slots,
  and nothing for manual schedules.
- **`AppendOnlyTest`**: the rest-server restarted in append-only mode and back: the switch
  to retention mode external without a deletion, backups that go on, the proposal to switch
  back, and no deletion until an administrator decides.
- **`CliTest`**: the WP-CLI commands with a real, pinned WP-CLI
  (`bin/install-test-tools.sh wp-cli`): `doctor`, `status`, `snapshots` (with a host name of
  control characters, which it quotes), `backup`, `forget`,
  `prune` and `check` with `--wait`, `tick --until-idle`, a second job that is refused,
  forget and prune that are refused in retention mode external, `settings` with the
  refusals of the settings page, and `cancel` and `logs` on a backup that is at work.
- **`MultisiteTest`**: on a second WordPress that is a multisite network
  (`bin/test-site.sh install-multisite`, never served, reached through WP-CLI): activation
  is refused for the network and for one site, and a plugin that is active all the same
  registers no route, no cron event and no command, and shows its notice.
- **`UninstallTest`**: what the Plugins screen runs on "Delete" leaves no option, transient,
  cron event or file of the plugin, and the repository unchanged; on a PHP older than 8.3
  it refuses and removes nothing.

`BFR_SITE_FIXTURE_MB` and `BFR_DB_FIXTURE_MB` set the fixture sizes (64 and 32 by default);
the nightly CI run uses 2048 and 300. The tests that catch a backup in the middle add
256 or 512 MiB of random data of their own.

The integration tests request `wp-cron.php` directly to control the timing of jobs.
A separate acceptance run on 2026-11-20 exercised visitor-triggered cron with the
release ZIP (SHA-256 `2416473f698ec9ff3a07494a934bce217abe522efec4db18140bf1964e09fde2`,
the package of the commits from `5b9dbea` to `eec2a46`, not the one of `acaecdc` on `main`).
It was a manual, one-off run that no workflow or script of the repository reproduces.
It used WordPress 7.1.5, PHP 8.3.36, a 128 MiB memory limit, a 30-second request
limit, a five-second tick budget and automatic loopback chaining. The fixture marked
the previous daily backup slot as missed and waited for the heartbeat already on
WordPress's schedule. One anonymous `GET /` triggered WordPress to send the heartbeat
request. The plugin then sent three loopback requests. A passive observer matched each
outbound request to the receiving cron hook. The scheduled backup finished in four
ticks and 23.92 seconds from the page visit, with zero warnings.

Native restic 0.20.0 verified all repository data before and after the scheduled
backup. Restoring the baseline and the changed WordPress files reproduced the expected
SHA-256 hashes, modes and modification times for a 32 MiB upload and its marker file.
The restored SQL imported into a separate database and matched the source checksums
for `wp_posts`, `wp_postmeta` and `wp_users`. The local repository contained both
snapshots and zero locks. The restored SQL and the job and server logs contained no
matches for the test credentials across 18 files (124,828 bytes).

The browser tests in §5.5 cover the admin page. The suite has no Site Health test.

### 5.4 Benchmarks

`bin/bench.php` measures the chunker, Poly1305, sealing (AES-256-CTR plus Poly1305),
SHA-256, scrypt for keys the plugin creates and, with `--slow`, for a key restic
calibrated, and the CPU time of an end-to-end backup of a 256 MB tree into an in-memory
repository. The baseline is PHP 8.3 without OPcache and JIT; a JIT run is recorded for
comparison. Results are committed to `docs/benchmarks/`; the gates in architecture §16 are
checked in CI and the script exits with status 1 when one fails.

What the figures do not cover: both scrypt figures come from `Scrypt::derive()`, which keeps
its table in memory, while the unlock of a job keeps the table in a file; nothing times that
path. The backup figure is the user CPU time of one process that is never interrupted,
measured for 256 MB and extrapolated to 1 GB, without network and without writes to a
server. No other test measures CPU time: the nightly integration run backs up 2 GB in ticks
to a rest-server and times nothing.

### 5.5 End-to-end (Playwright)

`tests/E2E/admin.spec.js` drives the admin page in Chromium against the test site of §5.3,
with the installed distribution at its normal path. Build it with `bin/build-package.sh`,
then run `BFR_TEST_PLUGIN_DIR="$PWD/build/backups-for-restic" bin/test-site.sh install`,
`bin/test-site.sh start` and `npm run test:e2e`. CI uses the same sequence in the
"Admin page in a browser" job. The tests click and read what an administrator clicks and
reads, and send no request of their own. `tests/E2E/global-setup.js` starts a TLS
rest-server with a certificate of its own authority for them. They run one after the other
on one site, without retries:

- the setup wizard: the environment checks; a connection test that fails until the
  server's CA certificate is entered; a new repository and its recovery kit, which has to
  be acknowledged; the schedule; the first backup with its progress to "Finished
  successfully";
- the dashboard with the last backup, the repository and the recent jobs, and the snapshot
  list with that one snapshot;
- the snapshot browser: the retention preview of the list, the switch to the whole
  repository and back, the newest snapshot with its manifest, the walk from the top
  directory down to the one of WordPress, a file that is ticked and stays selected while
  another directory is open, and the selection cleared;
- settings that are saved and still there after a reload, an invalid retention window that
  is refused with a message at its field, and the recovery kit, which needs the WordPress
  password;
- the forms of the Settings tab for the REST password, the CA certificate and the
  repository password, each with the refusal of a wrong value;
- the Environment tab on a site that cannot request itself: the browser makes the probe
  of the working directory, and the check says "protected";
- a backup of 1 GiB of new data that is cancelled from the dashboard while it runs.

The restore tests download the selected file, ZIP and report. On a disposable site they
exercise rejected and accepted live-file confirmations, a stale preview, undo and staged
deletion. The two-installation test uses a second real WordPress, database, URL and content
directory, created for that test from the same package.

### 5.6 Mutation testing and fuzzing

Infection runs in six gates, each with its own code, its own tests from
`tests/Mutation/phpunit.xml.dist` and its own thresholds. The database gate's tests have a
configuration of their own, `tests/Mutation/database/phpunit.xml.dist`, with a memory limit of
256M instead of 128M: the initial run of a gate keeps the coverage of every test in one
process, and beside it the 16 MiB row of `DatabaseDumpTest` does not always fit into 128M.
The gate also leaves out the test of a batch beyond `max_allowed_packet` in
`DatabaseExchangeTest`, which the database suite runs. It lowers the server's limit for a
moment, and a mutant process killed inside it at the timeout, or one that connects meanwhile,
leaves or meets a server on which large statements fail whatever the mutation: on 2026-11-23
188 mutants counted as killed that way.

| Gate (Composer script, configuration) | Mutated code | Runs in | MSI floor | Covered MSI |
| --- | --- | --- | ---: | ---: |
| Core (`mutation`, `infection.json5.dist`) | `Crypto`, `Format`, `Engine\Forget` and `Database\Restore` without the ten classes that only the database suite reaches | `ci.yml` | 87 % | 90 % |
| Planner (`mutation:planner`) | `Restore\ApplyPlan`, `Restore\SiteLayout` | `ci.yml` | 87 % | 88 % |
| File restore (`mutation:restore`) | the rest of `src/Restore` | `ci.yml` | 73 % | 77 % |
| File apply jobs (`mutation:apply`) | `src/Jobs/Apply` without its three `Database*` classes | `ci.yml` | 67 % | 73.8 % |
| ZIP downloads (`mutation:zip`) | `src/Storage/Zip` | `ci.yml` | 83 % | 84 % |
| Database (`mutation:database`) | all of `Database\Restore` and the `Database*` classes of `src/Jobs/Apply`, killed by `tests/Unit/Database` and `tests/Database` against MySQL | `database-mutation.yml` | 76.4 % | 81.3 % |

Every script passes `--with-uncovered`. Without it Infection mutates only lines that a test
covers, so code that no test reaches produced no mutant and counted nowhere: before
2026-11-22 the core gate listed `Database\Restore`, but ten of its classes, reached only by
the database suite, created no mutant at all. The MSI counts such code as not killed.
The covered MSI of the core stays ADR 0024's merge gate at 90 %. The other thresholds are
floors: the scores measured on 2026-11-23 (below), rounded down, and never above the score
over the mutants that every run of the gate killed, hence the tenths of the apply and the
database gate. A floor may only rise; the pull request that adds the tests raises it. The goal
for every gate is ADR 0024's 90 %. The planner had a covered threshold of 90 % as well; it
scores 88.80 % and has a floor like the others until tests raise it.

**What a mutant process measures.** Infection runs each mutant in a PHPUnit process of its
own and serves the mutated class there through a user-space `file://` stream wrapper in place
of PHP's own. Until 2026-11-23 the tests ran under that wrapper, which differs from PHP's in
two ways that fail tests whatever the mutation, and every mutant that such a test covers
counted as killed:

- `fsync()` fails on every stream it opens. Since 2026-11-22 every job document and journal
  record is written durably ([ADR 0041](adr/0041-guarded-file-changes.md)), so each test that
  saves one failed. The file restore gate reported 98 % covered and the apply gate 100 %.
- Its `url_stat()` reports a path that it cannot read as missing: an unreadable file, or a
  link whose target has been removed. Tests of unreadable files failed, and cleanups left
  links and their directories behind and warned, which fails a run under `failOnWarning`.
  The planner gate reported 98.48 % covered on `main` and the ZIP gate 99.87 %.

Since then the mutation suites start with `tests/Mutation/bootstrap.php`
(`tests/Support/MutationProcess.php`). In a mutant process it loads every class below the
gates' source directories while the wrapper is in place, so that the mutated class is the one
in memory, and then restores PHP's own wrapper. That costs about 16 ms and 3 MiB per process.
A class first loaded after the restore is the original, so a gap in the list lowers a score
instead of raising it. A mutated copy that does not compile keeps the wrapper, and the tests
meet its syntax error as before. The initial test run has no such wrapper, and the bootstrap
loads nothing there.

The bootstrap never fails a mutant process, because Infection would count that as a kill.
When `fsync()` still fails after the restore, or the user-space wrapper is back when the
process ends, it appends a line to a marker file instead. `bin/mutation-gate.sh`, through
which the Composer scripts and so CI run Infection, fails the gate on that whatever the score.
`tests/Unit/Tooling/MutationGatesTest.php` checks outside any mutant process that the
bootstrap of every gate loads every directory the gate mutates, that loading them prints and
defines nothing, and that the canary fires under a user-space wrapper.

The jobs keep the tests' temporary files on tmpfs (`.github/actions/mutation-tmpdir`): a gate
measures the tests, not the disk, and on the runner's ext4 every `fsync()` of a durable write
costs milliseconds, so the file restore gate would not finish within its job limit.

Measured on 2026-11-23 with `main` at `f0ea681` merged: PHP 8.3.37 with ext-zstd and pcov, 8
threads, the tests' temporary files on tmpfs as in CI, and MySQL 8.4.11 for the database
gate. "Before" is the covered MSI that the gate reported while its tests ran
under Infection's wrapper: on 2026-11-22 for the planner, file restore, apply and ZIP gates,
on 2026-11-20 for the core and the database gate.

| Gate | Mutants | Not covered | Escaped | Timed out | MSI | Covered MSI | Minutes | Before |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Core | 4,453 | 123 | 412 | 65 | 87.99 % | 90.48 % | 15.3 | 90.39 % |
| Planner | 745 | 13 | 82 | 2 | 87.25 % | 88.80 % | 1.1 | 98.36 % |
| File restore | 3,178 | 174 | 666 | 24 | 73.57 % | 77.83 % | 15.0 | 98 % |
| File apply jobs | 1,275 | 111 | 301 | 0 | 67.69 % | 74.14 % | 3.4 | 100 % |
| ZIP downloads | 794 | 15 | 117 | 14 | 83.38 % | 84.98 % | 0.5 | 99.87 % |
| Database | 3,756 | 225 | 622 | 9 | 77.45 % | 82.38 % | 31.9 | 79.20 % |

The core gate's score barely moved. The planner and ZIP gates gave the same figures in two
runs on tmpfs and one on ext4, and the apply gate the same figures in two runs on tmpfs. With
the tests' temporary files on ext4, as on GitHub's runners without the tmpfs step, the file
restore gate scored 73.63 % and 77.92 % in 55.5 minutes, and the apply gate 67.61 % and
74.05 % in 15.6 minutes. Between the runs 21 mutants of the file restore gate and 6 of the
apply gate went from killed to escaped or back, two of them between the two runs on tmpfs.
Their tests take a path that depends on how long a step takes, such as a time-sliced
directory listing or the deadline of a planning tick, not on the file system's semantics. So
no floor is above the score over the mutants that every run killed: 73.29 % and 77.53 % for
the file restore gate, 67.45 % and 73.88 % for the apply gate.

The database gate scored 77.45 % and 82.38 % in the run in the table and 77.64 % and 82.58 % in
a second one. 79 mutants were killed in one run and not the other, 78 of them by
`DatabaseExchangeTest`: the exchange checks the other sessions of its account on the server,
and Infection's processes share one server and account, so such a test can fail through
another process whatever the mutation. The floors are the 76.49 % and 81.37 % over the
mutants that both runs killed, rounded down to a tenth; the true scores may be lower still.
A database account per process, or serial runs of these tests, would settle that.

Infection gives each mutant a timeout of five seconds plus five times what its covering test
classes took in the initial run, at most the configured timeout; a mutant that runs into it
counts as killed. It skips a mutant without running it when the quickest test class that
covers it took at least the configured timeout in the initial run, which collects coverage and
is slower. With 30 s that left all 255 mutants of `Restore\SnapshotBrowser` unmeasured: its
tests take about 45 s with coverage and 6 s without. The file restore gate now allows 120 s
and the apply gate 60 s, and no gate skipped a mutant on 2026-11-23. A run on a loaded machine
still differs: on 2026-11-20 a run of the database gate during other test runs counted 530
timeouts instead of 8. One mutant of the ZIP writer writes its checkpoint files into the
working directory, and so do mutants of the file restore gate; delete `.head`, `.names` and
`.state` there after a local run.

The escaped mutants by class, the work of a later package:

- Core: `Database\Restore\CreateStatement` 77, `Database\Restore\TableFile` 63,
  `Format\Compression` 44, `Engine\Forget\ForgetRun` 35, `Database\Restore\Session` 27,
  `Database\Restore\Manifest` 24, `Format\Index\IndexSet` 22, `Format\ZstdFrame` 21,
  `Engine\Forget\Forget` 20, `Crypto\Scrypt` 16, `Format\Index\IndexFile` 13 and 50 in
  thirteen other classes.
- Planner: `ApplyPlan` 81, `SiteLayout` 1.
- File restore: `FileChange` 181, `Staging` 103, `DiskFilePlan` 86, `DirectorySpellings` 60,
  `ApplyManifest` 56, `FileMaintenance` 53, `LiveSpellings` 44, `ChunkDigest` 28,
  `SnapshotBrowser` 25, `FileVerification` 17, `FileSpace` 11, `StagedRestore` 2.
- File apply jobs: `FileChanges` 134, `PlanBuild` 77, `FilePlans` 74, `ApplyJournal` 16.
- ZIP downloads: `ZipWriter` 87, `ZipRecords` 29, `Crc32` 1. The last is equivalent: a 33rd
  row of the matrix in `Crc32::power()`, which no 32-bit vector reads.
- Database: `Jobs\Apply\DatabaseChanges` 117, `Jobs\Apply\DatabaseGeneration` 66,
  `Jobs\Apply\DatabaseReview` 47, and in `Database\Restore` `CreateStatement` 57,
  `SwapCatalog` 40, `RehearsalReport` 37, `WordPressState` 37, `TableExchange` 32, `Manifest`
  31, `OwnedTables` 31, `TableFile` 29, `Ledger` 20, `TableImporter` 18, `WriteBarrier` 15,
  `ExchangePlan` 12, `InsertStatement` 9, `Session` 9, `SqlLexer` 7, `MysqliConnection` 6,
  `SessionDrain` 2.

The core job runs with ext-zstd since 2026-11-22, so the native decompression of format 2 is
mutated and tested too. With it, 78 mutants of `Format\Compression` are covered and 44 of
them escape; the covered MSI of the core fell from 91.21 % (4,210 covered mutants, the CI run
of `acaecdc`) to 90.39 % (4,285). The run takes about 40 minutes on a runner. The core and the
four restore gates run on every push to `main`, and on every pull request that changes a file
their result can depend on (`.github/actions/mutation-scope`): for the core anything under
`src/Crypto`, `src/Format`, `src/Engine`, `src/Support`, `src/Exception`, `src/Backend`,
`src/Storage`, `src/Secrets`, `src/Database`, `src/Restore`, `src/Jobs` (whose classes every
mutant process loads first) or `tests/`, or one of `infection.json5.dist`, `composer.json`,
`composer.lock`, `phpunit.xml.dist`, `bin/install-test-tools.sh`, `bin/mutation-gate.sh`, the
actions `mutation-scope` and `mutation-tmpdir` and `.github/workflows/ci.yml`; for the restore
gates anything under `src/` or `tests/`, their configurations and the same tool files. The
list is wider than the mutated code on purpose, and
`tests/Unit/Tooling/MutationJobScopeTest.php` checks that it holds every file that the job's
steps, gates, PHPUnit configurations and preloaded classes name. On any other pull request a
job says in a notice that it skipped the run, and ends green, so that it can stay a required
check. When the job cannot list the files of a pull request, or cannot match them against the
list, it runs. Each job checks for a nonempty report and uploads its logs.

The database gate needs a server and takes longer than a pull request should wait (31.9
minutes with 8 threads locally, so presumably more than an hour on a runner with four). It runs
in `database-mutation.yml` on every push to `main`, on pull requests that change `src/`,
`tests/` or a file the job reads (its configuration, `phpunit.xml.dist`, the scripts it runs,
Composer's files and the workflow; the same test checks that list), and on request. It is not
a required check of a pull request; the release gate requires a successful run for the release
commit (§7).

The [built-in fuzzer](adr/0028-built-in-fuzzer.md) exercises 26 parsers. It covers
repository metadata, pack framing and decryption, REST listings, persisted listings and
chunks, Go quoting, timestamps, durations and IDs. Restore targets cover selections,
snapshot paths, manifest locators, site manifests, and SQL dump schemas, rows and
manifests. The unit suite runs 2,000 deterministic inputs per target. The nightly
workflow runs each target for two minutes. It saves each finding with a replay command
so that a reproducible input can be added to `tests/Fixtures/fuzz/<target>/`. The job's name
states the seconds per target, and a run seeds the fuzzer with its run ID, so re-running a
run that found a crash finds it again. The release gate requires a run of at least 120
seconds per target for the release commit, and no run of that commit with a finding (§7).

### 5.7 Live smoke tests

The private smoke workflow runs on Monday at 04:43 UTC and on manual dispatch from
`main`. Its first job starts two disposable TLS rest-server 0.14.0 instances with
`--private-repos` and self-signed certificates, one with append-only access. Using the
plugin's core it creates a backup under a lock, checks v2 listing sizes and ranged pack
bytes, restores the canary and compares its contents. It confirms that deleting its own
snapshot fails on the append-only server. A second append-only run verifies earlier
test snapshots can remain in place. These checks have passed locally; the workflow had not run
yet on 2026-11-22.

The hosted job runs only after the local job passes and the operator enables
`BFR_LIVE_SMOKE_ENABLED` as a repository variable. Configure the protected
`live-smoke` environment with the `BFR_LIVE_REPOSITORIES` secret. Each provider needs
a pair of dedicated, initialized repositories and the expected authenticated repository
IDs. The job removes only objects it created in the normal fixture and leaves the
append-only history in place. The release gate requires a run for the release commit whose
hosted job succeeded, not skipped (§7): dispatch the workflow on `main` before tagging. On
2026-11-20 the same hosted command passed directly on the owner's normal and append-only
BorgBase fixtures, in a manual run that no workflow recorded. [Operator setup](../tests/Live/README.md) · [Server evidence](adr/0025-repository-servers.md)

### 5.8 Checks on the package

`bin/build-package.sh` builds what would ship into `build/backups-for-restic/`: the files
that Git tracks, without the paths in `.distignore`, with the modes 0644 and 0755. A file
that Git does not track cannot reach the package. Two CI jobs work on that directory.

**Plugin Check.** The scanner of the WordPress.org review team runs through WP-CLI in a test
site of `bin/test-site.sh` (`.github/actions/check-package`), with the package installed as a
copy. Every part is pinned and checked: WordPress 7.1.5 by the checksum in `bin/test-site.sh`,
WP-CLI 2.12.0 by the checksum in `bin/install-test-tools.sh`, and Plugin Check 2.2.0 by a
digest of its 3,039 files, recorded after comparing each with the checksums that WordPress.org
publishes for that version. Until 2026-11-22 the job used `wordpress/plugin-check-action`,
which installs the latest `@wordpress/env` from npm and the latest WordPress and Plugin Check,
unverified. A new version of each is a reviewed change now, as for restic. Errors fail the
job, warnings are listed as annotations (`report.py` beside the action). The first run, with Plugin Check
2.2.0 on 2026-11-10, reported 63 errors and 2 warnings. Two findings were fixed in the
plugin. `readme.txt` said `Stable tag: trunk`; it now names the version of the plugin
header, and the scanner reports it when the two differ. And the `ABSPATH` guard of
`WordPress\Container` stood after 39 imports, where the scanner does not look for it; it
stands before the imports now. The workflow sets aside the rest, with the reasons beside
the settings:

- Twelve codes of `WordPress.WP.AlternativeFunctions`, 59 findings: `fopen()`, `fread()`,
  `fwrite()`, `fclose()`, `mkdir()`, `rmdir()`, `chmod()`, `touch()`, `is_writable()`,
  `rename()`, `unlink()` and `parse_url()` in the WordPress-free core, 27 of them in
  `Engine` and 6 in `Crypto`. The core must not call WordPress
  ([ADR 0029](adr/0029-wordpress-free-core.md)); `phpcs.xml.dist` exempts the same
  namespaces from the same sniff and keeps it for `src/WordPress` (§3). The codes are
  ignored one by one, so a native function of another kind is reported.
- `src/Support/GoQuote.php` is left out of the scan. The scanner reports `mb_chr()` and
  `mb_ord()` there as functions that need WordPress 7.1, while they are PHP's own, from
  the mbstring extension that the plugin requires and the Doctor checks.

Three calls carry individual annotations. `WordPress\Cron` requests a finite grace period
to checkpoint the worker and release its lock. `Restore\FileChange` preserves the reviewed
group on a temporary file before renaming it into place. `WordPress\WpdbConnection`
executes SQL assembled by the dump builders, which quote identifiers, hex-encode string
literals and validate every numeric seek key, including keys read from a changing table.
On 2026-11-20, Plugin Check 2.2.0 reported zero errors and zero warnings on the installed
0.3.0 release candidate with the workflow settings above. The FAQ in `readme.txt` explains
direct filesystem access for directory reviewers.

**Translation template.** `composer pot` builds the package and runs `wp i18n make-pot` on
it. The result, `languages/backups-for-restic.pot`, holds the strings of the PHP files and
of the admin script, 395 of them on 2026-11-10. The CI job uploads it as an artifact. It
fails when WP-CLI reports an error or a warning: WP-CLI warns, for example, about one
string with two different translator comments, which the first run found in
`WordPress\SiteHealth`. It also fails when one of three known strings is missing: a string
that only `admin.js` has, one that only `src/` has, and the translator comment of a
JavaScript string. They guard against a file that make-pot cannot parse, which it leaves
out without failing. The template is neither committed nor shipped: translate.wordpress.org
reads the strings from the released code and delivers the language packs, and a committed
copy would only go stale.

## 6. Continuous integration

| Workflow | Trigger | Jobs |
| --- | --- | --- |
| `ci.yml` | push to `main`, pull request | PHPCS and `node --check assets/js/admin.js`; ESLint for `assets/js` and the browser tests; PHPStan with the project's three rules; Plugin Check on the built package with the release rehearsal and the tests of the release gate, and the translation template built from the package (§5.8); on pull requests the check that the commits are signed off; unit tests on PHP 8.3, 8.4, 8.5 and 8.6 with CLI OPcache and JIT disabled, and once more on PHP 8.3 with ext-zstd; the database suite against MySQL and MariaDB; the core mutation gate and the four restore gates (§5.6) |
| `conformance.yml` | push to `main` and pull requests that change `src/`, `tests/`, `bin/install-test-tools.sh`, `bin/bench.php`, `composer.lock` or the workflow; nightly; manual dispatch | oracle suite with the restic CLI and rest-server; the format 2 tests again with `ext-zstd`; benchmarks with gates |
| `database-mutation.yml` | push to `main`, pull requests that change `src/`, `tests/` or a file the job reads, manual dispatch | the database mutation gate against MySQL (§5.6) |
| `fuzz.yml` | nightly, manual dispatch | fuzzing per parser, two minutes each unless the dispatch says otherwise; the job's name states the seconds; findings uploaded as an artifact |
| `integration.yml` | push to `main`, pull request, nightly, manual dispatch | three groups of jobs, each on a site of its own. **WordPress compatibility** runs 7.1.5, 7.0.9 and 6.7.12 on both PHP 8.3 and 8.5, with a 96 MiB job on 6.7.12. It also runs WordPress 7.1.5 on PHP 8.6 with 128 MiB. That job lints every packaged PHP file with all diagnostics enabled and fails on deprecations. Three combinations repeat the large fixture nightly and on request; their job names then end in "(acceptance size)". Records with `strace` and core dumps how the server's PHP processes ended, and fails when the PHP or WordPress log holds an entry from the plugin's files (§5.3). **WordPress memory**, a matrix of three sizes (500,000, 1,000,000 and 2,000,000 blobs) that runs with every trigger: `bin/bench-wordpress-memory.php` builds a repository history of that size, then drives a complete backup, check and prune job of the plugin, every tick in a new PHP process with `memory_limit=128M`, `max_execution_time=30`, `set_time_limit()` disabled and a full WordPress. The site is installed with `BFR_DISABLE_WP_CRON=1`, so that no request starts a tick that is not measured; the script refuses any other site. A size passes if no tick crashes, changes these limits or reserves more than 128 MiB, every job ends within 600 ticks and leaves no repository lock, a prune that succeeds deletes a pack, a check that succeeds finds no error, and `restic check --read-data` accepts the repository before and after. At 500,000 blobs all three jobs must succeed; at the larger sizes each must succeed or fail with `index_too_large`. The reports of every tick and the logs are uploaded as the artifact `wordpress-memory-<blobs>`, also when the job fails ([measurements](benchmarks/2026-11-12-job-memory.md)). **Admin page in a browser** runs the browser tests (§5.5) and the same log check. |
| `live-servers.yml` | weekly and manual dispatch on main, manual pre-release verification | disposable TLS rest-server pair and opt-in operator-configured repository pairs (§5.7) |
| `codeql.yml` | every push to `main`, admin script changes in pull requests, weekly, manual dispatch | CodeQL against the shipped JavaScript in `assets/js` |
| `deploy-wordpress-org.yml` | a pushed tag `vX.Y.Z`; a retry by re-running the tag's run, or by a dispatch on the tag | verification of the tag, the repository's protections and the test results of the tagged commit, the versions and changelogs, the package and Plugin Check (§7); the owner's approval; SVN deploy (`trunk`, `tags/x.y.z`, `assets/`); post-deploy diff; GitHub release notes, once the tag is shown to name the deployed commit |

**Runs on `main` never cancel each other.** Pull requests cancel their superseded runs, but a
push to `main` cancels neither the nightly runs nor the runs of the commit before it: the
concurrency group of a run on `main` is the run itself. Until 2026-11-22 a push could cancel
the nightly acceptance run and the release commit's own runs, and the release gate then
refused the release.

**A skip cannot turn a job green.** Every PHPUnit configuration fails a run that selected no
test (`failOnEmptyTestSuite`). After each PHPUnit run CI compares the skipped tests of its
JUnit log with a list in `tests/Skips` (`bin/check-skipped-tests.php`): the unit tests without
ext-zstd (`unit.txt`) and with it (`unit-zstd.txt`), the database suite on MySQL
(`database-mysql.txt`, empty) and on MariaDB (`database-mariadb.txt`), the two conformance
jobs, and the integration suite (`integration.txt`, empty). A skip that is not listed fails the
job, and so does a listed test that ran or no longer exists. A class that skips in
`setUpBeforeClass()`, as the database and integration classes do without their server or site,
runs none of its tests and fails the job whatever the list says; PHPUnit's own
`failOnSkipped` counts only tests that skip one by one and would miss it. Change a list only
together with the test, and say why in the pull request.

**What is pinned.** Actions are pinned to commit SHAs. Composer is 2.11.2, checked by setup-php
against the SHA-256 of its PHAR; the PHAR's OpenPGP signature by Composer's release key
`161D FBE3 4288 9F01 DDAC 4E61 CBB3 D576 F2A0 946F` was checked when the pin was recorded. The
PHPStan PHAR is checked by Composer against the SHA-1 in `composer.json` and `composer.lock`,
the only checksum Composer offers for a package repository; its signature by PHPStan's release
key `CA7C 2C7A 30C8 E8E1 274A 8476 51C6 7305 FFC2 E5C0` was checked when it was recorded, and
the release asset equals the PHAR in PHPStan's repository at tag 2.3.5. The MySQL and MariaDB
service images are pinned by digest (MySQL 8.4.13, MariaDB 11.4.13). WordPress, WP-CLI, Plugin
Check, restic, rest-server and Infection are checked against checksums in the repository.
Dependabot proposes updates of the actions and of the Composer and npm lock files every week;
`package.json` names exact versions, so a new version of ESLint or of its WordPress plugin is a
change by hand, like a new PHPStan, Composer, image, Plugin Check or restic. Not pinned: the
runner image and the packages that jobs install with apt (Subversion, strace, gdb, Apache,
nginx), the patch release of PHP that setup-php installs, the patch release of Node.js 22, the
browser that Playwright downloads for its pinned version, and the CodeQL bundle that
`github/codeql-action` selects. CI installs the npm packages with `npm ci --ignore-scripts`, so
only what the lock file names is installed and no package runs a script while it is installed.

Whether Dependabot's security updates, which can replace a vulnerable package in a lock file,
are switched on is a setting of the repository that no file here records. The quality jobs use
read-only repository access. CodeQL publishes its analysis to code scanning. The release
workflow uses its protected environment for SVN and a separate write token for release notes.

Checks that must be green before a merge: lint, ESLint, analyse, unit, database and mutation
testing (core and the four restore gates), Plugin Check, the translation template and the
sign-off check, and the WordPress compatibility, memory and browser jobs of `integration.yml`;
conformance when `Crypto`, `Format`, `Backend` or `Engine` are touched. GitHub enforces this
only through the ruleset on `main` of §7.1: until the owner has created it, these checks are
advice, and the release workflow refuses to deploy. Conformance cannot be a required check,
because its path filter leaves a pull request without its result; the release gate requires it
for the release commit instead, as it does the database mutation gate, which runs only on some
pull requests. A `readme.txt` that names another version than the plugin header fails Plugin
Check, which is the consistency check planned earlier; the check for a stale template has no
subject, because the template is not committed.

The job "Commits are signed off" runs on pull requests. It fails when a commit between the
base and the head of the pull request, merge commits included, has no `Signed-off-by`
trailer with a value. Dependabot cannot sign off, so the job does not run on a pull request
that the account `dependabot[bot]` opened. GitHub sets that account; a name in a commit,
which anybody can write, exempts nothing. A commit that someone else pushes onto such a
pull request is not checked either; whoever merges sees it.

## 7. Release process (WordPress.org)

WordPress.org is the only place users obtain the plugin
([ADR 0017](adr/0017-distribution-via-wordpress-org.md)). Git tags are the source of truth
for code; the SVN repository on WordPress.org is the distribution artefact, written only by
CI.

The package builder produces the WordPress.org plugin tree. Run `bin/build-package.sh --zip`
for an installable archive. CI runs Plugin Check against the packaged files. Directory artwork
lives in `.wordpress-org/` and is excluded from the plugin ZIP. The owner approves the
listing artwork, signs the release tag and authorizes deployment to WordPress.org.

A pushed tag `vX.Y.Z` starts `deploy-wordpress-org.yml`; a release candidate such as
`v0.3.0-rc.1` starts nothing. Before the owner is asked to approve, its check job verifies,
with the copies of the scripts on `main`:

1. **The tag** (`bin/verify-release-tag.sh`): a stable version, annotated, its tag object
   named as the tag, on a commit that is on `main`, and signed by the key in the repository
   variable `RELEASE_SIGNING_PUBLIC_KEY`, whose primary fingerprint must be pinned in
   `.github/release-signing-key.txt` as committed on `main`. Replacing the variable alone
   therefore authorises no other key; a new key needs a reviewed pull request. A signature by a
   signing subkey of the pinned key counts; an expired or revoked key does not. The script
   reads GnuPG's status itself and requires `GOODSIG`: Git 2.56 on the runner accepts a
   signature whose key has expired since, which Git 2.53 refused.
2. **The protections** (`bin/check-release-gates.py protections`): an active ruleset on
   `main` that requires pull requests and status checks and blocks force pushes and deletion;
   an active tag ruleset that keeps the release tag from being moved or deleted; and the
   `wordpress-org` environment with the owner as required reviewer, without administrator
   bypass, deploying only tags that match `v*`. The job's own token reads these: rulesets
   through the repository's metadata, the environment and its deployment policies with
   `actions: read`. GitHub answers 404 for an environment that does not exist, and creates
   it without protection on its first use; the check fails then too.
3. **The test results** (`bin/check-release-gates.py evidence`): for the exact commit on
   `main`, every run of `ci.yml`, `conformance.yml`, `integration.yml`,
   `database-mutation.yml`, `live-servers.yml`, `fuzz.yml` and `codeql.yml` ended in
   success, and among them are an integration run of the acceptance size, a live smoke whose
   hosted job succeeded, a fuzz run of at least 120 seconds per target and a CodeQL run;
   code scanning shows no open alert on `main`. A failed run is not outweighed by a later
   successful one: re-run the failed run if the failure was not caused by the commit. "For
   this commit" means the run's head commit and branch, its event and its repository; results
   of a commit do not age, so no time limit applies.
4. **Versions and changelogs** (`bin/prepare-wordpress-org.py --check-only`): the plugin
   header, `Stable tag`, `Plugin::VERSION`, the newest entry of `CHANGELOG.md` and of the
   readme's changelog name the release, and `CHANGELOG.md` has no unreleased entries left.
5. **The package**: built from the tagged commit, checked by Plugin Check.

The job lists every requirement that is not met. Then the deploy job waits for the owner's
approval of the protected environment, rebuilds the ZIP and compares its SHA-256 with the
archive that passed Plugin Check. The SVN transaction publishes the package and listing
together. Repeating a successful run verifies the existing tag. A different tag with a lower
version is refused. The notes job publishes the GitHub release only after the API shows that
the tag still names the tag object and the commit that were verified and deployed.

Before tagging, make sure the release commit has the results that only a schedule or a
dispatch produces: dispatch **Integration** on `main` with *acceptance* (or wait for the
nightly run), **Private repository smoke** and, unless the nightly runs have covered the
commit, **Fuzzing** and, when its path filter left the commit out, **Conformance**. To retry a deployment, re-run the tag's own run; GitHub allows that for 30 days.
After that, dispatch the workflow on the tag itself ("Use workflow from: Tags"); a dispatch on
a branch is refused, and the environment would refuse it too.
[GitHub workflow-run API](https://docs.github.com/en/rest/actions/workflow-runs#list-workflow-runs-for-a-workflow) ·
[GitHub deployment environments](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments)

`tests/Release/test-wordpress-org.sh` rehearses signing and publication with temporary local
Git and SVN repositories, including changes and deletions between releases, and checks every
refusal by its message: release candidates, a missing, unsigned or lightweight tag, a tag
object stored under another name, a commit off `main`, an unpinned, expired or revoked key, a
replaced key variable, a missing or empty fingerprint file, and versions or changelogs that do
not name the release. `tests/Release/test_release_gates.py` checks the release gate against
recorded GitHub responses. Both run in CI. They need Git, GnuPG, Subversion, rsync and Python
3. [WordPress SVN handbook](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)

What the workflow cannot verify, and the residual risk: GitHub returns the bypass list of a
ruleset only to a caller with write access to it, which the workflow's token never has; the
token cannot list secrets; and nothing on GitHub shows WordPress.org's settings. The owner
checks these by hand (§7.1). Someone who holds the owner's GitHub credentials with
administrator rights can change the rulesets, the environment, the variable and, through a
pull request they merge themselves, the pinned fingerprint, and can approve the deployment.
WordPress.org's release confirmation is the factor outside GitHub: each new release waits for
a link that WordPress.org e-mails to the plugin's committers.

### 7.1 Settings the owner makes

The release workflow refuses to deploy until the first three are in place. They are settings of
the repository, not files, so a pull request cannot make them.

1. **Ruleset for `main`** (Settings → Rules → Rulesets → New branch ruleset): target the
   default branch; enforcement *Active*; bypass list empty. Rules: *Restrict deletions*,
   *Block force pushes*, *Require a pull request before merging* (no approval required: the
   owner is the only maintainer, and the pull request is the record), and *Require status
   checks to pass* with the jobs listed in §6, as GitHub names them (for example "Unit tests
   (PHP 8.3)"). Merge through GitHub (the merge button or `gh pr merge`), because a merge
   pushed from a local clone is refused.
2. **Ruleset for release tags** (New tag ruleset): include the pattern `v*`; enforcement
   *Active*; bypass list empty. Rules: *Restrict updates* and *Restrict deletions*. A
   published release tag can then neither be moved nor deleted, not even by the owner, without
   first changing the ruleset.
3. **Environment `wordpress-org`** (Settings → Environments), before the first tag: required
   reviewer the owner, *Prevent self-review* off (the owner approves the releases they tag);
   *Allow administrators to bypass configured protection rules* off; deployment branches and
   tags: *Selected branches and tags*, one rule of type *Tag* with the pattern `v*` and no
   branch rule. Store the WordPress.org user name and a dedicated SVN password (generated in
   the WordPress.org profile, never the account password) as the environment secrets
   `WORDPRESS_ORG_SVN_USERNAME` and `WORDPRESS_ORG_SVN_PASSWORD`, never as repository secrets,
   which every workflow on every branch could read. Rotate the password yearly and on
   suspicion.
4. **Release key**: add the primary key's fingerprint to `.github/release-signing-key.txt` in
   a pull request, and set the repository variable `RELEASE_SIGNING_PUBLIC_KEY` to the
   ASCII-armored public key with its signing subkeys. A renewed expiry date or a new signing
   subkey changes only the variable; another key needs the pull request.
5. **Live smoke**: the repository variable `BFR_LIVE_SMOKE_ENABLED` set to `true`, and the
   `live-smoke` environment with the secret `BFR_LIVE_REPOSITORIES` ([operator
   setup](../tests/Live/README.md)). Without them the hosted job is skipped and the release
   gate refuses.
6. **Tokens on development machines**: fine-grained personal access tokens for this
   repository only, with the permissions daily work needs (for example *Contents* and *Pull
   requests* write, *Actions* read) and no write access to *Administration*, *Actions*,
   *Deployments*, *Environments*, *Secrets*, *Variables* or *Workflows*. No token with the
   classic `repo` scope: the one that `gh auth login` obtains in the browser has it, so give
   `gh auth login --with-token` a fine-grained token instead. A token with that scope can
   replace variables, push to `main` and approve deployments through the API. Change rulesets, environments, variables and secrets, and approve deployments, in the
   browser, with two-factor authentication.
7. **WordPress.org release confirmation**: once the plugin is in the directory, turn on
   *Release Confirmation* for it, an opt-in setting of the directory. A new tag in SVN is then
   released only after a committer confirms it on the Release Management dashboard through a
   link that WordPress.org e-mails to all committers; committers must be able to receive
   e-mail directly. The handbook does not describe how to turn it off again, so treat it as
   permanent. After each deployment, confirm the release from that e-mail; an e-mail that no
   deployment of yours explains means someone else published a tag.
   [Release confirmation emails](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/)
8. **By hand before each release**, because the workflow cannot read them: the bypass lists of
   both rulesets are empty, no repository secret named `WORDPRESS_ORG_SVN_*` exists, and
   release confirmation is on.

### 7.2 Release steps

1. Update `CHANGELOG.md` and the `== Changelog ==` section of `readme.txt`, moving the
   unreleased entries to the new version (the release workflow refuses changelogs that do
   not start with it); bump the version
   in the plugin header, `readme.txt` (`Stable tag`) and `Plugin::VERSION`; verify `Tested up
   to`, `Requires PHP`, `Requires at least`; attach the benchmark results. Between releases
   the three places name the same prerelease version, for example `0.3.0-rc.1`.
2. Pull request "Release x.y.z" with the completed release checklist (self-review, live
   smoke tests green, benchmarks attached); CI runs Plugin Check on the built package and
   the full conformance suite.
3. Signed tag `vx.y.z` on `main`. `deploy-wordpress-org.yml` checks what §7 lists, builds the
   package with `.distignore`, waits for the manual approval of the protected environment,
   then commits
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
textbook code tested against reference implementations) and in code comments. An answer
about direct filesystem access is in the plugin FAQ. The release workflow retains the
project's documented scanner exceptions for native core file functions (§5.8). The slug
`backups-for-restic` ([ADR 0015](adr/0015-naming-and-license.md)) becomes permanent at
approval. Pre-release builds are never published. Testers copy the directory that
`bin/build-package.sh` builds into `wp-content/plugins/`, or link a checkout of the
repository there; the plugin needs no build step and no `composer install` to run, because
`src/autoload.php` loads its classes.

## 8. Documentation standards

- `README.md` (GitHub): what, why, requirements, links; installation points to WordPress.org.
- `readme.txt` (WordPress.org): description, installation, FAQ with review-relevant answers,
  **External services** section (the repository server the user configures, what data is
  sent and when, the user's own rest-server, and for the BorgBase preset its address form
  and the links to its terms and privacy policy), changelog. Reviewed like code. The
  screenshots section captions the packaged UI as shown in the listing assets.
- `docs/`: architecture, security, development, roadmap, disaster recovery, appendix, ADRs,
  benchmarks. Updated in the same pull request as the code they describe.
- Format documentation: the class headers in `Format` and `Crypto` describe the byte layout
  or JSON form they implement and name the restic type they mirror where one exists (for
  example `data.Node`, `crypto.Key.Seal()`). They cite neither a section of restic's design
  document nor a restic version; the version that produced the test vectors is in the
  fixture path (`tests/Fixtures/restic-0.19.1/`). Adding those citations is not planned yet.
- User documentation (`docs/guides/`): running backups with a cron job of the hosting panel
  (cPanel, Plesk and others, as text) and running your own rest-server. The FAQ is in
  `readme.txt`. Panel screenshots, a hosting matrix and a page on what to expect from the
  first backup do not exist and are not planned yet.

## 9. Contribution workflow

Short-lived branches; conventional commit subjects; definition of done: code + tests + docs
+ changelog + green CI. The maintainer works through pull requests and the same checks as
everyone else; nobody pushes to `main` directly. Whether GitHub enforces that (branch
protection, required checks) is a setting of the repository that no file here records.

- The pull request template (`.github/pull_request_template.md`) asks what changes and why
  and for the security impact. Its checklist: tests, documents and decision records, the
  changelog, a green conformance run for changes to `Crypto`, `Format`, `Backend` or
  `Engine`, the cooling-off period for changes to `Crypto`, `Format` or `Engine`, the
  self-review of §9.1 for security-relevant changes, the sign-off of every commit, and no
  personal data.
- Commits are signed off under the Developer Certificate of Origin (`git commit -s`). A CI
  job checks the commits of a pull request for the trailer, except Dependabot's (§6).
- The issue template for bug reports (`.github/ISSUE_TEMPLATE/bug_report.md`) asks for the
  Doctor's output, from `wp backups-for-restic doctor` or **Backups → Environment**, and
  warns against posting a recovery kit or a password.
- CodeRabbit reviews every pull request with the instructions in `.coderabbit.yaml`; its
  comments are answered or resolved before merging
  ([ADR 0024](adr/0024-governance-and-quality-assurance.md)).

### 9.1 Self-review checklist for security-relevant changes

The project has no second reviewer
([ADR 0024](adr/0024-governance-and-quality-assurance.md)), so the author asks what a
reviewer would ask. A change is security-relevant when it touches secrets, cryptography or
the repository format, deletions in the repository, files the plugin writes, HTTP requests,
or what a REST route or a WP-CLI command allows. The author goes through this list before
the merge and writes the result under "Security impact" in the pull request: one sentence
for every point that applies, "not affected" for the others.

1. **Secrets.** No secret can reach a log line, a job file, an exception message, a REST
   response, a URL or an e-mail. A new call of `Secret::reveal()` is on the list of
   `SecretRevealRule`, and the pull request says why it is needed. A new kind of secret is
   added to the `Redactor`.
2. **Cryptography.** No new primitive, no new parameter and no changed order of operations
   without a decision record. The tag is verified before anything is decrypted, and the
   SHA-256 of a blob before its content is used. Random values come from `RandomSource`.
3. **Data from the server.** Everything the repository server returns is untrusted. Lengths,
   counts and names are checked before they size an allocation, bound a loop or become part
   of a path. A new or changed parser has a fuzz target in `tests/Fuzz/Targets.php`.
4. **Deleting and overwriting.** A new `DELETE` or overwrite in the repository happens only
   in a job that holds the lock it needs, never in retention mode `external`, and it stops
   on a 403. When a precondition cannot be verified, the code stops instead of going on. A
   test shows what is not deleted.
5. **Files.** The plugin writes files only inside the private directory, with owner-only
   permissions. A path that comes from the repository, a request or a setting is checked to
   stay inside its root before it is used. No password is written to disk in plain text,
   and backups never spool plaintext database content. Restore staging necessarily holds
   plaintext files under the protected, expiring directory of security §8.
6. **HTTP.** Requests go to the configured repository server only, over HTTPS with
   certificate verification, with the credentials in the `Authorization` header and never
   in the URL. The requests to the site itself (the tick chain, the loopback test and the
   exposure probe) stay the only others.
7. **WordPress surface.** Every REST route has a `permission_callback` that checks the
   capability, and a route that returns a secret, or restored content that can decrypt
   one, asks for the WordPress password again through the one shared check
   (`WordPress\PasswordConfirmation`, [security](03-security-and-threat-model.md) §5.5). Input is validated against
   `Settings\Schema` and output is escaped. WP-CLI restricts recovery-kit delivery to
   the authorised, audited `recovery-kit` command. Redirected output requires `--force`.
8. **Tests and gates.** A test fails without the change. For `Crypto`, `Format`, `Backend`
   or `Engine` the conformance run is green; for `Crypto`, `Format`, `Engine\Forget` and the
   database import filter the
   covered mutation score stays at or above 90 %, and no other mutation gate falls below its
   floor (§5.6). Documents and decision records say what changed.
9. **Cooling-off.** A change to `Crypto`, `Format` or `Engine` is merged no earlier than 24
   hours after the last commit that touches these namespaces, and the author reads the
   whole diff once more before merging.

## 10. Licensing and attribution

Plugin GPL-2.0-or-later (`License` / `License URI` in `readme.txt`, header, `LICENSE`).
restic (BSD-2-Clause) and `rest-server` are development dependencies only; trademarks
acknowledged in `readme.txt`. The format implementation is original work that follows a
published specification; it copies no restic source code.
