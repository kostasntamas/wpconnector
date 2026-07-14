<?php

/**
 * WP Connector — Hub module.
 *
 * Bootstraps the hub dashboard (menu: WP Connector Hub). Loaded by
 * wpconnector.php when the mode is 'hub' or 'both'. The WPCH_* constants the
 * classes rely on are defined here: DIR/URL point at this module folder so
 * requires and asset enqueues resolve. The plugin update checker lives in
 * wpconnector.php (plugin-wide, runs in every mode), not in this module.
 */

if (! defined('ABSPATH')) {
	exit;
}

define('WPCH_VERSION', WPC_VERSION);
define('WPCH_PLUGIN_FILE', WPC_PLUGIN_FILE);
define('WPCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPCH_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WPCH_PLUGIN_DIR . 'includes/class-wpch-icons.php';
require_once WPCH_PLUGIN_DIR . 'includes/class-wpch-endpoints.php';
require_once WPCH_PLUGIN_DIR . 'includes/class-wpch-folders.php';
require_once WPCH_PLUGIN_DIR . 'includes/class-wpch-status-checker.php';
require_once WPCH_PLUGIN_DIR . 'includes/class-wpch-admin-page.php';
require_once WPCH_PLUGIN_DIR . 'includes/class-wpch-comment-sync.php';
require_once WPCH_PLUGIN_DIR . 'includes/class-wpch-ajax.php';
require_once WPCH_PLUGIN_DIR . 'includes/class-wpch-plugin.php';

$wpch_plugin = new WPCH_Plugin();
$wpch_plugin->init();
