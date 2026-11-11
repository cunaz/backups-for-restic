# 08 · Handoff (2026-11-11)

Where the work stands, what is open, and what whoever continues has to know that the code
and the other documents do not say. The plan itself is in
[07-milestone-3-plan.md](07-milestone-3-plan.md); this file is a snapshot of one day and is
replaced or removed when the next handoff is written.

## 1. State of `main`

`main` contains Milestones 0 to 2 and, of phase 0 of the Milestone 3 plan, the packages
0.1 and 0.5 to 0.8. All workflows (`ci.yml`, `integration.yml`, `conformance.yml`) were green
on the commit before this file.

Merged on 2026-11-11, each as its own pull request with a merge commit:

| PR | What |
| --- | --- |
| 11 to 16 | Setup state, tests of the WordPress layer, browser tests, browser probe of the private directory, settings forms, smaller promises (phase 0.5, 0.6, 0.8) |
| 17 | `minimatch` override for the lint tooling |
| 18 | An entered repository password is a candidate until an unlock job has opened the repository with it |
| 19 | State, notifications and secrets are changed from the database and under `state.lock`; one request at a time sends notices |
| 20 | A server save that fails half way puts back settings, REST password, cached key and certificate |
| 21 | A cancel request that finds the work done still gets the result recorded; a check keeps its details with the job |
| 22 | CI installs the browser without packages from apt |
| 23 | The throttle test of a large file is deterministic |
| 24 | A write that the database refused no longer passes for done (`Options::set()` returns `bool`) |

## 2. Open work, in order

1. **Pull request 10, `feat/lock-continuity`** (ADR 0030, phase 0.3). It changes `Engine`,
   so the 24-hour rule of ADR 0024 applies: the last commit that touches `src/Engine`,
   `src/Format` or `src/Crypto` is from 2026-11-10 23:42 UTC, the head is a documents-only
   commit. Before merging: read the whole diff against `main` once more, check that CI is
   green on the head, and bring `main` in if GitHub reports a conflict (then wait for CI
   again).
2. **Three branches without a pull request**, cut before most of today's merges and far
   behind `main`:
   - `feat/schema-numbers` (phase 0.2),
   - `feat/prune-unindexed` (phase 0.4),
   - `feat/compact-index` (phase 0.9, first part).

   Bring each up to `main`, run the checks of section 4, open its pull request. Conflicts
   are to be expected in `src/Jobs/Backup/BackupSteps.php`, `src/Connection/Setup.php`,
   `src/Storage/SiteState.php`, `src/WordPress/Container.php`,
   `src/WordPress/RestRoutes.php`, `src/Engine/Repository.php`, `IndexCache.php`,
   `BackupRun.php`, `docs/02-architecture.md`, `docs/07-milestone-3-plan.md` and
   `CHANGELOG.md`. `feat/prune-unindexed` and `feat/compact-index` touch `Engine`: the 24
   hours count from their last engine commit after the merge of `main`.
3. **Engine items of phase 0** that have no branch yet: the order of tree entries checked
   on reading, the unchecked writes in `BackupRun`, a checkpoint written by another
   version, and the second part of 0.9 (memory with many blobs).
4. **Phases 1 to 5** of Milestone 3 as planned.

Once a package of phase 0 is merged, mark it in the table of
[07-milestone-3-plan.md](07-milestone-3-plan.md) §4.

## 3. Review findings that were declined, and why

So that they are not raised and argued again. Each has a reply on its review thread.

- `Options::fresh()` does not drop the `alloptions` cache: the options it reads are stored
  with autoload off. `WpOptions::set()` does drop it for its comparison, because the
  settings are autoloaded.
- `WpOptions::set()` compares through `get_option()` and not against the raw row: a foreign
  `pre_option_*` filter on one of the plugin's options governs every read of the plugin
  anyway. The reviewer withdrew this one.
- `SiteState::bind()` failing in a foreign hook after the results were dropped is not
  rolled back; `RepositoryRecord::moved()` does that write last instead.
- A rollback that itself fails while the database keeps refusing writes is not recorded
  anywhere: nothing can be stored then, and the request reports its error. The documents
  say that the rollback is attempted.
- A job that the scheduler creates in the moment of deactivation stays queued until the
  plugin is activated again. It holds no lock.
- A job whose `backing_up` step fails while a cancel request arrives is cancelled without a
  snapshot record; decompression limits inside WordPress' `Requests` library are described
  as a residual risk (T-19) instead of worked around.

## 4. How changes are made here

The rules are in [04-development-and-quality.md](04-development-and-quality.md); these are
the ones that are easy to get wrong.

- One package per pull request, body after `.github/pull_request_template.md`, with the
  self-review of §9.1 where the change is security-relevant.
- Commits are signed off (`git commit -s`) and carry the repository owner's GitHub noreply
  identity. No personal data, no chat transcripts, no session links, no model names in
  commits, pull requests or files.
- Merge with a local merge commit (`git merge --no-ff --signoff`, message
  `Merge <branch> (#<n>): <title>`) and push `main`, only when CI is green on the head of
  the pull request and every review finding is fixed or answered. If the merge needed a
  resolution, check the merge commit itself before pushing.
- A change to `Crypto`, `Format` or `Engine` waits 24 hours after its last commit and is
  read again before the merge (ADR 0024).
- Before a push: `vendor/bin/phpcs -q`,
  `vendor/bin/phpstan.phar analyse --no-progress --memory-limit=1G`,
  `vendor/bin/phpunit --testsuite unit` (about four minutes), `npm run lint:js` for
  `assets/js`. WordPress, WP-CLI, Plugin Check, the conformance suite and mutation testing
  run in CI only; a full CI round takes 35 to 45 minutes because of mutation testing.
- CodeRabbit reviews every pull request. Its findings are claims to verify against the
  code. Valid ones are fixed, the others get a reply on their thread; ten reviews per hour
  are included, and it pauses on a branch with many pushes.

Two things that cost time today:

- The browser job depended on apt; it no longer does. A job that fails before any test ran
  because of the runner's network is rerun, not fixed.
- `WpOptions` has no unit test: it is the adapter over WordPress' option functions. A
  change there is judged by the integration suite.

## 5. For the repository owner

- Delete the merged remote branches. Merges are pushed to `main` from a local merge, so
  GitHub's "automatically delete head branches" does not act on them.
- The first revision of the description of pull requests 7 to 24 carries a link that the
  tooling added; delete those revisions in the "edited" menu of each description.
- A ruleset for `main` would have to allow the pushes that merge pull requests, or the way
  of merging has to change first.
- Open decisions D2 to D9 of [07-milestone-3-plan.md](07-milestone-3-plan.md) §7, and the
  WordPress.org user name for the plugin directory.
