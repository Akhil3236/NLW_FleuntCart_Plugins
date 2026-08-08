<?php
/**
 * Read/write migration map and support resume.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Storage;

/**
 * ImportStateRepository
 */
class ImportStateRepository {

	/** @var string */
	private $table;

	public function __construct() {
		$this->table = DatabaseMigrator::get_map_table();
	}

	/**
	 * Check if pronamic_subscription_id is already imported successfully.
	 * Rows with status='failed' are NOT considered imported so --resume retries them.
	 *
	 * @param int $pronamic_subscription_id Pronamic subscription ID.
	 * @return bool True if already successfully imported.
	 */
	public function is_imported( $pronamic_subscription_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM {$this->table} WHERE pronamic_subscription_id = %d LIMIT 1",
			$pronamic_subscription_id
		), ARRAY_A );

		if ( $row === null ) {
			return false;
		}
		// If row exists but is marked failed, treat as not imported so it can be retried.
		if ( isset( $row['status'] ) && $row['status'] === 'failed' ) {
			return false;
		}
		return true;
	}

	/**
	 * Claim a record (insert row with only pronamic_subscription_id). Prevents race duplicate.
	 * Returns true if inserted, false if duplicate (already claimed).
	 *
	 * @param int $pronamic_subscription_id Pronamic subscription ID.
	 * @return bool True if row was inserted, false if duplicate key.
	 */
	public function claim( $pronamic_subscription_id ) {
		global $wpdb;

		// If a failed row already exists, delete it first so we can re-claim cleanly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM {$this->table} WHERE pronamic_subscription_id = %d LIMIT 1",
			$pronamic_subscription_id
		), ARRAY_A );

		if ( $existing && isset( $existing['status'] ) && $existing['status'] === 'failed' ) {
			$this->delete_mapping_row( $pronamic_subscription_id );
		} elseif ( $existing ) {
			// Already claimed and not failed — duplicate.
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$this->table,
			array(
				'pronamic_subscription_id' => $pronamic_subscription_id,
				'status'                   => 'pending',
				'created_at'               => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s' )
		);
		return $result !== false;
	}

	/**
	 * Mark a claimed row as failed so --resume retries it.
	 * Called on any failure after claim() — Mollie failure, FluentCart failure, etc.
	 *
	 * @param int    $pronamic_subscription_id Pronamic subscription ID.
	 * @param string $reason                   Short failure reason for debugging.
	 * @return bool
	 */
	public function mark_failed( $pronamic_subscription_id, $reason = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			array(
				'status'       => 'failed',
				'fail_reason'  => substr( $reason, 0, 500 ),
			),
			array( 'pronamic_subscription_id' => $pronamic_subscription_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return $result !== false;
	}

	/**
	 * Update mapping row with created IDs. Call after successful User/Customer/Subscription/Mollie create.
	 *
	 * @param int   $pronamic_subscription_id Pronamic subscription ID.
	 * @param array $ids                      Keys: wp_user_id, fluentcart_customer_id, fluentcart_subscription_id, mollie_subscription_id.
	 * @return bool
	 */
	public function update_mapping( $pronamic_subscription_id, array $ids ) {
		global $wpdb;
		$data = array( 'status' => 'imported' );
		$fmt  = array( '%s' );

		if ( isset( $ids['wp_user_id'] ) ) {
			$data['wp_user_id'] = $ids['wp_user_id'];
			$fmt[] = '%d';
		}
		if ( isset( $ids['fluentcart_customer_id'] ) ) {
			$data['fluentcart_customer_id'] = $ids['fluentcart_customer_id'];
			$fmt[] = '%d';
		}
		if ( isset( $ids['fluentcart_subscription_id'] ) ) {
			$data['fluentcart_subscription_id'] = $ids['fluentcart_subscription_id'];
			$fmt[] = '%d';
		}
		if ( isset( $ids['mollie_subscription_id'] ) ) {
			$data['mollie_subscription_id'] = $ids['mollie_subscription_id'];
			$fmt[] = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'pronamic_subscription_id' => $pronamic_subscription_id ),
			$fmt,
			array( '%d' )
		);
		return $result !== false;
	}

	/**
	 * Get all migration map rows (for rollback). Optionally limit.
	 *
	 * @param int $limit 0 = no limit.
	 * @return array[]
	 */
	public function get_all_for_rollback( $limit = 0 ) {
		global $wpdb;
		$sql = "SELECT id, pronamic_subscription_id, wp_user_id, fluentcart_customer_id, fluentcart_subscription_id, mollie_subscription_id FROM {$this->table} ORDER BY id ASC";
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete a single migration map row by pronamic_subscription_id.
	 *
	 * @param int $pronamic_subscription_id Pronamic subscription ID.
	 * @return bool
	 */
	public function delete_mapping_row( $pronamic_subscription_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$this->table,
			array( 'pronamic_subscription_id' => $pronamic_subscription_id ),
			array( '%d' )
		);
		return $result !== false;
	}

	/**
	 * Get test mapping: export customer/mandate ID -> test customer/mandate ID.
	 *
	 * @param string $export_customer_id Export Mollie customer ID.
	 * @param string $export_mandate_id  Export Mollie mandate ID.
	 * @return array{ test_customer_id: string, test_mandate_id: string }|null
	 */
	public static function get_test_mapping( $export_customer_id, $export_mandate_id ) {
		global $wpdb;
		$table = DatabaseMigrator::get_test_mapping_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT test_customer_id, test_mandate_id FROM {$table} WHERE export_customer_id = %s AND export_mandate_id = %s LIMIT 1",
			$export_customer_id,
			$export_mandate_id
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Store test mapping row.
	 *
	 * @param string $export_customer_id Export Mollie customer ID.
	 * @param string $test_customer_id   Test Mollie customer ID.
	 * @param string $export_mandate_id  Export Mollie mandate ID.
	 * @param string $test_mandate_id    Test Mollie mandate ID.
	 * @return bool
	 */
	public static function save_test_mapping( $export_customer_id, $test_customer_id, $export_mandate_id, $test_mandate_id ) {
		global $wpdb;
		$table = DatabaseMigrator::get_test_mapping_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->insert(
			$table,
			array(
				'export_customer_id' => $export_customer_id,
				'test_customer_id'   => $test_customer_id,
				'export_mandate_id'  => $export_mandate_id,
				'test_mandate_id'    => $test_mandate_id,
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		) !== false;
	}

	/**
	 * Get distinct test_customer_id from test mapping (for rollback: delete in Mollie).
	 *
	 * @return string[]
	 */
	public static function get_all_test_customer_ids() {
		global $wpdb;
		$table = DatabaseMigrator::get_test_mapping_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( "SELECT DISTINCT test_customer_id FROM {$table}" );
		return is_array( $rows ) ? array_filter( $rows ) : array();
	}

	/**
	 * Truncate test mapping table.
	 *
	 * @return bool
	 */
	public static function truncate_test_mapping() {
		global $wpdb;
		$table = DatabaseMigrator::get_test_mapping_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->query( "TRUNCATE TABLE {$table}" ) !== false;
	}
}
