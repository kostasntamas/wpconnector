<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Endpoint module bootstrap: wires up services and registers WordPress hooks.
 */
class WPCE_Plugin
{
	private WPCE_Settings_Page $settings_page;

	private WPCE_Rest_Controller $rest_controller;

	public function __construct()
	{
		$this->settings_page   = new WPCE_Settings_Page();
		$this->rest_controller = new WPCE_Rest_Controller();
	}

	public function init(): void
	{
		add_action('admin_menu', [$this->settings_page, 'register_menu']);
		add_action('rest_api_init', [$this->rest_controller, 'register_routes']);
	}
}
