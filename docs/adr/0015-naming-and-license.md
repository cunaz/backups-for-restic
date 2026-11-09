# 0015 · Name, identifiers and license
Status: accepted
Date: 2026-11-09

## Context
WordPress.org guideline 17 states: "we will not permit a slug to begin with another
product's term". A name that begins with "restic" or "BorgBase" is therefore not possible;
the "… for <Brand>" form is accepted. Guideline 16: names cannot be reserved, and approved
slugs that stay unused may be given to other developers. The text domain must equal the
slug. Two owner decisions affect the name: self-hosted restic REST servers are officially
supported, and the plugin is never presented together with BorgBase (ADR 0025). The
repository is public from its first commit, so the name had to be settled before it.

## Decision
- **Name: Backups for restic.** Slug, text domain, plugin directory and GitHub repository
  are `backups-for-restic`; the slug was free on WordPress.org when it was chosen.
- **Identifiers**: namespace `BackupsForRestic\`; prefix `backups_for_restic_` for hooks,
  options, transients, cron events and script handles; constants `BACKUPS_FOR_RESTIC_*`;
  REST namespace `backups-for-restic/v1`; WP-CLI command `wp backups-for-restic`. The short
  prefix `bfr-` is used only for CSS classes and in test fixtures.
- The name says what the plugin does and names no storage provider. "restic" is written in
  lower case, as the restic project writes it.
- **License**: GPL-2.0-or-later. `readme.txt` states that the plugin is an independent
  project, not affiliated with restic or BorgBase, and acknowledges the marks.

## Consequences
- The identifiers were applied before the first public commit and before any release, so
  there is nothing to migrate.
- The slug becomes permanent only at WordPress.org approval. Until then another plugin could
  take it; a rename before the first release stays one scripted change.

## Alternatives considered
- **A name that begins with "restic" or "BorgBase"**: rejected by guideline 17.
- **"Encrypted Backups for BorgBase" or "Offsite Backup for BorgBase"**: compliant, but they
  tie the product to one provider, which contradicts official self-hosting support and the
  no-association decision.
- **"Site Backups for restic", "Encrypted Backups for restic"**: compliant and free, but
  longer without saying more.
- **An original brand with a descriptive subtitle**: recommended by WordPress.org, but it
  needs a trademark check and is harder to find for people who search for restic.
