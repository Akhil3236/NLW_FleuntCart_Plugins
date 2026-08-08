<?php
/**
 * Validates a subscription record: email, mandate, interval, amount, next_payment.
 * Optionally checks Mollie mandate status (valid).
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Validation;

use SubscriptionImporter\Models\ImportSubscriptionRecord;
use SubscriptionImporter\Logging\ImportLogger;
use SubscriptionImporter\Services\MollieApiClient;
use SubscriptionImporter\Storage\ImportStateRepository;

/**
 * SubscriptionValidator
 */
class SubscriptionValidator {

	/** @var ImportLogger */
	private $logger;

	/** @var bool Whether to call Mollie API to verify mandate is valid. */
	private $check_mandate;

	/** @var MollieApiClient|null */
	private $mollie;

	/** @var string Mollie mode (test-only in this importer). */
	private $mollie_mode;

	/**
	 * @param ImportLogger       $logger        Logger.
	 * @param bool               $check_mandate If true, GET mandate from Mollie and require status 'valid'.
	 * @param MollieApiClient|null $mollie       Mollie API client (required if check_mandate is true).
	 * @param string             $mollie_mode   Mollie mode (defaults to test).
	 */
	public function __construct( ImportLogger $logger, $check_mandate = true, MollieApiClient $mollie = null, $mollie_mode = 'test' ) {
		$this->logger        = $logger;
		$this->check_mandate = $check_mandate;
		$this->mollie        = $mollie;
		$this->mollie_mode   = $mollie_mode;
	}

	/**
	 * Validate record. Returns array of error messages; empty array means valid.
	 *
	 * @param ImportSubscriptionRecord $record Record from export.
	 * @return string[] List of validation error messages (empty if valid).
	 */
	public function validate( ImportSubscriptionRecord $record ) {
		$errors = array();

		$email = isset( $record->customer['email'] ) ? trim( $record->customer['email'] ) : '';
		if ( $email === '' || ! is_email( $email ) ) {
			$errors[] = 'Missing or invalid email';
		}

		$customer_id = isset( $record->mollie['customer_id'] ) ? trim( $record->mollie['customer_id'] ) : '';
		if ( $customer_id === '' ) {
			$errors[] = 'Missing Mollie customer_id';
		}

		$mandate_id = isset( $record->mollie['mandate_id'] ) ? trim( $record->mollie['mandate_id'] ) : '';
		if ( $mandate_id === '' ) {
			$errors[] = 'Missing Mollie mandate_id';
		}

		$interval_iso = isset( $record->subscription['interval_iso'] ) ? trim( $record->subscription['interval_iso'] ) : '';
		if ( $interval_iso === '' || ! IntervalConverter::is_supported( $interval_iso ) ) {
			$errors[] = 'Missing or unsupported interval_iso: ' . ( $interval_iso ?: '(empty)' );
		}

		$amount = isset( $record->subscription['amount_value'] ) ? $record->subscription['amount_value'] : '';
		if ( $amount === '' || (float) $amount <= 0 ) {
			$errors[] = 'Invalid or zero amount_value';
		}

		$currency = isset( $record->subscription['amount_currency'] ) ? trim( $record->subscription['amount_currency'] ) : '';
		if ( $currency === '' ) {
			$errors[] = 'Missing amount_currency';
		}

		$next = isset( $record->subscription['next_payment_date'] ) ? trim( $record->subscription['next_payment_date'] ) : '';
		if ( $next === '' ) {
			$errors[] = 'Missing next_payment_date';
		} else {
			$ts = strtotime( $next );
			if ( $ts === false ) {
				$errors[] = 'Invalid next_payment_date format';
			}
		}

		// Mandate status check via Mollie API (use test mapping IDs when simulate-mollie ran first).
		if ( empty( $errors ) && $this->check_mandate && $this->mollie && $customer_id && $mandate_id ) {
			$mapping = ImportStateRepository::get_test_mapping( $customer_id, $mandate_id );
			if ( ! $mapping && self::is_dummy_mollie_id( $customer_id, $mandate_id ) ) {
				$errors[] = 'No Mollie test mapping for dummy export IDs on this site. Run: wp subs simulate-mollie --file=<export.json> before import.';
				return $errors;
			}

			$check_customer_id = $customer_id;
			$check_mandate_id  = $mandate_id;
			if ( $mapping ) {
				$check_customer_id = $mapping['test_customer_id'];
				$check_mandate_id  = $mapping['test_mandate_id'];
			}
			$mandate = $this->mollie->get_mandate( $check_customer_id, $check_mandate_id );
			if ( is_wp_error( $mandate ) ) {
				$errors[] = 'Mandate check failed: ' . $mandate->get_error_message();
			} elseif ( ! is_array( $mandate ) || ( isset( $mandate['status'] ) && strtolower( $mandate['status'] ) !== 'valid' ) ) {
				$errors[] = 'Mandate is not valid in Mollie';
			}
		}

		return $errors;
	}

	/**
	 * Export file uses pseudonymized Mollie IDs (cst_dummy_*, mdt_dummy_*) that only exist after simulate-mollie.
	 *
	 * @param string $customer_id Mollie customer ID from export.
	 * @param string $mandate_id  Mollie mandate ID from export.
	 * @return bool
	 */
	private static function is_dummy_mollie_id( $customer_id, $mandate_id ) {
		return strpos( $customer_id, 'cst_dummy_' ) === 0 || strpos( $mandate_id, 'mdt_dummy_' ) === 0;
	}
}
