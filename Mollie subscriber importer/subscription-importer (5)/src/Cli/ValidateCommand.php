<?php
/**
 * WP-CLI command: wp subs validate
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Cli;

use SubscriptionImporter\Models\ImportSubscriptionRecord;
use SubscriptionImporter\Validation\SubscriptionValidator;
use SubscriptionImporter\Logging\ImportLogger;

/**
 * ValidateCommand
 */
class ValidateCommand {

	/**
	 * Invoke command. Validates file without calling Mollie (no mandate check by default for speed).
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
		$logger = new ImportLogger( false );
		$validator = new SubscriptionValidator( $logger, false, null, 'test' );

		$valid = 0;
		$invalid = 0;
		$errors_by_type = array();

		foreach ( $subscriptions as $row ) {
			if ( ! is_array( $row ) ) {
				$invalid++;
				continue;
			}
			$record = ImportSubscriptionRecord::from_array( $row );
			$errs = $validator->validate( $record );
			if ( empty( $errs ) ) {
				$valid++;
			} else {
				$invalid++;
				foreach ( $errs as $e ) {
					$errors_by_type[ $e ] = isset( $errors_by_type[ $e ] ) ? $errors_by_type[ $e ] + 1 : 1;
				}
			}
		}

		\WP_CLI::log( 'Validation results:' );
		\WP_CLI::log( '  Total: ' . count( $subscriptions ) );
		\WP_CLI::log( '  Valid: ' . $valid );
		\WP_CLI::log( '  Invalid: ' . $invalid );
		if ( ! empty( $errors_by_type ) ) {
			\WP_CLI::log( '  Error breakdown:' );
			arsort( $errors_by_type );
			foreach ( $errors_by_type as $msg => $count ) {
				\WP_CLI::log( '    - ' . $msg . ': ' . $count );
			}
		}
		\WP_CLI::success( 'Validation complete.' );
	}
}
