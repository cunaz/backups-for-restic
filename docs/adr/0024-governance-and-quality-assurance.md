# 0024 · Governance: solo maintainer, free plugin, internal quality assurance
Status: accepted (owner decisions 2026-11-09; fuzzing tool revised by 0028; automated review
added 2026-11-09)
Date: 2026-11-09

## Context
The owner decided: the plugin belongs to the owner's personal WordPress.org account with no
further committers; it is completely free; there is no external security audit before 1.0;
repository and documentation are in English with translations through
translate.wordpress.org; the submission date is decided when needed. Earlier controls
assumed a second maintainer (two approving reviews, release review) and an external audit.
A plugin that implements an encryption format needs compensating controls that one person
can operate.

## Decision
- **Ownership**: the owner's personal WordPress.org account owns the plugin and is the only
  committer. 2FA on WordPress.org and GitHub; a dedicated SVN password in CI, rotated yearly
  and on suspicion. The protected deployment environment requires the owner's approval for
  each release: a deliberate second step even without a second person.
- **Funding**: completely free; a donation link in `readme.txt`; no add-on, no upsell
  notices, and no extension API promises beyond the documented hooks, which follow semantic
  versioning.
- **Quality assurance without a second reviewer or an external audit.** Compensating
  controls:
  1. Differential testing against the restic CLI on every change to `Crypto`, `Format`,
     `Backend` and `Engine` (ADR 0023).
  2. Known-answer tests for every primitive and format object (RFC 7914 for scrypt; vectors
     recorded with `restic cat`).
  3. Mutation testing with Infection on `Crypto`, `Format` and `Engine\Forget` as a merge
     gate: covered mutation score indicator of at least 90 %.
  4. Fuzzing of every parser that reads server data with the built-in structure-aware
     fuzzer (ADR 0028), briefly in every unit run and for two minutes per parser nightly;
     every finding becomes a regression input.
  5. A written self-review checklist for security-relevant changes and a 24-hour cooling-off
     period before merging changes to `Crypto`, `Format` or `Engine`.
  6. All changes, the maintainer's own included, go through pull requests with required
     checks; no direct pushes to `main`. The repository's initial import was the only
     direct push.
  7. An automated review of every pull request by CodeRabbit, free for public repositories
     and configured in `.coderabbit.yaml`: project-specific review instructions per path,
     secret and PII scanners, workflow linters, and a pre-merge check that fails on personal data
     and chat transcripts. Its findings are advice that must be answered or resolved before
     merging; it does not replace the checks above or an independent human review.
  8. Safety nets in production: a monthly check with sampled data verification, and restore
     with the plain restic CLI, independent of plugin code.
- **Accepted residual risks**, recorded in the threat model: bus factor 1 for releases and
  security fixes; no independent review of the format implementation before 1.0; design and
  side-channel flaws that differential tests cannot detect.
- **Language**: English for code, documentation and `readme.txt`; German and other languages
  for the UI and the directory listing through translate.wordpress.org.
- **Submission timing**: decided by the owner when needed; planning default after
  Milestone 3.
- **Openness**: public source and `SECURITY.md` invite independent reports. A second
  committer or an external audit can be added at any time without architectural change.

## Consequences
- Low overhead; the quality bar rests on automation that runs on every change.
- CI gains mutation-testing and fuzzing jobs; the release checklist includes the self-review.
- If the owner is unavailable, no release is possible; users keep a working plugin and a
  plugin-independent recovery path.

## Alternatives considered
- **External audit before 1.0**: declined by the owner.
- **A community review programme**: declined as a plan; contributions remain welcome.
- **A second committer**: declined for now.
- **No automated review**: rejected once the repository was public; an AI reviewer costs
  nothing for public repositories and catches a different class of mistakes than tests,
  such as missing capability checks or inconsistent documentation.
- **Pro add-on or paid services**: declined.
