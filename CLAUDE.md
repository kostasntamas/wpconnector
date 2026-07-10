# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Merged WordPress plugin combining the former standalone `wpconnectorendpoint` and `wpconnectorhub` plugins. One install, three modes stored in the `wpc_mode` option (`endpoint` / `hub` / `both`, `''` before setup): the endpoint module exposes the key-protected `/wp-json/wpconnector/v1/status` REST route on monitored sites; the hub module is the dashboard on the main site that pulls status from a list of those endpoints.

## Structure

- `wpconnector.php` — plugin header and the `wpc_` layer: mode option helpers (`wpc_get_mode()`/`wpc_mode_has()`), activation hook (sets the `wpc_setup_redirect` flag when no mode is chosen and ensures the endpoint secret key when one is), one-time redirect to the setup screen, the Settings > WP Connector page (mode radios, POST handled on `admin_init` before output), the "no mode chosen yet" admin notice, plugin-row action link, the plugin-wide update checker (PUC from top-level `plugin-update-checker/`, pointed at the public GitHub repo `github.com/kostasntamas/wpconnector`, branch `main`; no auth needed, with a `WPC_GITHUB_TOKEN` wp-config constant as an override if the repo ever goes private — runs in every mode so endpoint-only sites update too), and finally conditional `require` of the modules. If a standalone plugin is still active (`wpc_legacy_plugin_active()`, matched by file basename so renamed folders are caught), the corresponding module is skipped with an admin notice instead of fataling on redeclared functions/classes.
- `modules/endpoint/endpoint.php` — endpoint module bootstrap: defines `WPCE_PLUGIN_DIR`, requires the `includes/` classes and inits `WPCE_Plugin`. The module is class-based (mirroring the hub's layout): `WPCE_Plugin` wires the hooks, `WPCE_Settings_Page` is the Settings > WP Connector Endpoint page, `WPCE_Rest_Controller` registers the `/wp-json/wpconnector/v1/status` route and builds the status payload (including `core_auto_update_policy()`). The `wpce_secret_key` option, page slug and REST behavior are unchanged from the standalone plugin; key generation lives in `wpc_ensure_endpoint_key()` in the main file (called on activation and whenever an endpoint mode is saved).
- `modules/hub/hub.php` — hub bootstrap. Defines the `WPCH_*` constants the class files rely on: `WPCH_PLUGIN_DIR`/`WPCH_PLUGIN_URL` point at `modules/hub/` (so requires and asset enqueues resolve), `WPCH_PLUGIN_FILE` is the merged plugin's main file, `WPCH_VERSION` mirrors `WPC_VERSION`. Then requires the `includes/` classes and inits `WPCH_Plugin`, exactly like the old `wpconnectorhub.php`.
- `modules/hub/includes|assets` — from the standalone hub, with two exceptions: the update checker was removed from `class-wpch-plugin.php` and moved to `wpconnector.php` (see above) so it runs in every mode, with the PUC library relocated to the top-level `plugin-update-checker/`; and the old `includes/functions.php` is gone — `initials()` is now a private static method on `WPCH_Admin_Page`. See the standalone hub's CLAUDE.md for the full description of the hub internals — everything there still applies.

## Health grading

`WPCH_Status_Checker::wp_status()` grades a site by its gap to the latest WordPress feature release (X.Y): up to `MAX_HEALTHY_WP_GAP` (3) releases behind — including a missing maintenance/security release within the same X.Y branch — the tier stays `good`, so the site still shows as Healthy (PHP permitting). More than 3 releases behind (or an older first version number, "Very old") is `deprecated`, which puts the site in the Needs Attention tab. PHP grading (`php_status()`) is unchanged and can still pull a site down to Fair (`aging`) or Needs Attention (`deprecated`) on its own.

## Conventions

- Short array syntax (`[]`) everywhere in `wpconnector.php` and `modules/` — the vendored `plugin-update-checker/` library is left untouched.
- Parameter and return types on functions/methods, and typed properties, PHP 7.4-compatible only (no union types / `mixed` / 8.0+ syntax anywhere, hub and endpoint alike). Values that can be array-or-`WP_Error` (endpoint statuses) stay untyped with a comment.
- Both modules are class-based; the `wpc_` layer in `wpconnector.php` stays procedural (it runs before any module loads).

## Notes

- Same option names as the standalone plugins (`wpce_secret_key`, `wpch_endpoints_list`, `wpch_folders`, ...), so data migrates automatically in both directions.
- Switching modes never deletes data — an unloaded module's options stay in the DB.
- Requires PHP 7.4+ — avoid 8.0+-only syntax in all module code.
- The GitHub repo referenced by the update checker must have version tags (or a higher `Version:` header on the `main` branch) before updates will be offered; until then PUC simply finds no updates.
