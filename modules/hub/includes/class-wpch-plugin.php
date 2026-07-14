<?php

if (! defined('ABSPATH')) {
	exit;
}

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

	/** @var WPCH_Comment_Sync */
	private $comment_sync;

	public function __construct()
	{
		$this->endpoints      = new WPCH_Endpoints();
		$this->folders        = new WPCH_Folders();
		$this->status_checker = new WPCH_Status_Checker();
		$this->admin_page     = new WPCH_Admin_Page($this->endpoints, $this->folders, $this->status_checker);
		$this->comment_sync   = new WPCH_Comment_Sync($this->endpoints, $this->admin_page);
		$this->ajax           = new WPCH_Ajax($this->endpoints, $this->folders, $this->status_checker, $this->admin_page);
	}

	public function init()
	{
		// The plugin update checker is initialized in wpconnector.php so it
		// runs in every mode, not only when the hub module is loaded.

		add_action('admin_menu', [$this->admin_page, 'register_menu']);
		add_action('admin_init', [$this->admin_page, 'maybe_handle_actions']);
		add_action('admin_init', [$this->endpoints, 'migrate_legacy']);

		$this->ajax->register();
		$this->comment_sync->register();
	}
}
