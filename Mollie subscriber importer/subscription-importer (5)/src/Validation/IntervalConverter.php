<?php
/**
 * Converts ISO 8601 duration (e.g. P30D, P1M) to Mollie and FluentCart formats.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Validation;

/**
 * IntervalConverter
 */
class IntervalConverter {

	/** @var array Map interval_iso => [ 'mollie' => string, 'fluentcart' => string ] */
	private static $map = array(
		'P1D'   => array( 'mollie' => '1 day', 'fluentcart' => 'daily' ),
		'P7D'   => array( 'mollie' => '1 week', 'fluentcart' => 'weekly' ),
		'P1W'   => array( 'mollie' => '1 week', 'fluentcart' => 'weekly' ),
		'P30D'  => array( 'mollie' => '30 days', 'fluentcart' => 'monthly' ),
		'P1M'   => array( 'mollie' => '1 month', 'fluentcart' => 'monthly' ),
		'P3M'   => array( 'mollie' => '3 months', 'fluentcart' => 'quarterly' ),
		'P6M'   => array( 'mollie' => '6 months', 'fluentcart' => 'half_yearly' ),
		'P12M'  => array( 'mollie' => '12 months', 'fluentcart' => 'yearly' ),
		'P1Y'   => array( 'mollie' => '12 months', 'fluentcart' => 'yearly' ),
	);

	/**
	 * Get Mollie interval string for given ISO duration.
	 *
	 * @param string $interval_iso e.g. P30D, P1M.
	 * @return string|null Mollie interval or null if unknown.
	 */
	public static function to_mollie( $interval_iso ) {
		$interval_iso = trim( (string) $interval_iso );
		if ( isset( self::$map[ $interval_iso ] ) ) {
			return self::$map[ $interval_iso ]['mollie'];
		}
		return null;
	}

	/**
	 * Get FluentCart billing_interval for given ISO duration.
	 *
	 * @param string $interval_iso e.g. P30D, P1M.
	 * @return string|null FluentCart interval or null if unknown.
	 */
	public static function to_fluentcart( $interval_iso ) {
		$interval_iso = trim( (string) $interval_iso );
		if ( isset( self::$map[ $interval_iso ] ) ) {
			return self::$map[ $interval_iso ]['fluentcart'];
		}
		return null;
	}

	/**
	 * Check if interval is supported.
	 *
	 * @param string $interval_iso ISO duration.
	 * @return bool
	 */
	public static function is_supported( $interval_iso ) {
		return self::to_mollie( $interval_iso ) !== null;
	}
}
