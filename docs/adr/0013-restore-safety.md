# 0013 · Restore to staging first; guarded apply
Status: accepted (part of 1.0, confirmed by the owner 2026-11-09)
Date: 2026-11-09

## Context
Restoring files directly into the live site from inside the running site is dangerous:
the plugin may overwrite itself mid-run, a crafted snapshot could contain symlinks or
unexpected paths, and a wrong click destroys the current state. Database restores replace
the plugin's own settings.

## Decision
- Every restore first lands in `<private>/restores/<job>/` via the engine's restore (tree
  walk, ranged downloads, tag and id verification). The admin inspects the result, downloads
  it, or applies it.
- **Apply** is a separate job with a dry-run report. It validates each destination with
  `realpath` containment against the allowed roots, refuses symlinks and `..`, skips the
  plugin's own directory and `wp-config.php` unless explicitly selected, enables maintenance
  mode, moves files atomically where possible, and records a summary.
- **Database import** verifies header/trailer, runs in maintenance mode, preserves the
  plugin's options, and requires a typed confirmation.
- Full-site restores for lost servers are documented as plain-restic procedures and offered
  as a wizard path on a fresh installation.

## Consequences
- Restores need temporary disk space (checked in preflight) and two steps instead of one.
- The attack surface of "apply" is small and testable in isolation.

## Alternatives considered
- **Direct in-place `restic restore --target /`**: fast, but no inspection, risk of
  overwriting running code, and restic's `--delete` semantics are too sharp for a UI button.
- **No file apply in the plugin (WP-CLI only)**: simpler, but excludes operators without
  shell access; left as roadmap question Q4.
