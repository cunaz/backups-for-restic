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
- Nothing is released yet, and of the release pipeline only its first step exists:
  `bin/build-package.sh` builds the package from `.distignore`. There is no deployment
  workflow and there are no `.wordpress-org/` assets. Testers copy the built directory into
  `wp-content/plugins/` or link a checkout of the repository there; the plugin runs without
  a build step. The listing assets are planned for Milestone 3 and the deployment workflow
  for Milestone 4 ([roadmap](../05-roadmap.md)).
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
