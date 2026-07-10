<?php

if (! defined('ABSPATH')) {
	exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Main plugin bootstrap: wires up services and registers WordPress hooks.
 */
class WPCH_Plugin
{
	/** @var WPCH_Endpoints */
	private $endpoints;

	/** @var WPCH_Folders */
	private $folders;

	/** @var WPCH_Status_Checker */
	private $status_checker;

	/** @var WPCH_Admin_Page */
	private $admin_page;

	/** @var WPCH_Ajax */
	private $ajax;

	/** @var WPCH_Comment_Locks */
	private $comment_locks;

	public function __construct()
	{
		$this->endpoints      = new WPCH_Endpoints();
		$this->folders        = new WPCH_Folders();
		$this->status_checker = new WPCH_Status_Checker();
		$this->admin_page     = new WPCH_Admin_Page($this->endpoints, $this->folders, $this->status_checker);
		$this->comment_locks  = new WPCH_Comment_Locks();
		$this->ajax           = new WPCH_Ajax($this->endpoints, $this->folders, $this->status_checker, $this->admin_page, $this->comment_locks);
	}

	public function init()
	{
		$this->init_update_checker();

		add_action('admin_menu', [$this->admin_page, 'register_menu']);
		add_action('admin_init', [$this->admin_page, 'maybe_handle_actions']);
		add_action('admin_init', [$this->endpoints, 'migrate_legacy']);

		$this->ajax->register();
		$this->comment_locks->register();
	}

	private function init_update_checker()
	{
		require_once WPCH_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

		// Checks github.com/kostasntamas/wpconnector for new Releases and offers
		// them as normal plugin updates. Must NOT point at the old standalone
		// wpconnectorhub repo, or its releases would replace this merged plugin.
		// If the repo is private, a token with at least read-only "Contents"
		// access must be defined in wp-config.php:
		// define('WPCH_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx');
		$update_checker = PucFactory::buildUpdateChecker(
			'https://github.com/kostasntamas/wpconnector/',
			WPCH_PLUGIN_FILE,
			'wpconnector'
		);
		$update_checker->setBranch('main');
		// if (defined('WPCH_GITHUB_TOKEN') && WPCH_GITHUB_TOKEN) {
		// 	$update_checker->setAuthentication(WPCH_GITHUB_TOKEN);
		// }
	}
}
