<?php
/**
 * Plugin deactivator. Intentionally light — never destroys data on deactivate.
 * Data is dropped only via uninstall.php when the user removes the plugin.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCWR_Deactivator {

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'fcwr_daily_purge' );
	}
}
