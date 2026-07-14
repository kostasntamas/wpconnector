<?php

/**
 * Plugin Name: WP Connector
 * Description: WP Connector Endpoint and Hub combined. Choose per site whether it acts as a monitored Endpoint, as the monitoring Hub, or as both (for testing).
 * Version: 2.2.8.5
 * Requires at least: 4.7
 * Requires PHP: 7.0
 */

if (! defined('ABSPATH')) {
	exit;
}

/*
 * This loader deliberately avoids type declarations, closures and short array
 * syntax so it still parses on PHP 5.2. Old WordPress cores (pre-5.1) ignore
 * the "Requires PHP" header and activate the plugin anyway; on such servers
 * the check below shows an admin notice instead of a fatal error. All real
 * code lives in includes/main.php, loaded only when the check passes.
 */

if (version_compare(PHP_VERSION, '7.0', '<')) {
	function wpc_php_version_notice()
	{
		printf(
			'<div class="notice notice-error error"><p><strong>WP Connector</strong> requires PHP 7.0 or newer. This server is running PHP %s, so the plugin has not been loaded. Please ask your host to upgrade PHP.</p></div>',
			esc_html(PHP_VERSION)
		);
	}
	add_action('admin_notices', 'wpc_php_version_notice');
	return;
}

define('WPC_VERSION', '2.2.8.5');
define('WPC_PLUGIN_FILE', __FILE__);
define('WPC_PLUGIN_DIR', plugin_dir_path(__FILE__));

require WPC_PLUGIN_DIR . 'includes/main.php';
