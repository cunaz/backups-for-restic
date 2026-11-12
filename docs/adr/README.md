# Architecture Decision Records

One file per decision, numbered, never deleted. A superseded ADR gets `Status: superseded by
NNNN` and stays for history. Template:

```
# NNNN · Title
Status: proposed | accepted | superseded by NNNN
Date: YYYY-MM-DD

## Context
## Decision
## Consequences
## Alternatives considered
```

A decision is not rewritten when the code turns out differently. A wrong name or number is
corrected in place; where the implementation differs from the decision, a last section
`## As built (YYYY-MM)` says what differs and where the open part is planned.

| # | Title |
| --- | --- |
| [0001](0001-restic-rest-backend.md) | Talk to the repository server through restic's REST protocol over HTTPS |
| [0002](0002-runtime-dependencies.md) | Runtime dependencies: PHP and WordPress only |
| [0003](0003-version-floors.md) | Version floors: PHP 8.3, WordPress 6.7, repository format 1, restic CLI compatibility |
| [0004](0004-process-execution-model.md) | *(superseded by 0018)* Detached processes supervised by a tick-driven state machine |
| [0005](0005-database-dump-strategy.md) | *(superseded by 0021)* PHP-native streaming database dump into the spool |
| [0006](0006-secrets-at-rest.md) | Secrets encrypted with a salts-derived key; constants override; cached master key |
| [0007](0007-private-directory.md) | Private working directory, preferably outside the web root, verified actively |
| [0008](0008-restic-binary-provisioning.md) | *(superseded by 0016)* restic binary: system-installed first, opt-in managed download |
| [0009](0009-snapshot-layout.md) | One snapshot per run with files, database directory and manifest; stable host and tags |
| [0010](0010-retention-and-append-only.md) | Retention modes and automatic append-only detection |
| [0011](0011-scheduling.md) | Scheduling and progress on shared hosting: WP-Cron, loopback chaining, external ping |
| [0012](0012-admin-ui-without-build-step.md) | Admin UI without a build step |
| [0013](0013-restore-safety.md) | Restore to staging first; guarded apply |
| [0014](0014-file-based-job-store.md) | File-based job store |
| [0015](0015-naming-and-license.md) | Name, identifiers and license |
| [0016](0016-no-binary-provisioning.md) | The plugin never downloads, bundles or updates executables |
| [0017](0017-distribution-via-wordpress-org.md) | WordPress.org is the sole distribution channel; GitHub hosts the source |
| [0018](0018-native-php-engine.md) | A native PHP implementation of the restic repository format is the core engine |
| [0019](0019-repository-format-and-chunker.md) | Repository format version 1 for new repositories; exact restic chunker |
| [0020](0020-unlocking-and-kdf.md) | Unlocking: resumable pure-PHP scrypt once, then a cached master key |
| [0021](0021-database-streaming.md) | Database backup streams per table into the engine; no plaintext dump on disk |
| [0022](0022-maintenance-scope.md) | Maintenance in PHP: forget with restic's semantics, prune without repacking, structural check with sampled data |
| [0023](0023-restic-cli-as-conformance-oracle.md) | The restic CLI is the conformance oracle in CI |
| [0024](0024-governance-and-quality-assurance.md) | Governance: solo maintainer, free plugin, internal quality assurance |
| [0025](0025-repository-servers.md) | Repository servers: BorgBase and self-hosted restic REST servers, no vendor affiliation |
| [0026](0026-multisite-deferred.md) | Multisite is not supported in 1.0; activation is refused |
| [0027](0027-poly1305-in-pure-php.md) | Poly1305 in pure PHP; the engine needs no sodium |
| [0028](0028-built-in-fuzzer.md) | A built-in, structure-aware fuzzer instead of php-fuzzer |
| [0029](0029-wordpress-free-core.md) | A WordPress-free core behind adapters in one namespace |
| [0030](0030-lock-continuity.md) | A run trusts the repository only while its lock holds |
| [0031](0031-compact-index-and-memory-budget.md) | A compact index and a memory budget |
| [0032](0032-run-checkpoint-files-and-cleanup.md) | Complete working-file writes and cleanup by run ownership |
| [0033](0033-prune-listing-on-disk.md) | Prune validates the server pack listing on disk before deleting |
| [0036](0036-selected-file-staging.md) | Plan selected files before restoring into an owned staging directory |
