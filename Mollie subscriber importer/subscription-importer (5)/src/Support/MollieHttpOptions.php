<?php
/**
 * Shared HTTP options for Mollie API calls (SSL verify, etc.).
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Support;

/**
 * MollieHttpOptions
 */
class MollieHttpOptions {

	/**
	 * Whether SSL certificates should be verified for Mollie HTTP requests.
	 *
	 * Uses SUBSCRIPTION_MOLLIE_SSL_VERIFY or legacy SUBSCRIPTION_EXPORTER_MOLLIE_SSL_VERIFY.
	 * Set to false locally on Windows if cURL error 60 occurs.
	 *
	 * @return bool
	 */
	public static function ssl_verify() {
		if ( defined( 'SUBSCRIPTION_MOLLIE_SSL_VERIFY' ) && ! SUBSCRIPTION_MOLLIE_SSL_VERIFY ) {
			return false;
		}
		if ( defined( 'SUBSCRIPTION_EXPORTER_MOLLIE_SSL_VERIFY' ) && ! SUBSCRIPTION_EXPORTER_MOLLIE_SSL_VERIFY ) {
			return false;
		}
		$filtered = apply_filters( 'subscription_mollie_sslverify', true );
		return (bool) $filtered;
	}

	/**
	 * Base args for wp_remote_get/post/request to Mollie API.
	 *
	 * @return array{ sslverify: bool }
	 */
	public static function base_args() {
		return array(
			'sslverify' => self::ssl_verify(),
		);
	}
}
