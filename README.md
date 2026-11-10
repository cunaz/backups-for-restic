# Backups for restic

> **Project status: Milestones 0 to 2 are implemented; nothing is released yet.** The restic
> format engine in pure PHP is verified against the restic CLI. On top of it come the setup
> wizard, scheduled and resumable backup jobs on WP-Cron, the streamed database dump, and
> maintenance: retention with restic's semantics, prune without repacking, a repository check
> that reads a share of the data, append-only detection, e-mail notifications and a gentle CPU
> mode. The plugin cannot restore yet: that is Milestone 3, and until then every backup is
> restored with the restic command line ([guide](docs/06-disaster-recovery.md)). Also open
> and planned for Milestone 3: browser tests and tests of the WordPress layer, a preview of
> what retention would remove, the size of the repository on the dashboard, and measurements
> against a hosted server, which has not been tested yet ([roadmap](docs/05-roadmap.md)).
> The design documents explain *why* things are built the way they are.
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

## Documents

| Document | What it answers |
| --- | --- |
| [docs/01-goals-and-scope.md](docs/01-goals-and-scope.md) | What the plugin does, does not do, and where it runs |
| [docs/02-architecture.md](docs/02-architecture.md) | How it is built: modules, runtime model, the restic format as implemented, data flows, interfaces, surfaces |
| [docs/03-security-and-threat-model.md](docs/03-security-and-threat-model.md) | Assets, threats, controls, secret handling, cryptography rules, hardening checklist |
| [docs/04-development-and-quality.md](docs/04-development-and-quality.md) | Repository layout, standards, tooling, tests incl. the restic conformance oracle, CI, WordPress.org releases |
| [docs/05-roadmap.md](docs/05-roadmap.md) | Milestones, acceptance criteria, open questions, risks |
| [docs/06-disaster-recovery.md](docs/06-disaster-recovery.md) | How to get a site back with or without the plugin |
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
| Maintenance | Forget with restic's semantics, prune without repacking, structural check with sampled data | [0022](docs/adr/0022-maintenance-scope.md), [0010](docs/adr/0010-retention-and-append-only.md) |
| Conformance | The restic CLI is the oracle in CI; every release passes `restic check --read-data` and restore parity | [0023](docs/adr/0023-restic-cli-as-conformance-oracle.md) |
| Admin UI | Vanilla JavaScript, no build step, REST API as the only data channel | [0012](docs/adr/0012-admin-ui-without-build-step.md) |
| Restore (Milestone 3, not built yet) | Staging first; applying to the live site is a separate, guarded step | [0013](docs/adr/0013-restore-safety.md) |
| Executables | The plugin never downloads, bundles or updates executables | [0016](docs/adr/0016-no-binary-provisioning.md) |
| Distribution | WordPress.org plugin directory is the only release channel; GitHub holds source, issues and CI | [0017](docs/adr/0017-distribution-via-wordpress-org.md) |
| Name and license | Backups for restic, slug `backups-for-restic`; GPL-2.0-or-later | [0015](docs/adr/0015-naming-and-license.md) |
| Repository servers | Any restic REST server over HTTPS: self-hosted rest-server or a hosted service (preset for BorgBase); no vendor affiliation | [0025](docs/adr/0025-repository-servers.md) |
| Append-only | Detected automatically and recommended; the plugin cleans up only when deletions are allowed | [0010](docs/adr/0010-retention-and-append-only.md) |
| Multisite | Not supported in 1.0; activation is refused; planned for 1.1 | [0026](docs/adr/0026-multisite-deferred.md) |
| Governance | Solo maintainer, free plugin, internal quality assurance with compensating controls | [0024](docs/adr/0024-governance-and-quality-assurance.md) |

## Requirements (planned)

| Component | Minimum | Recommended |
| --- | --- | --- |
| PHP | 8.3 with OpenSSL, JSON, mbstring; `memory_limit` ≥ 96 MB; `max_execution_time` ≥ 30 s | 8.4 or newer, `ext-curl`, 256 MB, OPcache. The JIT is not part of the recommendation: it makes chunking about five times faster, but on PHP 8.3 its tracing mode has crashed PHP during backups in the project's tests |
| WordPress | 6.7, single site (multisite planned for 1.1) | current release |
| Hosting | outbound HTTPS to the repository server; a few hundred MB of disk for caches and restores | a control-panel cron fetching `wp-cron.php` every 1–5 minutes |
| Server software | none | none |
| Repository server | a restic REST server with TLS, one repository per site: self-hosted rest-server or a hosted service | append-only mode enabled |
| restic CLI | not needed on the server; to restore on another computer, restic 0.16.1 or newer, which the recovery kit's commands need (the repository format itself is readable since 0.9) | current release |

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
