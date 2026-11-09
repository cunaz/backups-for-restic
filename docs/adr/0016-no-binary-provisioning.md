# 0016 · The plugin never downloads, bundles or updates executables
Status: accepted (supersedes 0008)
Date: 2026-11-09

## Context
**Update (ADR 0018):** the plugin now contains a native PHP engine and uses no restic
binary at all in 1.0; the installation guidance below applies only to operators who restore
with the CLI on their own computer. This decision stays in force for the optional binary
engine of Appendix A and for every future feature.

The plugin is distributed through the WordPress.org plugin directory (ADR 0017). Guideline 8
of the directory ("Plugins may not send executable code via third-party systems") and the
review team's clarification forbid plugins from downloading code or binaries from anywhere
but WordPress.org; installs of executable code must not be initiated from inside wp-admin.
ADR 0008 had proposed an opt-in download of the restic release binary with pinned checksums
for hosts whose packages are too old (Ubuntu 24.04 LTS ships 0.16.4, below the floor of
0.18.0). That feature is incompatible with the distribution channel, and maintaining two
builds (with and without it) would double the testing matrix for a feature most users of a
WordPress.org plugin could not use.

## Decision
- The plugin **never downloads, bundles, copies or updates** a restic binary and never
  invokes `restic self-update`.
- Discovery order for the binary: constant `BACKUPS_FOR_RESTIC_RESTIC_BINARY` → setting
  `advanced.restic_binary` (absolute path) → well-known directories (`/usr/local/bin`,
  `/usr/bin`, `/bin`, `/opt/homebrew/bin`, `~/bin`) → "not found".
- Every candidate is validated before use: regular file, executable, not world-writable,
  owned by the PHP user or root, `version --json` parses and reports ≥ 0.18.0. The result is
  cached with the file's mtime.
- When restic is missing or too old, the wizard, the Doctor, Site Health and `wp
  backups-for-restic doctor` show an **installation guide** for the detected platform: the
  distribution package where its version is sufficient (Debian 13+, Fedora, Alpine, Arch,
  Homebrew), otherwise the upstream release: download to a user-writable directory such as
  `~/bin`, verify against the upstream `SHA256SUMS` and its signature, decompress, make
  executable, then enter the path in Settings → Advanced. The guide is text; the plugin does
  not execute any of it. The same text lives in `readme.txt` (Installation and FAQ).
- Backups refuse to start without a validated binary ("fail gracefully").

## Consequences
- The plugin's audience is operators with shell access to their server, which is inherent
  to a restic-based tool and now stated honestly in the listing.
- No checksum tables, download code or platform detection in the plugin: less code, smaller
  supply-chain surface, nothing for the review team to object to.
- Ubuntu LTS users install the upstream release manually; the guide makes this a five-minute
  task.
- Integration tests install restic through a developer script (`bin/install-restic-for-tests.sh`),
  not through plugin code.

## Alternatives considered
- **Bundling binaries in the plugin package** (as some image-optimisation plugins do):
  25–30 MB per platform in SVN for every restic release, compiled code needing special
  justification under the "human readable" guideline, lagging upstream security fixes, and a
  maintenance burden out of proportion to the benefit.
- **A companion installer outside WordPress.org** (shell script or WP-CLI package on
  GitHub): splits the installation story, still "requires external code", and offers little
  over a documented `curl`/`sha256sum` recipe.
- **Keeping the download in a GitHub-only build**: there is no GitHub build (ADR 0017).
