<?php
/**
 * Ensures a single "Imported subscription" product and variation exist for migration.
 * Stores IDs in options.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Services;

/**
 * MigrationProductResolver
 */
class MigrationProductResolver {

	const OPTION_PRODUCT_ID    = 'subscription_importer_migration_product_id';
	const OPTION_VARIATION_ID  = 'subscription_importer_migration_variation_id';
	const PRODUCT_TITLE        = 'Imported subscription';
	const VARIATION_TITLE      = 'Default';

	/**
	 * Get product ID and variation ID. Create if not exist.
	 *
	 * @return array{ product_id: int, variation_id: int }|null Null if FluentCart not available or creation failed.
	 */
	public function get_or_create() {
		$product_id   = (int) get_option( self::OPTION_PRODUCT_ID, 0 );
		$variation_id = (int) get_option( self::OPTION_VARIATION_ID, 0 );

		if ( $product_id && $variation_id ) {
			// Verify they still exist.
			if ( get_post_status( $product_id ) !== false && $this->variation_exists( $variation_id ) ) {
				return array( 'product_id' => $product_id, 'variation_id' => $variation_id );
			}
		}

		if ( ! class_exists( 'FluentCart\App\CPT\FluentProducts' ) || ! class_exists( 'FluentCart\App\Models\ProductVariation' ) ) {
			return null;
		}

		$cpt = 'fluent-products';

		$product_id = wp_insert_post( array(
			'post_title'   => self::PRODUCT_TITLE,
			'post_type'    => $cpt,
			'post_status'  => 'publish',
			'post_author'  => 1,
		), true );

		if ( is_wp_error( $product_id ) || ! $product_id ) {
			return null;
		}

		$variation = \FluentCart\App\Models\ProductVariation::query()->create( array(
			'post_id'           => $product_id,
			'variation_title'   => self::VARIATION_TITLE,
			'payment_type'      => 'subscription',
			'item_status'       => 'published',
			'item_price'        => 0,
			'sold_individually' => 1,
			'other_info'        => array( 'repeat_interval' => 'monthly', 'times' => 0, 'recurring_total' => 0, 'trial_days' => 0 ),
		) );

		if ( ! $variation || empty( $variation->id ) ) {
			wp_delete_post( $product_id, true );
			return null;
		}

		update_option( self::OPTION_PRODUCT_ID, $product_id );
		update_option( self::OPTION_VARIATION_ID, $variation->id );

		return array( 'product_id' => $product_id, 'variation_id' => $variation->id );
	}

	/**
	 * Check if variation row exists.
	 *
	 * @param int $variation_id Variation ID.
	 * @return bool
	 */
	private function variation_exists( $variation_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}fct_product_variations WHERE id = %d",
			$variation_id
		) );
		return $id !== null;
	}
}
