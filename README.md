# WP Connector

WP Connector Endpoint and WP Connector Hub merged into a single plugin. On first activation you're taken to **Settings > WP Connector** to choose what the site should be:

- **Endpoint only** — the site is monitored: it exposes the key-protected REST endpoint at `/wp-json/wpconnector/v1/status`.
- **Hub only** — the main site: it gets the WP Connector Hub dashboard that pulls status from all your endpoints.
- **Both** — endpoint and hub on the same site, handy for testing.

Only the chosen module is loaded — the other one is completely inert (no hooks, REST routes, or admin pages). The mode can be changed at any time from the same settings page; nothing is deleted when switching, so all data survives a switch back.

## Upgrading from the standalone plugins

The merged plugin uses the same option names as the standalone `wpconnectorendpoint` and `wpconnectorhub` plugins, so secret keys, the endpoint list, folders, and comments are picked up automatically. If a standalone plugin is still active, its module here is skipped (to avoid a conflict) and an admin notice asks you to deactivate and delete the standalone copy.

## Structure

- `wpconnector.php` — mode option (`wpc_mode`), post-activation setup screen, and conditional loading of the modules.
- `modules/endpoint/endpoint.php` — the endpoint module (former single-file endpoint plugin).
- `modules/hub/` — the hub module: `hub.php` bootstrap plus the former hub plugin's `includes/`, `assets/`, and `plugin-update-checker/`, unchanged.
