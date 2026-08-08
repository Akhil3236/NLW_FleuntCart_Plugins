<?php
/**
 * WP-CLI command: wp subs import
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Cli;

use SubscriptionImporter\Services\ImportRunner;
use SubscriptionImporter\Support\MollieTestApiKey;

/**
 * ImportCommand
 */
class ImportCommand {

	/**
	 * Invoke command.
	 *
	 * @param array $args   Positional args.
	 * @param array $assoc  Associative args (file, dry-run, limit, offset, resume, skip-mollie).
	 */
	public static function invoke( $args, $assoc ) {
		$file = isset( $assoc['file'] ) && is_string( $assoc['file'] ) ? trim( $assoc['file'] ) : '';
		if ( $file === '' ) {
			$file = defined( 'SUBSCRIPTION_IMPORTER_DEFAULT_EXPORT_FILE' )
				? SUBSCRIPTION_IMPORTER_DEFAULT_EXPORT_FILE
				: ( ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : getcwd() . '/wp-content' ) . '/subscriptions-export.json' );
		}

		$path = $file;
		if ( ! preg_match( '#^[a-zA-Z]:\\\\#', $path ) && $path[0] !== '/' ) {
			$path = getcwd() . '/' . $path;
		}
		if ( ! file_exists( $path ) ) {
			\WP_CLI::error( 'File not found: ' . $path );
		}

		$skip_mollie = isset( $assoc['skip-mollie'] ) && $assoc['skip-mollie'];

		$options = array(
			'dry_run'     => isset( $assoc['dry-run'] ) && $assoc['dry-run'],
			'limit'       => isset( $assoc['limit'] ) ? $assoc['limit'] : 0,
			'offset'      => isset( $assoc['offset'] ) ? $assoc['offset'] : 0,
			'test_api'    => true,
			'resume'      => isset( $assoc['resume'] ) && $assoc['resume'],
			'skip_mollie' => $skip_mollie,
		);

		if ( $options['dry_run'] ) {
			\WP_CLI::log( 'Running in DRY-RUN mode (no changes will be made).' );
		}

		if ( $skip_mollie ) {
			\WP_CLI::log( 'SKIP-MOLLIE mode: WP + FluentCart records will be created but Mollie subscriptions will be skipped.' );
		}

		\WP_CLI::log( 'Security mode: TEST-ONLY. Live Mollie API is blocked.' );

		if ( ! $options['dry_run'] && ! $skip_mollie && MollieTestApiKey::resolve() === '' ) {
			\WP_CLI::error(
				'Mollie test API key not configured. Set SUBSCRIPTION_MOLLIE_TEST_API_KEY in wp-config.php, then run: wp subs mollie-config'
			);
		}

		$runner = new ImportRunner();
		$result = $runner->run( $path, $options );

		if ( isset( $result['errors'] ) && count( $result['errors'] ) > 0 ) {
			foreach ( array_slice( $result['errors'], 0, 20 ) as $err ) {
				\WP_CLI::warning( $err );
			}
			if ( count( $result['errors'] ) > 20 ) {
				\WP_CLI::warning( '... and ' . ( count( $result['errors'] ) - 20 ) . ' more errors.' );
			}
		}

		\WP_CLI::success( sprintf(
			'Import finished. Total in file: %d, Processed: %d, Success: %d, Skipped (resume): %d, Failed: %d.',
			$result['total'],
			$result['processed'],
			$result['success'],
			$result['skipped'],
			$result['failed']
		) );
	}
}
