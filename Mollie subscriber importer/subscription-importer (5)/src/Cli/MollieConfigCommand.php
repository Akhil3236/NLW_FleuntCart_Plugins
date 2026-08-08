<?php
/**
 * WP-CLI: wp subs mollie-config — show whether Mollie test API key is configured.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Cli;

use SubscriptionImporter\Support\MollieTestApiKey;

/**
 * MollieConfigCommand
 */
class MollieConfigCommand {

	/**
	 * @param array $args    Positional args.
	 * @param array $assoc   Associative args.
	 */
	public static function invoke( $args, $assoc ) {
		$sources = array(
			'SUBSCRIPTION_MOLLIE_TEST_API_KEY'      => defined( 'SUBSCRIPTION_MOLLIE_TEST_API_KEY' ),
			'SUBSCRIPTION_IMPORTER_MOLLIE_TEST_API_KEY' => defined( 'SUBSCRIPTION_IMPORTER_MOLLIE_TEST_API_KEY' ),
			'SUBSCRIPTION_EXPORTER_MOLLIE_API_KEY'  => defined( 'SUBSCRIPTION_EXPORTER_MOLLIE_API_KEY' ),
			'env SUBSCRIPTION_MOLLIE_TEST_API_KEY'  => ( getenv( 'SUBSCRIPTION_MOLLIE_TEST_API_KEY' ) !== false && getenv( 'SUBSCRIPTION_MOLLIE_TEST_API_KEY' ) !== '' ),
		);

		foreach ( $sources as $name => $defined ) {
			\WP_CLI::log( sprintf( '%s: %s', $name, $defined ? 'defined' : 'not set' ) );
		}

		$key = MollieTestApiKey::resolve();
		if ( $key === '' ) {
			\WP_CLI::error(
				'Mollie test API key not configured. Add SUBSCRIPTION_MOLLIE_TEST_API_KEY to wp-config.php (test_...).'
			);
		}

		$masked = substr( $key, 0, 9 ) . '...' . substr( $key, -4 );
		\WP_CLI::success( 'Mollie test API key resolved: ' . $masked );
	}
}
