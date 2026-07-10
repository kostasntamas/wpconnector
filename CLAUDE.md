# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Merged WordPress plugin combining the former standalone `wpconnectorendpoint` and `wpconnectorhub` plugins. One install, three modes stored in the `wpc_mode` option (`endpoint` / `hub` / `both`, `''` before setup): the endpoint module exposes the key-protected `/wp-json/wpconnector/v1/status` REST route on monitored sites; the hub module is the dashboard on the main site that pulls status from a list of those endpoints.

## Structure

<<<<<<< HEAD
- `wpconnector.php` — plugin header and the `wpc_` layer: mode option helpers (`wpc_get_mode()`/`wpc_mode_has()`), activation hook (sets the `wpc_setup_redirect` flag when no mode is chosen and ensures the endpoint secret key when one is), one-time redirect to the setup screen, the Settings > WP Connector page (mode radios, POST handled on `admin_init` before output), the "no mode chosen yet" admin notice, plugin-row action link, the plugin-wide update checker (PUC from top-level `plugin-update-checker/`, pointed at the public GitHub repo `github.com/kostasntamas/wpconnector`, branch `main`; no auth needed, with a `WPC_GITHUB_TOKEN` wp-config constant as an override if the repo ever goes private — runs in every mode so endpoint-only sites update too), and finally conditional `require` of the modules. If a standalone plugin is still active (`wpc_legacy_plugin_active()`, matched by file basename so renamed folders are caught), the corresponding module is skipped with an admin notice instead of fataling on redeclared functions/classes.
=======
- `wpconnector.php` — plugin header and the `wpc_` layer: mode option helpers (`wpc_get_mode()`/`wpc_mode_has()`), activation hook (sets the `wpc_setup_redirect` flag when no mode is chosen and ensures the endpoint secret key when one is), one-time redirect to the setup screen, the Settings > WP Connector page (mode radios, POST handled on `admin_init` before output), the "no mode chosen yet" admin notice, plugin-row action link, the plugin-wide update checker (PUC from top-level `plugin-update-checker/`, pointed at the private repo `bitbucket.org/limecreatives/wpconnector`, branch `main`; the read-only OAuth consumer key/secret are hardcoded there so no per-site config is needed, with `WPC_BITBUCKET_CONSUMER_KEY`/`_SECRET` wp-config constants as an override — runs in every mode so endpoint-only sites update too), and finally conditional `require` of the modules. If a standalone plugin is still active (`wpc_legacy_plugin_active()`, matched by file basename so renamed folders are caught), the corresponding module is skipped with an admin notice instead of fataling on redeclared functions/classes.
>>>>>>> 75873caf78ece619cb723705ed3e4fc52f6cc89b
- `modules/endpoint/endpoint.php` — the endpoint module, byte-for-byte the old single-file plugin minus the plugin header and its `register_activation_hook` (key generation moved to `wpc_ensure_endpoint_key()` in the main file, called on activation and whenever an endpoint mode is saved). All `wpce_` functions, the `wpce_secret_key` option, and the Settings > WP Connector Endpoint page are unchanged.
- `modules/hub/hub.php` — hub bootstrap. Defines the `WPCH_*` constants the class files rely on: `WPCH_PLUGIN_DIR`/`WPCH_PLUGIN_URL` point at `modules/hub/` (so requires and asset enqueues resolve), `WPCH_PLUGIN_FILE` is the merged plugin's main file, `WPCH_VERSION` mirrors `WPC_VERSION`. Then requires the `includes/` classes and inits `WPCH_Plugin`, exactly like the old `wpconnectorhub.php`.
- `modules/hub/includes|assets` — copied unchanged from the standalone hub, with one exception: the update checker was removed from `class-wpch-plugin.php` and moved to `wpconnector.php` (see above) so it runs in every mode, with the PUC library relocated to the top-level `plugin-update-checker/`. See the standalone hub's CLAUDE.md for the full description of the hub internals — everything there still applies.

## Notes

- Same option names as the standalone plugins (`wpce_secret_key`, `wpch_endpoints_list`, `wpch_folders`, ...), so data migrates automatically in both directions.
- Switching modes never deletes data — an unloaded module's options stay in the DB.
- Requires PHP 7.4+ (hub constraint) — avoid 8.0+-only syntax in `modules/hub/includes/`.
- The Bitbucket repo `limecreatives/wpconnector` referenced by the update checker must exist (with version tags, or a higher `Version:` header on the `main` branch) before updates will be offered; until then PUC simply finds no updates. While the hardcoded consumer key still holds the `PASTE-` placeholder, authentication is skipped entirely, so a private repo returns nothing until the real key/secret are pasted in.
