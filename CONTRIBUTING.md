# Contributing

Thank you for helping. The architecture, its decisions and the reasons behind them are in
[`docs/`](docs/); please read [docs/02-architecture.md](docs/02-architecture.md) and the
ADR index before larger changes.

## Set up

```bash
composer install
```

```bash
bin/install-test-tools.sh
```

```bash
composer check
```

```bash
composer test:conformance
```

`composer check` runs PHPCS, PHPStan and the unit tests without network access.
`composer test:conformance` checks the restic format implementation against the real restic
CLI and rest-server installed by `bin/install-test-tools.sh` (Linux x86-64).

The admin script has its own linter, ESLint with the WordPress rulesets. It needs Node.js
22.13 or newer (CI uses 22) and the packages that `package-lock.json` names:

```bash
npm ci
```

```bash
npm run lint:js
```

The database suite needs a MySQL or MariaDB server (root over the local socket, or
`BFR_TEST_MYSQL_HOST`, `BFR_TEST_MYSQL_USER` and `BFR_TEST_MYSQL_PASSWORD`):

```bash
composer test:database
```

The integration suite runs against a real WordPress on `http://127.0.0.1:8890`:

```bash
bin/test-site.sh install
```

```bash
bin/test-site.sh start
```

```bash
composer test:integration
```

Two more checks run in CI and can run locally:

```bash
composer fuzz
```

```bash
composer mutation
```

`composer fuzz` fuzzes every parser for ten seconds. A finding is saved under
`.tools/fuzz-findings/` with the command that replays it; after the fix, copy the input to
`tests/Fixtures/fuzz/<target>/` so the unit suite replays it forever. `composer mutation`
needs pcov or Xdebug and checks that the tests of `Crypto`, `Format` and `Engine\Forget`
kill at least 90 % of the covered mutants. In CI it runs on `main` and on pull requests
that change the engine, the code below it, the tests or the tools
([docs/04 §5.6](docs/04-development-and-quality.md#56-mutation-testing-and-fuzzing)).

The package that would ship, and the translation template built from it:

```bash
bin/build-package.sh
```

```bash
bin/install-test-tools.sh wp-cli
```

```bash
composer pot
```

`bin/build-package.sh` copies the files that Git tracks, without the paths in `.distignore`,
into `build/backups-for-restic/` (needs Git and rsync). CI runs Plugin Check, the scanner
of the WordPress.org review team, on that directory. `composer pot` builds the package and
writes `languages/backups-for-restic.pot` with WP-CLI; CI fails when WP-CLI warns about a
string. The template is not committed.

## Rules of the road

- WordPress coding standards with the deviations in [docs/04 §3](docs/04-development-and-quality.md#3-coding-standards):
  tabs, PSR-4 file names, snake_case functions and variables, no Yoda conditions.
- Only `src/WordPress` and `src/Plugin.php` may call WordPress functions or use WordPress
  classes; a PHPStan rule checks it
  ([ADR 0029](docs/adr/0029-wordpress-free-core.md)).
- `Engine` and the layers below it (`Backend`, `Format`, `Crypto`, `Support`, `Exception`)
  use only each other, each one only the layers below it. A second PHPStan rule checks it;
  a new dependency needs a change of its list
  ([docs/02 §3](docs/02-architecture.md#3-module-map-and-dependency-rules)).
- JavaScript in `assets/js` is a classic script in ES2020 without a build step. Every
  translation call names the text domain `backups-for-restic` as a literal, and a string
  with a placeholder has a translator comment directly before the call. ESLint checks both.
- Every change comes with tests. Changes to `Crypto`, `Format`, `Backend` or `Engine` also
  need a green conformance run. Changes to `Crypto`, `Format` or `Engine\Forget` must keep
  the covered mutation score at or above 90 %.
- Changes to `Crypto`, `Format` or `Engine` wait 24 hours after their last commit before
  they are merged, and the author reads the diff once more before merging (the cooling-off
  period of [ADR 0024](docs/adr/0024-governance-and-quality-assurance.md)).
- Never weaken a security control to make a test pass; open an issue instead.
- Security problems go to [SECURITY.md](SECURITY.md), not to the issue tracker.

## Pull requests

All changes, including the maintainer's own, go through pull requests with the required
checks. Sign off your commits (`git commit -s`) to confirm the Developer Certificate of
Origin; a CI job fails when a commit of the pull request lacks the `Signed-off-by` line.
Describe what changed, why, and the security impact: does the change touch secrets,
cryptography, filesystem writes, HTTP requests or capability checks? If it does, go through
the [self-review checklist](docs/04-development-and-quality.md#91-self-review-checklist-for-security-relevant-changes)
and write its result under "Security impact". The pull request template lists the same
points as checkboxes.

Bug reports use the issue template, which asks for the output of
`wp backups-for-restic doctor` or the list under **Backups → Environment**. Never paste a
recovery kit, a password or a repository address with credentials into an issue.

[CodeRabbit](https://www.coderabbit.ai) reviews every pull request automatically with the
project's instructions in [`.coderabbit.yaml`](.coderabbit.yaml). Answer or resolve its
comments before merging; where it is wrong, say why in the thread. Its review is advice,
the CI checks are the gate. CodeRabbit also reviews stacked pull requests, which target the
branch below them, and pauses after several reviewed pushes; comment `@coderabbitai review`
when it skipped or paused one. Never put personal data, private e-mail addresses or chat
transcripts into code, commits or pull requests. Commits use a noreply address: GitHub's
`…@users.noreply.github.com`; AI-assisted commits may name the tool's noreply address in a
`Co-Authored-By` trailer.
