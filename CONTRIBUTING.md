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
kill at least 90 % of the covered mutants.

## Rules of the road

- WordPress coding standards with the deviations in [docs/04 §3](docs/04-development-and-quality.md#3-coding-standards):
  tabs, PSR-4 file names, snake_case functions and variables, no Yoda conditions.
- `src/Crypto`, `src/Format`, `src/Backend` and `src/Engine` must not call WordPress
  functions.
- Every change comes with tests. Changes to `Crypto` or `Format` also need a green
  conformance run and must keep the mutation score at or above 90 %.
- Never weaken a security control to make a test pass; open an issue instead.
- Security problems go to [SECURITY.md](SECURITY.md), not to the issue tracker.

## Pull requests

All changes, including the maintainer's own, go through pull requests with the required
checks. Sign off your commits (`git commit -s`) to confirm the Developer Certificate of
Origin. Describe what changed, why, and the security impact: does the change touch secrets,
cryptography, filesystem writes, HTTP requests or capability checks?
