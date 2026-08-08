<?php
/**
 * Creates FluentCart placeholder order and subscription for an imported record.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Services;

use SubscriptionImporter\Models\ImportSubscriptionRecord;
use SubscriptionImporter\Validation\IntervalConverter;

/**
 * FluentCartSubscriptionCreator
 */
class FluentCartSubscriptionCreator {

	/** @var MigrationProductResolver */
	private $product_resolver;

	public function __construct( MigrationProductResolver $product_resolver = null ) {
		$this->product_resolver = $product_resolver ?: new MigrationProductResolver();
	}

	/**
	 * Create placeholder order and subscription. Returns array with order_id, subscription_id or WP_Error.
	 *
	 * @param int                      $fluentcart_customer_id FluentCart customer ID.
	 * @param ImportSubscriptionRecord $record                 Export record.
	 * @param string                   $currency               Currency code (e.g. EUR).
	 * @param int                      $amount_cents           Recurring amount in cents.
	 * @param string|null              $test_customer_id       Mapped Mollie test customer ID (replaces dummy).
	 * @param string|null              $test_mandate_id        Mapped Mollie test mandate ID (replaces dummy).
	 * @return array{ order_id: int, subscription_id: int, subscription: object }|\WP_Error
	 */
	public function create( $fluentcart_customer_id, ImportSubscriptionRecord $record, $currency = 'EUR', $amount_cents = 0, $test_customer_id = null, $test_mandate_id = null ) {
		if ( ! class_exists( 'FluentCart\App\Models\Order' ) || ! class_exists( 'FluentCart\App\Models\Subscription' ) ) {
			return new \WP_Error( 'fluentcart_missing', 'FluentCart Order or Subscription model not found.' );
		}

		$ids = $this->product_resolver->get_or_create();
		if ( ! $ids ) {
			return new \WP_Error( 'migration_product', 'Could not get or create migration product/variation.' );
		}

		$interval_iso     = isset( $record->subscription['interval_iso'] ) ? $record->subscription['interval_iso'] : 'P1M';
		$billing_interval = IntervalConverter::to_fluentcart( $interval_iso ) ?: 'monthly';

		$next_billing = isset( $record->subscription['next_payment_date'] ) ? $record->subscription['next_payment_date'] : '';
		if ( $next_billing ) {
			$ts = strtotime( $next_billing );
			if ( $ts !== false ) {
				$next_billing = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$order = \FluentCart\App\Models\Order::query()->create( array(
			'type'                 => 'subscription',
			'customer_id'          => $fluentcart_customer_id,
			'payment_method'       => 'mollie',
			'payment_method_title' => 'Mollie',
			'payment_status'       => \FluentCart\App\Helpers\Status::PAYMENT_PAID,
			'status'               => \FluentCart\App\Helpers\Status::ORDER_COMPLETED,
			'currency'             => $currency,
			'subtotal'             => 0,
			'tax_total'            => 0,
			'total_amount'         => 0,
			'total_paid'           => 0,
			'total_refund'         => 0,
			'mode'                 => 'test',
			'note'                 => 'Subscription import - Pronamic ID ' . $record->pronamic_subscription_id,
		) );

		if ( ! $order || empty( $order->id ) ) {
			return new \WP_Error( 'order_create', 'Failed to create placeholder order.' );
		}

		$item_name = 'Imported subscription #' . $record->pronamic_subscription_id;

		// Use mapped test IDs if available; fall back to export IDs (dummy) only as a last resort.
		$export_customer_id = isset( $record->mollie['customer_id'] ) ? $record->mollie['customer_id'] : '';
		$export_mandate_id  = isset( $record->mollie['mandate_id'] ) ? $record->mollie['mandate_id'] : '';

		$stored_customer_id = $test_customer_id ?: $export_customer_id;
		$stored_mandate_id  = $test_mandate_id  ?: $export_mandate_id;

		$subscription = \FluentCart\App\Models\Subscription::query()->create( array(
			'customer_id'             => $fluentcart_customer_id,
			'parent_order_id'         => $order->id,
			'product_id'              => $ids['product_id'],
			'item_name'               => $item_name,
			'variation_id'            => $ids['variation_id'],
			'billing_interval'        => $billing_interval,
			'signup_fee'              => 0,
			'quantity'                => 1,
			'recurring_amount'        => $amount_cents,
			'recurring_tax_total'     => 0,
			'recurring_total'         => $amount_cents,
			'bill_times'              => 0,
			'bill_count'              => 0,
			'next_billing_date'       => $next_billing,
			'trial_days'              => 0,
			'collection_method'       => 'automatic',
			// Store mapped test IDs so FluentCart admin matches Mollie Test dashboard.
			'vendor_customer_id'      => $stored_customer_id,
			'vendor_plan_id'          => null,
			'vendor_subscription_id'  => null,
			'status'                  => \FluentCart\App\Helpers\Status::SUBSCRIPTION_ACTIVE,
			'current_payment_method'  => 'mollie',
			'config'                  => array(
				'currency'                     => $currency,
				'mandate_id'                   => $stored_mandate_id,
				'imported_from_pronamic_id'    => $record->pronamic_subscription_id,
				// Keep original export IDs for audit trail.
				'export_dummy_customer_id'     => $export_customer_id,
				'export_dummy_mandate_id'      => $export_mandate_id,
			),
		) );

		if ( ! $subscription || empty( $subscription->id ) ) {
			// Cleanup order if subscription failed.
			if ( $order && ! empty( $order->id ) ) {
				\FluentCart\App\Models\Order::query()->where( 'id', $order->id )->delete();
			}
			return new \WP_Error( 'subscription_create', 'Failed to create FluentCart subscription.' );
		}

		return array(
			'order_id'        => (int) $order->id,
			'subscription_id' => (int) $subscription->id,
			'subscription'    => $subscription,
		);
	}
}
