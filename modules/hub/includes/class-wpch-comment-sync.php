<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Live refresh for the per-row comment popovers, over the Heartbeat API.
 *
 * While a popover is open, comments.js switches Heartbeat to 'fast' and sends
 * the endpoint id as 'wpch_comment_viewing' on every tick; the response
 * carries that endpoint's freshly-rendered thread plus a revision hash, so
 * messages posted by other admins appear within a few seconds. The chat model
 * is append-only (and delete-own-message), so there is nothing to lock — this
 * replaced the old advisory comment locks.
 */
class WPCH_Comment_Sync
{
	/** @var WPCH_Endpoints */
	private $endpoints;

	/** @var WPCH_Admin_Page */
	private $admin_page;

	public function __construct(WPCH_Endpoints $endpoints, WPCH_Admin_Page $admin_page)
	{
		$this->endpoints  = $endpoints;
		$this->admin_page = $admin_page;
	}

	public function register()
	{
		add_filter('heartbeat_received', [$this, 'heartbeat'], 10, 2);
	}

	public function heartbeat(array $response, array $data): array
	{
		if (empty($data['wpch_comment_viewing']) || ! current_user_can('manage_options')) {
			return $response;
		}

		$id = sanitize_text_field($data['wpch_comment_viewing']);
		foreach ($this->endpoints->get_all() as $i => $endpoint) {
			if (! isset($endpoint['id']) || $endpoint['id'] !== $id) {
				continue;
			}
			$comments = isset($endpoint['comments']) && is_array($endpoint['comments']) ? $endpoint['comments'] : [];

			ob_start();
			$this->admin_page->render_comments($i, $comments);
			$response['wpch_comment_thread'] = [
				'endpoint_id' => $id,
				'index'       => $i,
				'html'        => ob_get_clean(),
				'rev'         => WPCH_Admin_Page::comments_rev($comments),
				'count'       => count($comments),
			];
			break;
		}

		return $response;
	}
}
