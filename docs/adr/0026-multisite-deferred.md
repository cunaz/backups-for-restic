# 0026 · Multisite is not supported in 1.0; activation is refused
Status: accepted (owner decision 2026-11-09)
Date: 2026-11-09

## Context
Multisite needs network-level settings, a network admin UI, `manage_network_options` and,
hardest of all, scheduling: WP-Cron runs per site, so jobs scheduled on the main site stall
when the main site gets little traffic. A per-site activation would let a site
administrator back up or restore the files and database of the whole network, which is a
privilege escalation. The owner deferred multisite to 1.1.

## Decision
- On multisite the activation hook refuses both network-wide and per-site activation with a
  clear message.
- A runtime guard (`is_multisite()`) keeps the plugin inert and shows a notice to super
  administrators if it was activated by other means, for example as a must-use plugin or
  through WP-CLI.
- `readme.txt` states "Multisite: not supported yet, planned for 1.1".
- Settings storage, capability checks and scheduling stay behind adapters, so 1.1 adds
  network support without touching the engine: network-only activation, site options,
  `manage_network_options`, scheduling on the main site with loopback chaining and
  cron-ping guidance.

## Consequences
- A smaller test matrix and full focus on the engine for 1.0.
- Multisite operators wait for 1.1 or back up the network with the restic CLI on a server
  they control.

## Alternatives considered
- **Network-wide support in 1.0**: deferred by the owner.
- **Allowing per-site activation**: unsafe; rejected permanently.
