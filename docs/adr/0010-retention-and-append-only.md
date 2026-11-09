# 0010 · Retention modes and automatic append-only detection
Status: accepted (revised: detection and recommendation decided by the owner 2026-11-09)
Date: 2026-11-09

## Context
Forget and prune need delete permission. BorgBase (repository setting) and rest-server
(`--append-only`) can reject deletions of data, index, key and snapshot files with HTTP 403
while lock files stay deletable. That is the main protection against an attacker who takes
over the website. The price: the plugin cannot remove old snapshots, which then happens from
another computer. restic warns that count-based retention can be gamed with injected fake
snapshots and recommends `--keep-within`. The owner decided: detect the mode automatically
and recommend append-only; without append-only the plugin cleans up by itself.

## Decision
- **Detection probe**: `DELETE {path}/snapshots/<random 64 hex>`. The object does not exist,
  so the request changes nothing on any server. rest-server checks append-only before it
  touches the filesystem and answers 403; otherwise it ignores the missing file and answers
  200 (verified in the rest-server source). Mapping: 200, 204 or 404 → deletions allowed;
  403 or 405 → append-only; 401 → credentials invalid; anything else → unknown, and the
  wizard asks. BorgBase's answers are verified by the live smoke tests (ADR 0025).
- **When**: in the wizard, daily by the heartbeat, and at the start of every forget or
  prune job. A 403 during forget or prune counts as a detection.
- **Retention mode `plugin`** (deletions allowed): forget after each successful backup and
  prune-lite weekly, scoped by host and tag, always with a non-empty policy that includes
  `keep-within` (default 2 days), never with `unsafe-allow-remove-all`.
- **Retention mode `external`** (append-only): the plugin never deletes data, index or
  snapshot files; it still releases its own locks. The dashboard shows repository growth and
  the disaster-recovery guide explains pruning from another computer.
- **Asymmetric switching**: detecting append-only switches to `external` at once and
  notifies the administrator. Detecting that deletions became possible only *proposes* the
  switch back to `plugin`; nothing is deleted until an administrator confirms.
- **Recommendation** shown by the wizard when deletions are allowed: *"Recommended: turn on
  append-only on your server (BorgBase: repository settings; rest-server: `--append-only`).
  Then nobody who takes over this website can delete your backups. Old snapshots must then
  be cleaned up from another computer."*

## Implementation
- Forget and prune jobs begin with a `detecting` step that runs the probe; a refused deletion
  during the run counts as a detection too. On append-only, `retention.mode` becomes
  `external` and the job ends without deleting (`skipped: append_only`). In mode `external`
  the jobs end at once (`skipped: retention_external`); if deletions work again they record a
  proposal in the site state for the dashboard and change nothing.
- The heartbeat starts a forget job after every successful backup (mode `plugin`,
  `retention.run_forget_after_backup`), prune on its weekly or monthly slot (mode `plugin`
  only) and the check on its slot (both modes); one job at a time, the first slot after setup
  is only recorded. Forget is scoped to this site's host name and the `backups-for-restic`
  tag, grouped by host and tags, and refuses a policy without `keep-within`.
- Administrators start the same jobs from the REST API (`POST /jobs` with `type`) and WP-CLI
  (`forget`, `prune`, `check [--read-data-subset=<percent>]`).

## Consequences
- No question in the wizard for the common case; the secure setup is explained, not forced.
- In `plugin` mode the credentials on the web server can delete backups: the documented
  default trade-off for users without append-only.
- Turning append-only off temporarily for a manual prune never makes the plugin start
  deleting on its own.
- Integration tests run against `rest-server --append-only`; live tests against BorgBase.

## Alternatives considered
- **Asking in the wizard every time**: friction without new information when detection works.
- **Forcing an explicit risk confirmation without append-only**: considered; the owner chose
  a recommendation.
- **Requiring append-only**: maximum protection, but pruning from another computer is a high
  hurdle for shared-hosting users.
- **Probing by uploading and deleting a test object**: leaves garbage in append-only
  repositories that restic cannot decrypt; rejected.
