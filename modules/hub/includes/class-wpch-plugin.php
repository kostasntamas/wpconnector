<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Main plugin bootstrap: wires up services and registers WordPress hooks.
 */
class WPCH_Plugin
{
	private WPCH_Endpoints $endpoints;

	private WPCH_Folders $folders;

	private WPCH_Status_Checker $status_checker;

	private WPCH_Admin_Page $admin_page;

	private WPCH_Ajax $ajax;

	private WPCH_Comment_Locks $comment_locks;

	public function __construct()
	{
		$this->endpoints      = new WPCH_Endpoints();
		$this->folders        = new WPCH_Folders();
		$this->status_checker = new WPCH_Status_Checker();
		$this->admin_page     = new WPCH_Admin_Page($this->endpoints, $this->folders, $this->status_checker);
		$this->comment_locks  = new WPCH_Comment_Locks();
		$this->ajax           = new WPCH_Ajax($this->endpoints, $this->folders, $this->status_checker, $this->admin_page, $this->comment_locks);
	}

	public function init(): void
	{
		// The plugin update checker is initialized in wpconnector.php so it
		// runs in every mode, not only when the hub module is loaded.

		add_action('admin_menu', [$this->admin_page, 'register_menu']);
		add_action('admin_init', [$this->admin_page, 'maybe_handle_actions']);
		add_action('admin_init', [$this->endpoints, 'migrate_legacy']);

		$this->ajax->register();
		$this->comment_locks->register();
	}
}
