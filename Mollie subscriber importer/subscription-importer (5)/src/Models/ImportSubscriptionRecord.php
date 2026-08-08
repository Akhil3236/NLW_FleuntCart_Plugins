<?php
/**
 * DTO for a single subscription record from export JSON.
 * Compatible with subscription-exporter schema.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Models;

/**
 * ImportSubscriptionRecord
 */
class ImportSubscriptionRecord {

	/** @var int */
	public $pronamic_subscription_id;

	/** @var string */
	public $pronamic_status;

	/** @var array */
	public $customer = array(
		'email'      => '',
		'first_name' => '',
		'last_name'  => '',
		'full_name'  => '',
		'country'    => '',
	);

	/** @var array */
	public $subscription = array(
		'amount_value'      => '',
		'amount_currency'   => '',
		'interval_iso'      => '',
		'next_payment_date' => '',
	);

	/** @var array */
	public $mollie = array(
		'customer_id'             => '',
		'mandate_id'              => '',
		'mandate_status_at_export' => '',
		'mandate_checked_at'      => '',
	);

	/** @var array */
	public $source = array(
		'system'   => '',
		'entry_id' => '',
	);

	/** @var array */
	public $flags = array(
		'has_email'               => false,
		'has_customer_id'          => false,
		'has_mandate_id'          => false,
		'is_safe_for_import'      => false,
		'mandate_valid_at_export' => false,
	);

	/**
	 * Create from decoded JSON row.
	 *
	 * @param array $data Single subscription array from export.
	 * @return self
	 */
	public static function from_array( array $data ) {
		$record = new self();

		$record->pronamic_subscription_id = isset( $data['pronamic_subscription_id'] ) ? (int) $data['pronamic_subscription_id'] : 0;
		$record->pronamic_status          = isset( $data['pronamic_status'] ) ? sanitize_text_field( $data['pronamic_status'] ) : '';

		if ( ! empty( $data['customer'] ) && is_array( $data['customer'] ) ) {
			$record->customer = array(
				'email'      => isset( $data['customer']['email'] ) ? sanitize_email( $data['customer']['email'] ) : '',
				'first_name' => isset( $data['customer']['first_name'] ) ? sanitize_text_field( $data['customer']['first_name'] ) : '',
				'last_name'  => isset( $data['customer']['last_name'] ) ? sanitize_text_field( $data['customer']['last_name'] ) : '',
				'full_name'  => isset( $data['customer']['full_name'] ) ? sanitize_text_field( $data['customer']['full_name'] ) : '',
				'country'    => isset( $data['customer']['country'] ) ? sanitize_text_field( $data['customer']['country'] ) : '',
			);
		}

		if ( ! empty( $data['subscription'] ) && is_array( $data['subscription'] ) ) {
			$record->subscription = array(
				'amount_value'      => isset( $data['subscription']['amount_value'] ) ? sanitize_text_field( $data['subscription']['amount_value'] ) : '',
				'amount_currency'   => isset( $data['subscription']['amount_currency'] ) ? sanitize_text_field( $data['subscription']['amount_currency'] ) : '',
				'interval_iso'      => isset( $data['subscription']['interval_iso'] ) ? sanitize_text_field( $data['subscription']['interval_iso'] ) : '',
				'next_payment_date' => isset( $data['subscription']['next_payment_date'] ) ? sanitize_text_field( $data['subscription']['next_payment_date'] ) : '',
			);
		}

		if ( ! empty( $data['mollie'] ) && is_array( $data['mollie'] ) ) {
			$record->mollie = array(
				'customer_id'             => isset( $data['mollie']['customer_id'] ) ? sanitize_text_field( $data['mollie']['customer_id'] ) : '',
				'mandate_id'              => isset( $data['mollie']['mandate_id'] ) ? sanitize_text_field( $data['mollie']['mandate_id'] ) : '',
				'mandate_status_at_export' => isset( $data['mollie']['mandate_status_at_export'] ) ? sanitize_text_field( $data['mollie']['mandate_status_at_export'] ) : '',
				'mandate_checked_at'      => isset( $data['mollie']['mandate_checked_at'] ) ? sanitize_text_field( $data['mollie']['mandate_checked_at'] ) : '',
			);
		}

		if ( ! empty( $data['source'] ) && is_array( $data['source'] ) ) {
			$record->source = array(
				'system'   => isset( $data['source']['system'] ) ? sanitize_text_field( $data['source']['system'] ) : '',
				'entry_id' => isset( $data['source']['entry_id'] ) ? sanitize_text_field( $data['source']['entry_id'] ) : '',
			);
		}

		if ( ! empty( $data['flags'] ) && is_array( $data['flags'] ) ) {
			$record->flags = array(
				'has_email'               => ! empty( $data['flags']['has_email'] ),
				'has_customer_id'         => ! empty( $data['flags']['has_customer_id'] ),
				'has_mandate_id'           => ! empty( $data['flags']['has_mandate_id'] ),
				'is_safe_for_import'       => ! empty( $data['flags']['is_safe_for_import'] ),
				'mandate_valid_at_export'  => ! empty( $data['flags']['mandate_valid_at_export'] ),
			);
		}

		return $record;
	}
}
