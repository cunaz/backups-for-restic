# 0017 · WordPress.org is the sole distribution channel; GitHub hosts the source
Status: accepted
Date: 2026-11-09

## Context
The project owner wants the plugin published in the WordPress.org plugin directory and the
source code public, with GitHub explicitly **not** acting as a distribution source. Two
installation channels would confuse users, split the update mechanism (WordPress updates
plugins from WordPress.org only) and double the release testing.

## Decision
- **WordPress.org** is the only installation and update channel. `Stable tag` in
  `readme.txt` governs what users receive. Support for users happens in the WordPress.org
  support forum; bug reports and contributions happen on GitHub; both are stated in
  `readme.txt`.
- **GitHub** is the source of truth for code, documentation, issues, pull requests, CI and
  security advisories. Tags are signed; **no release packages are attached to GitHub
  releases**, and the README points to WordPress.org for installation. Testers build from
  source with `bin/build-package.sh`.
- **Release pipeline**: signed tag → CI builds the package with `.distignore` → Plugin Check
  → manual approval in a protected environment → deployment to SVN (`trunk`, `tags/`,
  `assets/`) with a dedicated WordPress.org SVN password → post-deploy diff of the SVN tag
  against the package → GitHub release entry with notes only.
- **Guidelines as constraints**: every WordPress.org guideline is treated as a hard
  requirement from the first commit, verified by Plugin Check in CI: no executable
  downloads (ADR 0016), unique prefixes (ADR 0015), external-service disclosure for the
  repository server, human-readable code, no tracking, local assets only, translations
  through translate.wordpress.org.
- **Accounts**: the owner's personal WordPress.org account holds the plugin and is the only
  committer ([ADR 0024](0024-governance-and-quality-assurance.md)). It uses 2FA (mandatory
  for plugin authors since October 2024) and a dedicated SVN password, never the account
  password, in CI secrets.

## Consequences
- Users get automatic updates and a trusted installation path; there is exactly one
  artefact per version.
- The first release depends on the review queue (typically weeks) and on answering reviewer
  questions about system calls and filesystem access, which the documentation prepares for.
- The slug and plugin name become permanent at approval (ADR 0015).
- WordPress.org credentials in CI are a supply-chain asset and are handled as described in
  the security document (protected environment, approval, rotation).
- Features that WordPress.org forbids are not built at all rather than hidden behind
  build flags, which keeps a single tested code path.

## Alternatives considered
- **GitHub releases as a second channel**: update inconsistencies, two support stories, and
  a package that WordPress would not update automatically.
- **GitHub only with a custom updater**: WordPress.org-hosted plugins must update from
  WordPress.org; a custom updater also contradicts the owner's goal.
- **WordPress.org only, source private**: contradicts the goal of public, reviewable code.

## As built (2026-11)
- `bin/build-package.sh --zip` builds an installable package from the tracked files and
  `.distignore`. The banner and icons live in `.wordpress-org/`. The deployment workflow
  verifies an owner-signed stable tag on `main`, checks the package and uses the protected
  `wordpress-org` environment for publication. A local release rehearsal exercises the
  SVN transaction and byte comparison. First submission, account setup and public
  deployment require the owner. See the [release process](../04-development-and-quality.md#7-release-process-wordpressorg).
- Since 2026-11-22 the deployment workflow (`.github/workflows/deploy-wordpress-org.yml`)
  fails closed, and its checks run before the owner is asked to approve:
  - It starts for stable `vX.Y.Z` tags only, and its first step refuses any other run. The
    `tag` input of a manual start is gone: a retry re-runs the tag's own run, or starts the
    workflow on the tag itself.
  - `bin/verify-release-tag.sh`, in the copy on `main`, accepts an annotated tag that names
    itself, lies on `main` and is signed by the key in the repository variable
    `RELEASE_SIGNING_PUBLIC_KEY`. The primary fingerprint of that key must be pinned in
    `.github/release-signing-key.txt` as committed on `main`, so replacing the variable
    authorises no other key. The script reads GnuPG's status itself and requires `GOODSIG`:
    an expired or revoked key is refused whatever the installed Git accepts.
  - `bin/check-release-gates.py` checks what GitHub enforces (the rulesets of `main` and of
    release tags, the `wordpress-org` environment) and what CI has shown for the tagged
    commit. `bin/prepare-wordpress-org.py --check-only` checks the versions and both
    changelogs. A requirement that cannot be read counts as failed.
  - The release notes are published only if the tag still names the commit that was
    verified and deployed.
- The package check (`.github/actions/check-package`) refuses a package with anything but
  the plugin's files at its top, so a development file that `.distignore` forgets fails the
  check instead of shipping. `.distignore` names the configurations of the new mutation
  gates (`infection-*.json5.dist`). Plugin Check runs through WP-CLI in a WordPress
  set up by `bin/test-site.sh`, with WordPress, WP-CLI and Plugin Check pinned by checksum;
  the codes it ignores and the one file it leaves out are listed in the action with their
  reasons.
- Since 2026-11-10 Plugin Check runs in CI on the built package, for every pull request.
  Its errors about native file functions in the WordPress-free core are set aside in the
  workflow, with the reason; the reviewers will see them
  ([development guide](../04-development-and-quality.md) §5.8). Before that, PHPCS with the
  WordPress rules (prefixes, escaping, translation calls) was the only automatic check
  that concerns the guidelines.
- Two items of the guideline list were corrected in place. "Graceful failure without
  restic" was removed, because the plugin needs no restic program
  ([ADR 0018](0018-native-php-engine.md)). The external-service disclosure names the
  repository server instead of BorgBase alone ([ADR 0025](0025-repository-servers.md)).
