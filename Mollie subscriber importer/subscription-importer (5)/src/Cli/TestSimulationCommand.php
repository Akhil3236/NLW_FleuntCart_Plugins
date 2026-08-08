<?php
/**
 * WP-CLI command: wp subs simulate-mollie
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Cli;

use SubscriptionImporter\Simulation\MollieSandboxCloner;
use SubscriptionImporter\Support\MollieTestApiKey;

/**
 * TestSimulationCommand
 */
class TestSimulationCommand {

	/**
	 * Invoke command.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Associative args (file).
	 */
	public static function invoke( $args, $assoc ) {
		$file = isset( $assoc['file'] ) && is_string( $assoc['file'] ) ? trim( $assoc['file'] ) : '';
		if ( $file === '' ) {
			$file = defined( 'SUBSCRIPTION_IMPORTER_DEFAULT_EXPORT_FILE' ) ? SUBSCRIPTION_IMPORTER_DEFAULT_EXPORT_FILE : ( ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : getcwd() . '/wp-content' ) . '/subscriptions-export.json' );
		}

		$path = $file;
		if ( ! preg_match( '#^[a-zA-Z]:\\\\#', $path ) && $path[0] !== '/' ) {
			$path = getcwd() . '/' . $path;
		}
		if ( ! file_exists( $path ) ) {
			\WP_CLI::error( 'File not found: ' . $path );
		}

		$json = file_get_contents( $path );
		if ( $json === false ) {
			\WP_CLI::error( 'Could not read file.' );
		}
		$data = json_decode( $json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			\WP_CLI::error( 'Invalid JSON.' );
		}

		$subscriptions = isset( $data['subscriptions'] ) && is_array( $data['subscriptions'] ) ? $data['subscriptions'] : array();
		\WP_CLI::log( 'Loaded ' . count( $subscriptions ) . ' subscriptions from file.' );

		if ( MollieTestApiKey::resolve() === '' ) {
			\WP_CLI::error(
				'Mollie test API key not configured. Set SUBSCRIPTION_MOLLIE_TEST_API_KEY in wp-config.php, then run: wp subs mollie-config'
			);
		}

		$cloner = new MollieSandboxCloner();
		$result = $cloner->run( $subscriptions );

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $err ) {
				\WP_CLI::warning( $err );
			}
		}

		\WP_CLI::log( 'Test customers created: ' . $result['created_customers'] );
		\WP_CLI::log( 'Test mandates created: ' . $result['created_mandates'] );
		\WP_CLI::log( 'Mapping rows stored: ' . $result['mapped'] );
		\WP_CLI::success( 'Simulate-mollie complete. Importer is test-only and will use test mapping automatically.' );
	}
}
