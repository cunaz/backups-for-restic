# 0008 · restic binary: system-installed first, opt-in managed download with pinned checksums
Status: superseded by [0016](0016-no-binary-provisioning.md) — the plugin is distributed
through WordPress.org, whose guideline 8 forbids downloading executable code
Date: 2026-11-09

## Context
restic must exist on the server. Distribution packages lag (Ubuntu 24.04: 0.16.4, below the
floor); many managed hosts have no package manager access but allow user-writable
directories. WordPress.org guideline 8 forbids plugins from downloading executable code from
third-party systems, which matters only if the plugin is ever listed there.

## Decision
- Order of preference: `BACKUPS_FOR_RESTIC_RESTIC_BINARY` constant → `advanced.restic_binary` setting →
  auto-detection in a fixed list of directories (`/usr/local/bin`, `/usr/bin`, `/bin`,
  `/opt/homebrew/bin`, `~/bin`) → managed binary.
- Every candidate is validated: regular file, executable, not world-writable, owned by the
  PHP user or root, `version --json` parses and meets the floor.
- **Managed binary** (opt-in via an explicit admin action or `wp backups-for-restic restic install`):
  download of the pinned restic release for the detected OS/architecture from GitHub
  releases over HTTPS, streamed to a temp file in the private directory, SHA-256 compared
  against values committed in the plugin source, `chmod 0700`, atomic move to
  `<private>/bin/restic-<version>`, verified with `version --json`. Bumps of the pinned
  version are reviewed pull requests that update the hashes from upstream `SHA256SUMS`.
- `restic self-update` is never invoked. `BACKUPS_FOR_RESTIC_DISABLE_MANAGED_BINARY` removes the feature;
  a WordPress.org build sets it at build time.

## Consequences
- Operators who control their servers keep full control; others get a safe path.
- The plugin carries a table of hashes that must be maintained with each restic release.
- The integration test suite installs restic through the same code, giving the download
  logic continuous coverage.

## Alternatives considered
- **Bundle binaries in the plugin**: large package, licensing/trademark questions, lagging
  security fixes, WordPress.org violation.
- **Verify GPG signatures instead of pinned hashes**: PHP has no reliable GPG without
  extensions; pinned hashes committed to the repository provide equivalent assurance through
  the plugin's own review process.
- **Always require system installation**: excludes many managed hosts and LTS distributions
  with old packages.
