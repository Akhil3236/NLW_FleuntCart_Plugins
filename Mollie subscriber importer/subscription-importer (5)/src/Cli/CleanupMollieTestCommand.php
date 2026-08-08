<?php
/**
 * WP-CLI: wp subs cleanup-mollie-test — delete test customers created by simulate-mollie.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Cli;

use SubscriptionImporter\Services\MollieApiClient;
use SubscriptionImporter\Support\MollieTestApiKey;
use SubscriptionImporter\Storage\ImportStateRepository;

/**
 * CleanupMollieTestCommand
 */
class CleanupMollieTestCommand {

	/**
	 * @param array $args  Positional args.
	 * @param array $assoc Associative args (dry-run).
	 */
	public static function invoke( $args, $assoc ) {
		$dry_run       = isset( $assoc['dry-run'] ) && $assoc['dry-run'];
		$truncate_only = isset( $assoc['truncate-only'] ) && $assoc['truncate-only'];

		$customer_ids = ImportStateRepository::get_all_test_customer_ids();
		if ( empty( $customer_ids ) ) {
			\WP_CLI::warning( 'No test customers found in mapping table (wp_mollie_test_mapping).' );
			return;
		}

		\WP_CLI::log( 'Found ' . count( $customer_ids ) . ' test customer(s) in mapping table.' );

		if ( $truncate_only ) {
			if ( $dry_run ) {
				\WP_CLI::success( 'Dry-run: would truncate mapping table only (no Mollie API calls).' );
				return;
			}
			ImportStateRepository::truncate_test_mapping();
			\WP_CLI::success( 'Mapping table truncated. Mollie customers were NOT deleted (use without --truncate-only to delete via API).' );
			return;
		}

		if ( MollieTestApiKey::resolve() === '' ) {
			\WP_CLI::error(
				'Mollie test API key not configured. Set SUBSCRIPTION_MOLLIE_TEST_API_KEY in wp-config.php'
			);
		}

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry-run: no customers deleted, mapping table unchanged.' );
			return;
		}

		$total = count( $customer_ids );
		\WP_CLI::log( 'Deleting ' . $total . ' test customer(s) in Mollie (mandates go with them). This can take several minutes...' );

		$mollie   = new MollieApiClient();
		$deleted  = 0;
		$failed   = 0;
		$progress = class_exists( '\WP_CLI\Utils' ) && method_exists( '\WP_CLI\Utils', 'make_progress_bar' )
			? \WP_CLI\Utils\make_progress_bar( 'Deleting', $total )
			: null;

		foreach ( $customer_ids as $index => $customer_id ) {
			$result = $mollie->delete_customer( $customer_id );
			if ( is_wp_error( $result ) ) {
				$failed++;
				\WP_CLI::warning( $customer_id . ': ' . $result->get_error_message() );
			} else {
				$deleted++;
			}

			if ( $progress ) {
				$progress->tick();
			} elseif ( ( $index + 1 ) % 10 === 0 || ( $index + 1 ) === $total ) {
				\WP_CLI::log( sprintf( 'Progress: %d/%d deleted (%d failed)', $deleted, $total, $failed ) );
			}
		}

		if ( $progress ) {
			$progress->finish();
		}

		ImportStateRepository::truncate_test_mapping();

		\WP_CLI::log( 'Deleted in Mollie: ' . $deleted );
		\WP_CLI::log( 'Failed: ' . $failed );
		\WP_CLI::success( 'Mapping table wp_mollie_test_mapping truncated. Mandates are removed with their customer in Mollie.' );
	}
}
