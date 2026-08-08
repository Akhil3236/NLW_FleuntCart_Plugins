<?php
/**
 * Creates Mollie subscription with startDate = next_payment_date (no immediate charge).
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Services;

use SubscriptionImporter\Models\ImportSubscriptionRecord;
use SubscriptionImporter\Validation\IntervalConverter;

/**
 * MollieSubscriptionCreator
 */
class MollieSubscriptionCreator {

	/** @var MollieApiClient */
	private $mollie;

	/**
	 * @param MollieApiClient|null $mollie Mollie API client (test-only).
	 */
	public function __construct( MollieApiClient $mollie = null ) {
		$this->mollie = $mollie ?: new MollieApiClient( null, 'test' );
	}

	/**
	 * Create Mollie subscription. startDate is set to next_payment_date so no immediate payment.
	 *
	 * @param ImportSubscriptionRecord $record         Export record.
	 * @param object                    $fc_subscription FluentCart Subscription model (with id, item_name, recurring_total, currency).
	 * @param string|null               $override_customer_id Optional test customer ID (when using test mapping).
	 * @param string|null               $override_mandate_id  Optional test mandate ID (when using test mapping).
	 * @return array|\WP_Error Mollie subscription response or WP_Error.
	 */
	public function create( ImportSubscriptionRecord $record, $fc_subscription, $override_customer_id = null, $override_mandate_id = null ) {
		


                $customer_id = $override_customer_id !== null ? trim( $override_customer_id ) : ( isset( $record->mollie['customer_id'] ) ? trim( $record->mollie['customer_id'] ) : '' );
		$mandate_id  = $override_mandate_id !== null ? trim( $override_mandate_id ) : ( isset( $record->mollie['mandate_id'] ) ? trim( $record->mollie['mandate_id'] ) : '' );

		if ( ! $customer_id || ! $mandate_id ) {
			return new \WP_Error( 'mollie_data', 'Missing Mollie customer_id or mandate_id.' );
		}

		$currency = isset( $record->subscription['amount_currency'] ) ? strtoupper( trim( $record->subscription['amount_currency'] ) ) : 'EUR';
		$amount_value = isset( $record->subscription['amount_value'] ) ? $record->subscription['amount_value'] : '0';
		$amount_decimal = number_format( (float) $amount_value, 2, '.', '' );

		$interval_iso = isset( $record->subscription['interval_iso'] ) ? $record->subscription['interval_iso'] : 'P1M';
		$interval_mollie = IntervalConverter::to_mollie( $interval_iso ) ?: '1 month';

		$next_payment = isset( $record->subscription['next_payment_date'] ) ? trim( $record->subscription['next_payment_date'] ) : '';
		if ( ! $next_payment ) {
			return new \WP_Error( 'mollie_data', 'Missing next_payment_date for startDate.' );
		}
		$ts = strtotime( $next_payment );
		if ( $ts === false ) {
			return new \WP_Error( 'mollie_data', 'Invalid next_payment_date format.' );
		}
		$start_date = gmdate( 'Y-m-d', $ts );

		$description = isset( $fc_subscription->item_name ) ? $fc_subscription->item_name : ( 'Subscription #' . $record->pronamic_subscription_id );
		$webhook_url = $this->get_webhook_url();

		$payload = array(
			'amount'      => array(
				'value'    => $amount_decimal,
				'currency' => $currency,
			),
			'interval'    => $interval_mollie,
			'startDate'   => $start_date,
			'mandateId'   => $mandate_id,
			'description' => $description,
			'webhookUrl'  => $webhook_url,
			'metadata'   => array(
				'subscription_hash' => isset( $fc_subscription->uuid ) ? $fc_subscription->uuid : '',
				'pronamic_id'       => (string) $record->pronamic_subscription_id,
			),
		);

		$response = $this->mollie->create_subscription( $customer_id, $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * Same webhook URL as FluentCart Mollie gateway.
	 *
	 * @return string
	 */
	private function get_webhook_url() {
		$url = site_url( '?fluent-cart=fct_payment_listener_ipn&method=mollie' );
		if ( function_exists( 'apply_filters' ) ) {
			return (string) apply_filters( 'fluent_cart/mollie/webhook_url', $url );
		}
		return $url;
	}
}
