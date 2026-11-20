# Backups for restic

> **Release candidate 0.3.0-rc.1.** Back up files and the database to an encrypted
> restic repository. In **Backups → Snapshots**, select a file or directory to restore.
> Download the result from private staging, or review the proposed file changes before
> applying them to this installation. A separate confirmation reverses eligible changes
> within 24 hours. A fresh installation can connect to the repository with its recovery kit,
> stage content and uploads under their new paths, and rehearse the database import.
> Eligible MySQL installations can apply the reviewed database and undo it for 24 hours.
> MariaDB and incompatible schemas use the rehearsed SQL and the manual recovery guide.
> See the [recovery guide](docs/06-disaster-recovery.md) and [release criteria](docs/05-roadmap.md).
>
> **Distribution:** the plugin will be published in the
> [WordPress.org plugin directory](https://wordpress.org/plugins/), which is the only
> installation and update channel. This GitHub repository is the public source: issues, pull
> requests, CI and documentation live here; release packages do not.

A WordPress plugin that backs up a site (files **and** database) into an end-to-end
encrypted [restic](https://restic.net) repository on a restic REST server over HTTPS:
your own [rest-server](https://github.com/restic/rest-server) or a hosted service such as
BorgBase. It is written in pure PHP: **no restic program, no shell access and no server
configuration are required**, so it runs on ordinary shared hosting. What it writes is a
normal restic repository that the `restic` command line can read, check and restore. The
project is independent and not affiliated with restic or BorgBase.

## Why this plugin exists

Most WordPress backup plugins invent their own archive formats, store plaintext copies in
`wp-content`, or need a SaaS account of the plugin vendor. Tools built on restic, on the
other hand, assume a server you control. This plugin closes the gap:

- **restic's format does the hard part.** Deduplication, client-side encryption, integrity
  checking and retention follow restic's proven repository design. The plugin implements
  that design in PHP so that it works where no binary can be installed, and every backup
  stays an ordinary restic repository.
- **You choose the storage.** Your own restic REST server or a hosted one: one HTTPS
  endpoint per repository, optionally in append-only mode, so that nobody who takes over
  the website can delete the backups.
- **Your data stays yours.** The repository password never leaves the server; the storage
  provider and the plugin author never see plaintext. Every backup can be restored with the
  plain `restic` command line on any computer, without WordPress and without this plugin.

## Design principles (short version)

1. **No dependencies beyond PHP and WordPress.** No restic binary, no `proc_open`, no SSH
   client, no `mysqldump`, no Composer packages at runtime, no JavaScript build step, no
   downloads of executables. Required PHP extensions: OpenSSL, JSON and mbstring, all
   standard on hosting.
2. **Security is a feature, not a layer.** No home-made cryptography: AES and SHA-256 come
   from PHP's OpenSSL and hash extensions, and the three algorithms PHP lacks (Poly1305,
   scrypt, restic's chunker) are textbook code tested against reference implementations.
   Secrets are encrypted at rest, never logged, never in URLs, and can live in
   `wp-config.php` instead of the database. The format implementation is verified against
   the restic CLI on every change.
3. **Built for humans.** Small modules with one job each, explicit names, documented
   decisions (ADRs), tests that read like specifications, and a disaster-recovery guide that
   works at 3 a.m.
4. **Bounded by design.** Everything runs in short, resumable slices that fit shared-hosting
   limits; a killed request loses at most one slice of work.
5. **Fail closed, recover gracefully.** If anything is uncertain (web-readable working
   directory, rotated salts, unsupported repository version, failed authentication tag), the
   plugin refuses to proceed and says exactly what to do.

## After installing an older plugin version

If the stored settings have a newer or malformed layout number, the plugin stays inactive
and shows a notice. Its admin page, commands, REST routes and scheduled callbacks are
unavailable. Install a version compatible with those settings, or restore damaged settings
from a verified database backup; do not lower or delete their stored layout number.
Deactivation removes scheduled events but leaves jobs and their repository locks alone.
An unreadable job document also blocks new jobs and repository changes and is kept for a
compatible version to read. The [recovery guide](docs/06-disaster-recovery.md#scenario-j--the-plugin-is-inactive-after-a-downgrade)
explains how to proceed.

Database backups limit each encoded SQL statement to 16 MiB. A single row that exceeds
that limit stops the backup with a database error; binary and escaped values can be larger
in SQL than in the source table. Use an external database export for such tables rather
than expecting an incomplete backup to count as successful.

## Documents

| Document | What it answers |
| --- | --- |
| [docs/01-goals-and-scope.md](docs/01-goals-and-scope.md) | What the plugin does, does not do, and where it runs |
| [docs/02-architecture.md](docs/02-architecture.md) | How it is built: modules, runtime model, the restic format as implemented, data flows, interfaces, surfaces |
| [docs/03-security-and-threat-model.md](docs/03-security-and-threat-model.md) | Assets, threats, controls, secret handling, cryptography rules, hardening checklist |
| [docs/04-development-and-quality.md](docs/04-development-and-quality.md) | Repository layout, standards, tooling, tests incl. the restic conformance oracle, CI, WordPress.org releases |
| [docs/05-roadmap.md](docs/05-roadmap.md) | Milestones, acceptance criteria, open questions, risks |
| [docs/06-disaster-recovery.md](docs/06-disaster-recovery.md) | How to get a site back with or without the plugin |
| [docs/07-milestone-3-plan.md](docs/07-milestone-3-plan.md) | Plan for Milestone 3 (restore): work packages, order, decisions, risks |
| [docs/08-handoff.md](docs/08-handoff.md) | Where the work stands and what is open, as of the date in its title |
| [docs/guides/cron.md](docs/guides/cron.md) | Running backups with a real cron job of the hosting panel |
| [docs/guides/rest-server.md](docs/guides/rest-server.md) | Running your own restic REST server, append-only, and cleaning up from the server |
| [docs/appendix-a-binary-engine.md](docs/appendix-a-binary-engine.md) | Design of an optional restic-binary engine for VPS users (future option) |
| [docs/adr/](docs/adr/) | Architecture Decision Records: the reasoning behind every non-obvious choice |
| [docs/benchmarks/](docs/benchmarks/) | Measured throughput and unlock time, with the gates they must pass |

## Decisions at a glance

| Topic | Decision | ADR |
| --- | --- | --- |
| Engine | Native PHP implementation of the restic repository format; no binary, no shell | [0018](docs/adr/0018-native-php-engine.md) |
| Transport | restic REST protocol over HTTPS, implemented on the WordPress HTTP API | [0001](docs/adr/0001-restic-rest-backend.md) |
| Runtime dependencies | PHP ≥ 8.3 and WordPress ≥ 6.7 only; no external programs, no Composer packages at runtime | [0002](docs/adr/0002-runtime-dependencies.md), [0003](docs/adr/0003-version-floors.md) |
| Repository format | Version 1 for new repositories (no compression, readable by every restic since 0.9); exact restic chunker; version 2 from restic only with `ext-zstd` | [0019](docs/adr/0019-repository-format-and-chunker.md) |
| Cryptography | AES and SHA-256 from OpenSSL and PHP's hash extension; Poly1305 and scrypt in pure PHP, tested against libsodium and RFC vectors | [0027](docs/adr/0027-poly1305-in-pure-php.md), [0020](docs/adr/0020-unlocking-and-kdf.md) |
| Unlocking | Pure-PHP resumable scrypt once, master key cached in the encrypted secret store | [0020](docs/adr/0020-unlocking-and-kdf.md) |
| Long-running work | Resumable steps in bounded ticks: WP-Cron, loopback chaining, external cron ping, admin polling | [0011](docs/adr/0011-scheduling.md), [0014](docs/adr/0014-file-based-job-store.md) |
| Database | Streamed per table into the snapshot as virtual files; no plaintext dump on disk | [0021](docs/adr/0021-database-streaming.md) |
| Secrets | libsodium-encrypted at rest, key derived from WordPress salts, `wp-config.php` overrides | [0006](docs/adr/0006-secrets-at-rest.md) |
| Working directory | Private directory, preferably outside the web root, protection verified actively, caches encrypted | [0007](docs/adr/0007-private-directory.md) |
| Snapshot layout | One snapshot per run: files + database directory + manifest; stable host and tags | [0009](docs/adr/0009-snapshot-layout.md) |
| Maintenance | Forget with restic's semantics; prune without repacking, including unindexed packs left by interrupted backups; structural check with sampled data | [0022](docs/adr/0022-maintenance-scope.md), [0010](docs/adr/0010-retention-and-append-only.md) |
| Conformance | The restic CLI is the oracle in CI; every release passes `restic check --read-data` and restore parity | [0023](docs/adr/0023-restic-cli-as-conformance-oracle.md) |
| Admin UI | Vanilla JavaScript, no build step, REST API as the only data channel | [0012](docs/adr/0012-admin-ui-without-build-step.md) |
| Restore | Stage files, download them, review live changes and undo them. Rehearse SQL and exchange eligible InnoDB tables after confirmation | [0041](docs/adr/0041-guarded-file-changes.md), [0043](docs/adr/0043-atomic-mysql-database-exchange.md) |
| Executables | The plugin never downloads, bundles or updates executables | [0016](docs/adr/0016-no-binary-provisioning.md) |
| Distribution | WordPress.org plugin directory is the only release channel; GitHub holds source, issues and CI | [0017](docs/adr/0017-distribution-via-wordpress-org.md) |
| Name and license | Backups for restic, slug `backups-for-restic`; GPL-2.0-or-later | [0015](docs/adr/0015-naming-and-license.md) |
| Repository servers | Any restic REST server over HTTPS: self-hosted rest-server or a hosted service (preset for BorgBase); no vendor affiliation | [0025](docs/adr/0025-repository-servers.md) |
| Append-only | Detected automatically and recommended; the plugin cleans up only when deletions are allowed | [0010](docs/adr/0010-retention-and-append-only.md) |
| Multisite | Not supported in 1.0; activation is refused; planned for 1.1 | [0026](docs/adr/0026-multisite-deferred.md) |
| Governance | Solo maintainer, free plugin, internal quality assurance with compensating controls | [0024](docs/adr/0024-governance-and-quality-assurance.md) |

## Requirements

| Component | Minimum | Recommended |
| --- | --- | --- |
| PHP | 8.3 with OpenSSL, JSON, mbstring; `memory_limit` ≥ 96 MB; `max_execution_time` ≥ 30 s | 8.4 or newer, `ext-curl`, 256 MB, OPcache. The JIT is not part of the recommendation: it makes chunking about five times faster, but on PHP 8.3 its tracing mode has crashed PHP during backups in the project's tests |
| WordPress | 6.7, single site (multisite planned for 1.1) | current release |
| Hosting | outbound HTTPS to the repository server; private writable disk space for caches, staging and undo copies | a control-panel cron fetching `wp-cron.php` every 1–5 minutes |
| Server software | none | none |
| Repository server | a restic REST server with TLS, one repository per site: self-hosted rest-server or a hosted service | append-only mode enabled |
| restic CLI | not needed on the server; to restore on another computer, restic 0.16.1 or newer, which the recovery kit's commands need (the repository format itself is readable since 0.9) | current release |

The merged repository index also has to fit in PHP's memory limit alongside WordPress and
the backup buffers. The plugin checks the accumulated index budget after each decoded pack
and stops with `index_too_large` when too little memory remains. A single large index file
or pack can still exhaust PHP memory before that check. Backups can reduce their pack size, down
to 4 MiB, and record the reduction in the job log. This helps the buffers fit; it does not
remove the index's memory cost. See [memory-limit recovery](docs/06-disaster-recovery.md#index-memory-limit).

Backup checkpoints and saved directory entries need a writable private working directory.
If a local read or write fails, the job stops with an environment error instead of
publishing incomplete directory contents. Fix the local storage problem before starting
another backup; see [working-file failures](docs/06-disaster-recovery.md#local-backup-working-file-failures).

## Installation (once published)

Install from the WordPress.org plugin directory (Plugins → Add New). No server software is
needed; the setup wizard checks PHP extensions, memory and time limits, the hosting's cron
options and the protection of the plugin's working directory, then guides you through
connecting a repository on your own restic REST server or a hosted one. Packages are not
distributed through GitHub. To test unreleased code, run `bin/build-package.sh` and copy
`build/backups-for-restic/` into `wp-content/plugins/`, or link a checkout of this
repository there: the plugin needs no build step
([development guide](docs/04-development-and-quality.md), §5.8).

## License

GPL-2.0-or-later (required for WordPress plugins distributed through WordPress.org and the
customary license for WordPress code). restic is a separate program under its own BSD
license; it is not bundled and not required.
