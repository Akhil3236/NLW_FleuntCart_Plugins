<?php
/**
 * Plugin activator.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCWR_Activator {

	/**
	 * Runs on plugin activation. Creates the webhook log table.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . FCWR_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED DEFAULT NULL,
			url TEXT NOT NULL,
			method VARCHAR(10) NOT NULL DEFAULT 'POST',
			request_headers LONGTEXT NULL,
			request_body LONGTEXT NULL,
			response_code INT(11) DEFAULT NULL,
			response_body LONGTEXT NULL,
			error_message TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'failed',
			retry_count INT(11) NOT NULL DEFAULT 0,
			parent_log_id BIGINT(20) UNSIGNED DEFAULT NULL,
			retried_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_order_id (order_id),
			KEY idx_status (status),
			KEY idx_created_at (created_at),
			KEY idx_parent (parent_log_id)
		) {$charset_collate};";

		dbDelta( $sql );

		// Default options.
		if ( false === get_option( 'fcwr_settings' ) ) {
			add_option( 'fcwr_settings', array(
				'watch_urls'        => '',          // newline-separated URL patterns to capture
				'log_successes'     => 0,           // store successful webhooks too?
				'max_retries'       => 10,          // hard cap per log entry
				'retry_window_sec'  => 60,          // rate limit window
				'retries_per_window'=> 5,           // max retries per window per log
				'auto_purge_days'   => 30,          // delete entries older than this (0 = never)
			) );
		}

		// Store DB version for future migrations.
		update_option( 'fcwr_db_version', '1.0.0' );
	}
}
