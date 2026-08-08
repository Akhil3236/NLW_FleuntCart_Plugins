<?php
/**
 * Resolve Mollie test API key from wp-config, env, FluentCart, or legacy constants.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Support;

/**
 * MollieTestApiKey
 */
class MollieTestApiKey {

	/**
	 * Mollie test API key for simulate-mollie and import (must start with test_).
	 *
	 * Resolution order:
	 * 1. SUBSCRIPTION_MOLLIE_TEST_API_KEY (shared, recommended)
	 * 2. SUBSCRIPTION_IMPORTER_MOLLIE_TEST_API_KEY
	 * 3. Environment variable SUBSCRIPTION_MOLLIE_TEST_API_KEY
	 * 4. SUBSCRIPTION_EXPORTER_MOLLIE_API_KEY only when it is a test_ key (legacy)
	 * 5. Filter subscription_mollie_test_api_key
	 * 6. FluentCart Pro Mollie test key
	 *
	 * @return string Empty when not configured.
	 */
	public static function resolve() {
		$candidates = array();

		if ( defined( 'SUBSCRIPTION_MOLLIE_TEST_API_KEY' ) ) {
			$candidates[] = SUBSCRIPTION_MOLLIE_TEST_API_KEY;
		}
		if ( defined( 'SUBSCRIPTION_IMPORTER_MOLLIE_TEST_API_KEY' ) ) {
			$candidates[] = SUBSCRIPTION_IMPORTER_MOLLIE_TEST_API_KEY;
		}

		$env = getenv( 'SUBSCRIPTION_MOLLIE_TEST_API_KEY' );
		if ( is_string( $env ) && $env !== '' ) {
			$candidates[] = $env;
		}

		if ( defined( 'SUBSCRIPTION_EXPORTER_MOLLIE_API_KEY' ) ) {
			$candidates[] = SUBSCRIPTION_EXPORTER_MOLLIE_API_KEY;
		}

		foreach ( $candidates as $candidate ) {
			$key = self::normalize_test_key( $candidate );
			if ( $key !== '' ) {
				return $key;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'subscription_mollie_test_api_key', '' );
			$key      = self::normalize_test_key( $filtered );
			if ( $key !== '' ) {
				return $key;
			}
		}

		if ( class_exists( 'FluentCartPro\App\Modules\PaymentMethods\MollieGateway\MollieSettingsBase' ) ) {
			$settings = new \FluentCartPro\App\Modules\PaymentMethods\MollieGateway\MollieSettingsBase();
			return self::normalize_test_key( $settings->getApiKey( 'test' ) );
		}

		return '';
	}

	/**
	 * @param mixed $key Raw key value.
	 * @return string Normalized test key or empty.
	 */
	private static function normalize_test_key( $key ) {
		if ( ! is_string( $key ) ) {
			return '';
		}
		$key = trim( $key );
		if ( $key === '' ) {
			return '';
		}
		if ( stripos( $key, 'test_' ) !== 0 || strlen( $key ) < 12 ) {
			return '';
		}
		return $key;
	}
}
