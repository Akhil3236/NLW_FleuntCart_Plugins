<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCWR_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var FCWR_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return FCWR_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up subsystems.
	 */
	public function init() {
		load_plugin_textdomain( 'fluentcart-webhook-retry', false, dirname( plugin_basename( FCWR_PLUGIN_FILE ) ) . '/languages' );

		( new FCWR_Webhook_Logger() )->register();
		( new FCWR_Rest_API() )->register();

		if ( is_admin() ) {
			( new FCWR_Admin() )->register();
		}

		// Daily housekeeping.
		add_action( 'fcwr_daily_purge', array( $this, 'run_purge' ) );

		if ( ! wp_next_scheduled( 'fcwr_daily_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fcwr_daily_purge' );
		}
	}

	/**
	 * Purge old log rows according to settings.
	 */
	public function run_purge() {
		global $wpdb;

		$settings = FCWR_Settings::get();
		$days     = (int) $settings['auto_purge_days'];

		if ( $days <= 0 ) {
			return;
		}

		$table  = $wpdb->prefix . FCWR_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
