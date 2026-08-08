<?php
/**
 * Uninstall — runs when the user deletes the plugin via WP admin.
 * Drops the custom table and removes options.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'fcwr_webhook_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'fcwr_settings' );
delete_option( 'fcwr_db_version' );

wp_clear_scheduled_hook( 'fcwr_daily_purge' );
