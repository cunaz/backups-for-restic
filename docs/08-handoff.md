# 08 · Handoff (2026-11-16)

This handoff records the state on November 16. The November 20 release candidate and
remaining publication steps are documented in the [release verification](benchmarks/2026-11-20-release-candidate.md).

Where the work stands, what is open, and what whoever continues has to know that the code
and the other documents do not say. The plan itself is in
[07-milestone-3-plan.md](07-milestone-3-plan.md); this file is a snapshot of one day and is
replaced or removed when the next handoff is written.

## 1. State of `main`

`main` contains Milestones 0 to 2, phase 0 of the Milestone 3 plan except the rest of 0.9
(see its row in the plan), and the two engine packages of the restore: the snapshot reader
(1.1, ADR 0034) and the staging engine (2.1, ADR 0036).

Merged since the last handoff, each as its own pull request with a merge commit:

| PR | What |
| --- | --- |
| 10, 26 to 30 | Lock continuity, schema numbers, compact index, run checkpoints, strict tree order, prune of unindexed packs (phase 0.2 to 0.4, 0.9 first part) |
| 34 to 36 | Job memory budget and the WordPress memory matrix in CI, the layout marker under concurrent and malformed writes, a changelog correction |
| 37 | The index decoder refuses an object where the format has a list (the finding of the scheduled fuzz run) |
| 32 | Snapshot reader (1.1), with the fixes of its reread |
| 33 | Staging engine (2.1), with the fixes of its reread |

## 2. Open pull requests, in the order to merge them

All were opened on 2026-11-16. Each package was built, read by two independent reviewers
and corrected before it was pushed; the integration and browser tests of the packages ran
in CI for the first time there.

1. **41**, a lock order fix in `RestoreRun` (follow-up to 33), and **44**, three
   fixes to the reader and the backup plan (follow-up to 32). Small; merge first.
2. **45**, four minor findings of the reread of 34 and 35.
3. **38**, routes and the `ls` command (1.2), then **42**, the snapshot browser and the
   retention preview (1.3), which is stacked on 38.
4. **40**, the restore job (2.2), then **43**, staging list, expiry and removal (2.3),
   which is stacked on 40.
5. **39**, the zip writer and the download tokens (2.4, the parts without WordPress).
6. **31**, the database importer (phase 3). Not read in this round.

The pull requests 38 to 43 were opened against the branch below them. Once that branch is
merged, change the base to `main` (the head and its checks stay). 38, 39 and 40 were built
side by side: each adds to `CHANGELOG.md`, `docs/02-architecture.md`,
`docs/07-milestone-3-plan.md`, `docs/adr/README.md`, `RestRoutes`, `Container`, `Messages`
and `AccessTest`. After one of them is merged, bring `main` into the next, keep both sides,
run the checks of section 4 and wait for CI again.

CodeRabbit reviewed none of the pull requests opened on 2026-11-16: its hourly limit was
used up. Ask for a review on each (a comment with its review command) before merging, and
treat what it finds as section 4 says.

## 3. What is missing for phase 2

- **2.4, second part:** the routes that issue and redeem a download token and stream a
  file, and the job step that drives `ZipWriter`. ADR 0038 lists the duties these have
  (a name of its own per archive, reporting a refused name, files found through
  `Restore\Staging` so that expired staging is not served).
- **2.5:** the screens (start from the browser's selection, which the page keeps in
  `restoreSelection`; progress; the staging list; download; delete) and
  `wp backups-for-restic restore`.
- The acceptance of the whole phase under 128 MiB (plan, section 5).

Smaller points that are recorded nowhere else:

- The docblocks of `IndexFile::packs()` and `split()` and decision 5 of ADR 0031 still say
  that the text walk decides nothing about validity; since 37 it decides whether `packs`
  and `blobs` are lists.
- The reader takes two shared locks for `GET /snapshots/<id>` (snapshot, then manifest).
- While a prune holds the repository, the Snapshots tab answers with an error unless the
  list is cached. What it should show is a product decision for 1.3.
- `replace()` in `assets/js/admin.js` hands its arguments to `replaceChildren()`; a `null`
  among them would be shown as the text "null". Two callers on the Settings tab pass
  `null`. Seen by reading and in a stand-in page, not on a real site: check it there.

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
- **Owner override, 2026-11-12:** the 24-hour cooling-off period of ADR 0024 is waived for
  the ongoing completion of the work in this handoff. Read the whole diff again before
  merging, require green CI on the pull request's head, and fix or answer every review
  finding. The waiver applies to the waiting period only.
- Before a push: `vendor/bin/phpcs -q`,
  `vendor/bin/phpstan.phar analyse --no-progress --memory-limit=2G`,
  `vendor/bin/phpunit --testsuite unit` (six to seven minutes), `npm run lint:js`.
  WordPress, WP-CLI, Plugin Check, the conformance suite, the browser tests and mutation
  testing run in CI only; a full CI round takes about 50 minutes because of mutation
  testing.
- A new class in `tests/Integration` runs only if it is listed in
  `phpunit-integration.xml.dist`. One was written without that and passed CI unrun.
- A pull request that conflicts with `main` gets no CI run. Bring `main` in first.
- CodeRabbit reviews every pull request. Its findings are claims to verify against the
  code. Valid ones are fixed, the others get a reply on their thread; ten reviews per hour
  are included, and it pauses on a branch with many pushes.

## 5. For the repository owner

- Delete the merged remote branches. Merges are pushed to `main` from a local merge, so
  GitHub's "automatically delete head branches" does not act on them.
- The first revision of the description of the pull requests carries a link that the
  tooling added; delete those revisions in the "edited" menu of each description (7 to 25,
  and 37 to 46).
- Make the settings of [04-development-and-quality.md](04-development-and-quality.md) §7.1
  before the first release: the rulesets for `main` and for release tags, the `wordpress-org`
  environment, the pinned release key and its variable, the live smoke, fine-grained tokens on
  development machines and, once the plugin is listed, WordPress.org's release confirmation.
  The release workflow refuses to deploy without the first three. With the ruleset on `main`,
  merges go through GitHub (the merge button or `gh pr merge`); a merge pushed from a local
  clone is refused.
- Open decisions D2 to D9 of [07-milestone-3-plan.md](07-milestone-3-plan.md) §7, and the
  WordPress.org user name for the plugin directory.
