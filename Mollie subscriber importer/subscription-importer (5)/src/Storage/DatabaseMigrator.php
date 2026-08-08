<?php
/**
 * Database migrator for subscription importer tables.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Storage;

/**
 * Creates wp_subscription_migration_map and wp_mollie_test_mapping tables.
 */
class DatabaseMigrator {

	const OPTION_VERSION = 'subscription_importer_db_version';
	const MAP_TABLE      = 'subscription_migration_map';
	const TEST_MAP_TABLE = 'mollie_test_mapping';

	/**
	 * Run install if version mismatch or not set.
	 */
	public static function maybe_install() {
		$version = get_option( self::OPTION_VERSION, 0 );
		if ( (int) $version >= 1 ) {
			return;
		}
		self::install();
		update_option( self::OPTION_VERSION, 1 );
	}

	/**
	 * Create tables.
	 */
	public static function install() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$map_table = $prefix . self::MAP_TABLE;
		$sql_map   = "CREATE TABLE IF NOT EXISTS {$map_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			pronamic_subscription_id BIGINT UNSIGNED NOT NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			fluentcart_customer_id BIGINT UNSIGNED NULL,
			fluentcart_subscription_id BIGINT UNSIGNED NULL,
			mollie_subscription_id VARCHAR(64) NULL,
			created_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pronamic_subscription_id (pronamic_subscription_id)
		) $charset_collate;";

		$test_table = $prefix . self::TEST_MAP_TABLE;
		$sql_test   = "CREATE TABLE IF NOT EXISTS {$test_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			export_customer_id VARCHAR(64) NOT NULL,
			test_customer_id VARCHAR(64) NOT NULL,
			export_mandate_id VARCHAR(64) NOT NULL,
			test_mandate_id VARCHAR(64) NOT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY export_customer_id (export_customer_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_map );
		dbDelta( $sql_test );
	}

	/**
	 * Get migration map table name.
	 *
	 * @return string
	 */
	public static function get_map_table() {
		global $wpdb;
		return $wpdb->prefix . self::MAP_TABLE;
	}

	/**
	 * Get Mollie test mapping table name.
	 *
	 * @return string
	 */
	public static function get_test_mapping_table() {
		global $wpdb;
		return $wpdb->prefix . self::TEST_MAP_TABLE;
	}
}
