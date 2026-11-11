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
├── .wordpress-org/                  (M3) listing assets synced to SVN `assets/`: banners, icon, screenshots
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
└── .github/                         workflows ci.yml, conformance.yml, integration.yml, fuzz.yml; dependabot.yml; pull_request_template.md; ISSUE_TEMPLATE/bug_report.md; (M3) live-servers.yml, codeql.yml; (release) deploy-wordpress-org.yml
```

Entries marked (M3) are not in the repository yet; they are planned for Milestone 3
([roadmap](05-roadmap.md)): `.wordpress-org/` and the workflows `live-servers.yml` and
`codeql.yml`. `deploy-wordpress-org.yml` comes with the
first release.

## 2. Toolchain (development only)

| Tool | Purpose |
| --- | --- |
| PHP 8.3–8.5, 8.6 once released (CI matrix; 8.3 without JIT is the performance baseline) | runtime floor 8.3 ([ADR 0003](adr/0003-version-floors.md)) |
| Composer 2 | dev dependencies and scripts; no `vendor/` in the release |
| `wp-coding-standards/wpcs` 3, PHP_CodeSniffer | WordPress Coding Standards |
| `phpstan/phpstan` 2 + `szepeviktor/phpstan-wordpress` + strict rules | static analysis at level `max`, with three rules of the project: the WordPress-free core, the dependencies of the engine and the `reveal()` allow-list (§4) |
| `phpunit/phpunit` 12 | unit, database, conformance and integration tests |
| Node.js 22 with npm | runs ESLint; `node --check assets/js/admin.js` stays as a syntax check that needs no packages |
| ESLint 10 with `@wordpress/eslint-plugin` 28 (two packages with exact versions in `package.json`; `package-lock.json` pins the about 400 packages they bring) | lints `assets/js` with the rulesets `esnext`, `custom` and `i18n` (§3); development only, nothing from npm ships |
| Infection 0.35 (PHAR, pinned and checksum-verified by `bin/install-test-tools.sh`) with pcov | mutation testing of `Crypto`, `Format` and `Engine\Forget`; merge gate ([ADR 0024](adr/0024-governance-and-quality-assurance.md)) |
| Built-in fuzzer (`bin/fuzz.php`, `tests/Fuzz/`) | structure-aware fuzzing of every parser that reads server data ([ADR 0028](adr/0028-built-in-fuzzer.md)) |
| **restic CLI** 0.19.1, 0.18.1 and 0.14.0 (upstream releases, checksum-verified by `bin/install-test-tools.sh`) | conformance oracle ([ADR 0023](adr/0023-restic-cli-as-conformance-oracle.md)); never shipped |
| **rest-server** 0.14.0 (upstream release, checksum-verified), with TLS, normal, `--append-only` and `--max-size` | local repository server for conformance and integration tests |
| WordPress 7.1.4 (pinned and checksum-verified by `bin/test-site.sh`) | the real WordPress of the integration suite, served by PHP's built-in server. The project uses no WordPress test library, no function mocking and no `@wordpress/env`: the adapters are tested against a running WordPress or not at all ([ADR 0029](adr/0029-wordpress-free-core.md)) |
| Plugin Check through `wordpress/plugin-check-action` (pinned SHA) | the WordPress.org review team's scanner, in CI on the built package (§5.8). The action installs the current Plugin Check and the current WordPress, so a new check can fail a pull request that did not cause it |
| WP-CLI 2.12.0 (PHAR, pinned and checksum-verified by `bin/install-test-tools.sh wp-cli`) | `wp i18n make-pot` for the translation template (§5.8); it needs no WordPress for that |
| `@playwright/test` 1.63.0 (pinned in `package.json`) | the browser tests of §5.5; its browser is installed in CI by the same version |
| (M3) CodeQL | JavaScript analysis; not set up yet |
| (release) `10up/action-wordpress-plugin-deploy` (pinned SHA) | SVN deployment of tagged releases |

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
| `Backend\RestBackend` | request shapes (method, path, headers, Range), status mapping (400/401/403/404/507), a retried read, no credentials in URLs. Retries of uploads, the limit of five attempts and the back-off have no test |
| `Jobs\Runner`, `JobType`, `JobError`, `TickReport`, `RetentionGuard` | steps in order across ticks; cleanup after success, failure and cancellation; back-off of retryable errors; lease and runner lock; one active job; redacted job files; the error codes; which ticks chain; the switching rules of the retention modes |
| `Secrets`, `Settings`, `Storage`, `Support`, `Doctor`, `Scheduling\SchedulePolicy`, `Notifications\Notifier`, `Connection\ServerProfile` | their own test classes under `tests/Unit/` |
| Fuzz targets | 2000 deterministic inputs per parser and the saved regression inputs (§5.6) |

Not unit tested: `Support\Redactor` has no test class of its own (the runner test and the
job test check that a plain secret value is masked); `Backup\PlanFactory` with its roots and
excludes and `Database\Dump` are covered by the conformance and database suites (§5.2,
§5.3), not by unit tests; no test loads a class of `src/WordPress` (§5.3). Tests for
`Database\Restore\Importer` and `Restore\Apply` arrive with those classes in Milestone 3.

### 5.2 Conformance suite (restic CLI + rest-server)

Defined in [ADR 0023](adr/0023-restic-cli-as-conformance-oracle.md): PHP-written
repositories pass `restic check --read-data` (restic 0.19.1, 0.18.1 and 0.14.0) and restore
byte-identically, including a 300 MB streamed database dump; repositories written by restic
0.19.1 and 0.14.0 are read, restored and deduplicated against by PHP; format 2 repositories
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
are re-recorded with `bin/record-vectors.sh` and `bin/record-forget-vectors.sh` when a new
restic version enters the matrix; the versions are pinned in `bin/install-test-tools.sh`,
and nothing picks up a new restic release by itself (planned for Milestone 3).

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
serves it with PHP's built-in server and several workers, `max_execution_time = 30`,
`memory_limit = 128M` and `set_time_limit()` disabled. A router script enforces "deny all"
`.htaccess` files as Apache would. Two notes on that server:

- The JIT is switched off (`opcache.jit=disable`). With PHP 8.3's tracing JIT, which
  `setup-php` turns on in CI, the server's processes died with SIGSEGV in backup ticks.
- A tick raises its own memory limit to `WP_MAX_MEMORY_LIMIT` (architecture §4.2). The test
  site's `wp-config.php` therefore sets that constant to 128M; without it ticks would run
  with 256M and the 128M of the acceptance criterion would not be in force.

The tests reach the site over HTTP, as a browser and a cron service do. Where an
administrator acts outside the plugin's own interface (deactivating or deleting it, creating
a user) they run PHP inside the site's WordPress on the command line, and for what only the
files show (a job's lease, its log) they read the private directory. `tests/Support/TestSite.php`
is that access. The classes share one site and one repository, so
`phpunit-integration.xml.dist` names the files in order. A class whose tool or site is not
installed is skipped:

- **`AccessTest`**: every route without credentials and as an editor; its list of routes is
  compared with what WordPress has registered, so a new route cannot go untested; the files
  of the private directory are not served.
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
  backup without any further tick; a wrong repository password and its repair; repository
  requests that verify certificates while a must-use plugin switches verification off for
  the site (`bin/test-site-hostile-filters.php`); a setup that is not completed, and a
  backup that does not run, while the private directory is served.
- **`ScheduleTest`**: the heartbeat, run through `wp-cron.php`, records the first slot,
  starts a backup whose slot has come and forget after it, prune and check on their slots,
  and nothing for manual schedules.
- **`AppendOnlyTest`**: the rest-server restarted in append-only mode and back: the switch
  to retention mode external without a deletion, backups that go on, the proposal to switch
  back, and no deletion until an administrator decides.
- **`CliTest`**: the WP-CLI commands with a real, pinned WP-CLI
  (`bin/install-test-tools.sh wp-cli`): `doctor`, `status`, `snapshots`, `backup`, `forget`,
  `prune` and `check` with `--wait`, `tick --until-idle`, a second job that is refused, and
  forget and prune that are refused in retention mode external.
- **`MultisiteTest`**: on a second WordPress that is a multisite network
  (`bin/test-site.sh install-multisite`, never served, reached through WP-CLI): activation
  is refused for the network and for one site, and a plugin that is active all the same
  registers no route, no cron event and no command, and shows its notice.
- **`UninstallTest`**: what the Plugins screen runs on "Delete" leaves no option, transient,
  cron event or file of the plugin, and the repository unchanged.

`BFR_SITE_FIXTURE_MB` and `BFR_DB_FIXTURE_MB` set the fixture sizes (64 and 32 by default);
the nightly CI run uses 2048 and 300. The tests that catch a backup in the middle add
256 or 512 MiB of random data of their own.

What the suite does not reach: Site Health, and the loopback chain as such, because the
tests request `wp-cron.php` themselves. The admin page itself has the browser tests of
§5.5.

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
on a WordPress that was just installed (`npm run test:e2e`; in CI the job "Admin page in a
browser" of `integration.yml`). The tests click and read what an administrator clicks and
reads, and send no request of their own. `tests/E2E/global-setup.js` starts a TLS
rest-server with a certificate of its own authority for them. They run one after the other
on one site, without retries:

- the setup wizard: the environment checks; a connection test that fails until the
  server's CA certificate is entered; a new repository and its recovery kit, which has to
  be acknowledged; the schedule; the first backup with its progress to "Finished
  successfully";
- the dashboard with the last backup, the repository and the recent jobs, and the snapshot
  list with that one snapshot;
- settings that are saved and still there after a reload, an invalid retention window that
  is refused with a message at its field, and the recovery kit, which needs the WordPress
  password;
- a backup of 1 GiB of new data that is cancelled from the dashboard while it runs.

Still to come with the restore of Milestone 3: snapshot browsing, single-file restore and
download, and the acceptance test of that milestone. Site Health has no browser test.

### 5.6 Mutation testing and fuzzing

Infection runs on `Crypto`, `Format` and `Engine\Forget`; the covered mutation score
indicator must stay at or above 90 % (`infection.json5.dist`). The run takes about 40
minutes. It runs on every push to `main`, and on every pull request that changes a file the
result can depend on: anything under `src/Crypto`, `src/Format`, `src/Engine`,
`src/Support`, `src/Exception`, `src/Backend`, `src/Storage`, `src/Secrets` or `tests/`,
or one of `infection.json5.dist`, `composer.json`, `composer.lock`, `phpunit.xml.dist`,
`bin/install-test-tools.sh` and `.github/workflows/ci.yml`. The list is wider than the
mutated code on purpose. On any other pull request the job says in a notice that it skipped
the run, and ends green, so that it can stay a required check. When the job cannot list the
files of a pull request, it runs.

The built-in
fuzzer ([ADR 0028](adr/0028-built-in-fuzzer.md)) drives every parser that reads server data,
with 17 targets: config, key file, master key, snapshot, lock, index, tree, the JSON decoder,
unpacked files, pack header and its length, the encryption envelope, the REST listing, Go
string quoting, RFC 3339 times, durations and ids. The unit suite runs 2000 deterministic
inputs per parser; `fuzz.yml` runs two minutes per parser every night. Every finding is
saved with a replay command and becomes a regression input in
`tests/Fixtures/fuzz/<target>/`. Both compensate for the missing second reviewer and
external audit ([ADR 0024](adr/0024-governance-and-quality-assurance.md)).

### 5.7 Live smoke tests

Not built yet. No workflow `live-servers.yml` exists and no test has run against a hosted
service: every server behaviour the suites assert was measured on a local rest-server
(§5.2, §5.3), and the answers of BorgBase are not yet measured
([ADR 0025](adr/0025-repository-servers.md)). The tests need an account at the hosted
service and are planned for Milestone 3. The design so far, not yet confirmed by the
owner:

`live-servers.yml` runs weekly, on manual dispatch and before every release. Against a
rest-server with TLS, a self-signed CA and `--private-repos` it runs init or connect, a
backup of a small fixture, the v2 listing, ranged reads, the append-only probe, lock
creation and deletion, a refused forget in append-only mode and a restore. Against two
BorgBase repositories (normal and append-only) it runs the same flow. Credentials are
repository secrets available only to scheduled and manually dispatched runs on `main`. A
hosted service's behaviour is verified this way instead of by vendor statements.

### 5.8 Checks on the package

`bin/build-package.sh` builds what would ship into `build/backups-for-restic/`: the files
that Git tracks, without the paths in `.distignore`, with the modes 0644 and 0755. A file
that Git does not track cannot reach the package. Two CI jobs work on that directory.

**Plugin Check.** The scanner of the WordPress.org review team runs through
`wordpress/plugin-check-action`, in a WordPress that the action starts with `wp-env`.
Errors fail the job, warnings are listed as annotations. The first run, with Plugin Check
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

Two warnings remain and are known: `set_time_limit()` in `WordPress\Cron`, with which a
tick extends its time limit where the host allows it, and the SQL string that
`WordPress\WpdbConnection` passes to `$wpdb` for the dump, whose identifiers `SqlFormatter`
quotes. The reviewers at WordPress.org run the scanner without these settings and will see
the 59 errors; `readme.txt` has no answer about direct file access yet (§7).

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
| `ci.yml` | push to `main`, pull request | PHPCS and `node --check assets/js/admin.js`; ESLint for `assets/js` and the browser tests; PHPStan with the project's three rules; Plugin Check on the built package and the translation template built from it (§5.8); on pull requests the check that the commits are signed off; unit tests on PHP 8.3, 8.4 and 8.5 with OPcache switched off for the CLI, so also without JIT (8.6 once released); the database suite against MySQL; mutation testing with its gate, skipped on pull requests that cannot change its result (§5.6) |
| `conformance.yml` | push to `main` and pull requests that change `src/`, `tests/`, `bin/install-test-tools.sh`, `bin/bench.php`, `composer.lock` or the workflow; nightly; manual dispatch | oracle suite with the restic CLI and rest-server; the format 2 tests again with `ext-zstd`; benchmarks with gates |
| `fuzz.yml` | nightly, manual dispatch | two minutes of fuzzing per parser; findings uploaded as an artifact |
| `integration.yml` | push to `main`, pull request, nightly, manual dispatch | the integration suite against WordPress 7.1.4 on PHP 8.3; nightly and on request with the acceptance sizes; records with `strace` and core dumps how the server's PHP processes ended. A second job runs the browser tests (§5.5) on a site of its own. WordPress latest-1 and PHP 8.5 are acceptance criteria of Milestone 4 and not in the matrix yet |
| (M3) `live-servers.yml` | weekly, manual dispatch, before every release | live smoke tests against a TLS rest-server and BorgBase repositories (§5.7) |
| (M3) `codeql.yml` | push, weekly | JavaScript analysis |
| (release) `deploy-wordpress-org.yml` | tag `v*` | build with `.distignore`, Plugin Check, manual approval, SVN deploy (`trunk`, `tags/x.y.z`, `assets/`), post-deploy diff, GitHub release notes only |

The four workflows without a mark exist; those marked (M3) are planned for Milestone 3 and
`deploy-wordpress-org.yml` comes with the first release. Actions are pinned to commit SHAs;
Dependabot proposes updates of the actions and of the Composer lock file every week. For
npm it is set to the lock file only as well. `package.json` names exact versions, so that
weekly run proposes no new version of ESLint or of its WordPress plugin: a new version is
a change of `package.json` and the lock file by hand, like a new PHPStan or restic.
Whether Dependabot's security updates, which can replace a vulnerable package in a lock
file, are switched on is a setting of the repository that no file here records. Every
workflow has read-only permissions and uses no secret. CI installs the npm packages with
`npm ci --ignore-scripts`, so only what the lock file names is installed and no package
runs a script while it is installed.

Checks that must be green before a merge: lint, ESLint, analyse, unit and mutation
testing, Plugin Check, the translation template and the sign-off check; conformance when
`Crypto`, `Format`, `Backend` or `Engine` are touched; integration once it has passed for
the first time. A `readme.txt` that names another version than the plugin header fails
Plugin Check, which is the consistency check planned earlier; the check for a stale
template has no subject, because the template is not committed.

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

No release exists yet, and most of the release tooling is not in the repository. What
exists: `bin/build-package.sh` builds the package from `.distignore`, and CI runs Plugin
Check on it on every pull request (§5.8). What does not: `deploy-wordpress-org.yml` is not
written, there is no `.wordpress-org/` directory, and the release checklist of step 2 does
not exist as a file. The steps below are the plan. The listing assets are planned for
Milestone 3 and the deployment workflow for Milestone 4 ([roadmap](05-roadmap.md)).

1. Update `CHANGELOG.md` and the `== Changelog ==` section of `readme.txt`; bump the version
   in the plugin header, `readme.txt` (`Stable tag`) and `Plugin::VERSION`; verify `Tested up
   to`, `Requires PHP`, `Requires at least`; attach the benchmark results. Between releases
   the three places name the same development version, for example `0.1.0-dev`.
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
textbook code tested against reference implementations) and in code comments. An answer
about direct filesystem access is not written yet, and it will be needed: the reviewers'
scanner reports 59 errors for native file functions in the core (§5.8). The slug
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
  and the links to its terms and privacy policy), changelog. Reviewed like code. A
  screenshots section comes with the listing assets in Milestone 3.
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
   stay inside its root before it is used. No password and no database content is written
   to disk in plain text.
6. **HTTP.** Requests go to the configured repository server only, over HTTPS with
   certificate verification, with the credentials in the `Authorization` header and never
   in the URL. The requests to the site itself (the tick chain, the loopback test and the
   exposure probe) stay the only others.
7. **WordPress surface.** Every REST route has a `permission_callback` that checks the
   capability, and a route that returns a secret asks for the WordPress password again
   ([security](03-security-and-threat-model.md) §5.5). Input is validated against
   `Settings\Schema`, output is escaped, and WP-CLI prints no secret.
8. **Tests and gates.** A test fails without the change. For `Crypto`, `Format`, `Backend`
   or `Engine` the conformance run is green; for `Crypto`, `Format` and `Engine\Forget` the
   covered mutation score stays at or above 90 %. Documents and decision records say what
   changed.
9. **Cooling-off.** A change to `Crypto`, `Format` or `Engine` is merged no earlier than 24
   hours after the last commit that touches these namespaces, and the author reads the
   whole diff once more before merging.

## 10. Licensing and attribution

Plugin GPL-2.0-or-later (`License` / `License URI` in `readme.txt`, header, `LICENSE`).
restic (BSD-2-Clause) and `rest-server` are development dependencies only; trademarks
acknowledged in `readme.txt`. The format implementation is original work that follows a
published specification; it copies no restic source code.
