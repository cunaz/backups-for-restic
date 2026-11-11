# 0012 · Admin UI without a build step
Status: accepted (revised: classic scripts because of translation support)
Date: 2026-11-09

## Context
The admin UI needs live progress, a snapshot browser and asynchronous actions. React via
`@wordpress/scripts` brings a large toolchain and a build step; the project wants few
dependencies and readable code. JavaScript strings must be translatable on
translate.wordpress.org, and WordPress delivers those translations to scripts registered
with `wp_set_script_translations()`, which works on classic script handles.

## Decision
- Server-rendered PHP templates are the baseline; plain JavaScript files (ES2020, one per
  screen, no bundler, no transpiler) enhance them.
- Scripts are registered as **classic scripts** with `wp_enqueue_script` and the `defer`
  strategy, depend on core's `wp-api-fetch`, `wp-i18n` and `wp-dom-ready`, and receive
  translations through `wp_set_script_translations()`. Script modules are revisited once
  WordPress offers equivalent translation loading for them on every supported version.
- Data access goes exclusively through the plugin's REST API with `wp.apiFetch`; nonce and
  root URL are handled by core. No third-party JavaScript, no CDNs.
- Styling uses core admin classes plus a small scoped stylesheet.

## Consequences
- Contributors edit JavaScript and reload; CI runs ESLint only.
- No component library: tables, forms and progress bars. A feature for a backup tool.
- Every UI action has a form-post fallback with nonces.

## Alternatives considered
- **React via `@wordpress/scripts`**: thousands of transitive packages for a few screens.
- **Script modules**: modern, but they do not use `wp_set_script_translations()`.
- **Alpine or htmx**: external code or a vendored dependency to track.

## As built (2026-11)
- One script, `assets/js/admin.js`, builds every screen (wizard, dashboard, snapshots,
  settings, environment, logs) from REST responses. PHP renders only the frame: heading,
  tabs, a `noscript` notice and an empty container (`WordPress\AdminPage`). There are no
  templates per screen and no script per screen.
- No action has a form-post fallback. Without JavaScript the page shows "This page needs
  JavaScript." and nothing else; a fallback is not planned yet.
- Since 2026-11-10 CI runs ESLint on the script, with the rulesets `esnext`, `custom` and
  `i18n` of `@wordpress/eslint-plugin` and ES2020 as the syntax limit
  ([development guide](../04-development-and-quality.md) §3). The linter is a development
  tool installed with npm; the script still has no build step and loads no third-party
  code. Until then CI only checked its syntax with `node --check`.
- Since 2026-11-11 CI also drives the page in a browser (Playwright, a pinned development
  dependency like the linter, [development guide](../04-development-and-quality.md) §5.5).
  The tests click through the script as it is shipped; nothing is compiled for them, and
  the package for WordPress.org contains neither them nor their tool.
