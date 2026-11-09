# 0003 · Version floors: PHP 8.3, WordPress 6.7, repository format 1, restic CLI compatibility
Status: accepted (owner decision 2026-11-09)
Date: 2026-11-09

## Context
WordPress.org statistics in November 2026: PHP 8.1 or newer runs on 76.8 % of WordPress
sites, 8.2 or newer on 67.7 %, 8.3 or newer on 45.0 %. Upstream security support ended for
PHP 8.1 in December 2025, ends for 8.2 on 31 December 2026, and lasts for 8.3 until
31 December 2027, for 8.4 until 2028 and for 8.5 until 2029. WordPress 7.1 requires PHP 7.4
and recommends 8.3. Version 1.0 of the plugin is expected in 2027. The plugin handles
secrets on shared hosting, where PHP error logs often land inside the web root, and it
implements a cryptographic format whose code benefits from strict, expressive types. There
is no restic binary at runtime (ADR 0018); restic's version matters only for operators who
restore with the CLI and for the test oracle.

## Decision
- **PHP ≥ 8.3** (`Requires PHP: 8.3`). At 1.0 the plugin runs only on PHP versions that
  still receive security fixes. The code may use readonly classes, `#[\SensitiveParameter]`
  (redaction in stack traces), typed class constants, `json_validate()` for cheap validation
  of untrusted JSON before decoding, `#[\Override]` and DNF types. JIT is not assumed;
  performance gates are measured on PHP 8.3 without JIT. CI tests 8.3, 8.4, 8.5 and 8.6 once
  it is released.
- **WordPress ≥ 6.7**, single site only in 1.0 (ADR 0026).
- **Repository format version 1** for repositories the plugin creates (ADR 0019); readable
  by every restic release since 0.9.
- **Conformance oracle**: restic 0.18.x and the latest release; reading tests also use a
  repository written by restic 0.14.

## Consequences
- Today 45 % of WordPress sites can install the plugin; the share grows until 1.0.
  WordPress refuses installation and updates on older PHP, which is a graceful failure, and
  `readme.txt` explains how to switch the PHP version in common hosting panels.
- Raw secret strings are redacted from stack traces on every supported PHP version; the
  `Secret` wrapper still protects logs and serialisation.
- The floor follows upstream end of life with a lag of about one year (8.4 in 2028),
  announced one minor release ahead.

## Alternatives considered
- **PHP 8.1**: nine percentage points more reach today, but unsupported upstream and no
  stack-trace redaction.
- **PHP 8.2**: 67.7 % reach, but unsupported upstream before 1.0 ships.
- **Repository format 2**: needs zstd, which PHP lacks (ADR 0019).
