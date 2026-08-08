<?php
/**
 * Orchestrates import: load JSON, apply limit/offset/resume, run SubscriptionImporter per record.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Services;

use SubscriptionImporter\Models\ImportSubscriptionRecord;
use SubscriptionImporter\Logging\ImportLogger;
use SubscriptionImporter\Validation\SubscriptionValidator;
use SubscriptionImporter\Validation\DuplicateDetector;
use SubscriptionImporter\Storage\ImportStateRepository;

/**
 * ImportRunner
 */
class ImportRunner {

	/**
	 * Run import from file path.
	 *
	 * @param string $file_path  Path to export JSON.
	 * @param array  $options    dry_run, limit, offset, test_api, resume, skip_mollie.
	 * @return array{ total: int, processed: int, success: int, skipped: int, failed: int, errors: string[] }
	 */
	public function run( $file_path, array $options = array() ) {
		$dry_run     = ! empty( $options['dry_run'] );
		$limit       = isset( $options['limit'] ) ? (int) $options['limit'] : 0;
		$offset      = isset( $options['offset'] ) ? (int) $options['offset'] : 0;
		$test_api    = true;
		$resume      = ! empty( $options['resume'] );
		$skip_mollie = ! empty( $options['skip_mollie'] );

		$logger = new ImportLogger( $dry_run );

		$mollie_mode = 'test';
		$mollie      = new MollieApiClient( null, $mollie_mode );

		$validator  = new SubscriptionValidator( $logger, true, $mollie, $mollie_mode );
		$repository = new ImportStateRepository();
		$duplicate  = new DuplicateDetector( $repository );
		$user       = new UserCreator();
		$customer   = new FluentCartCustomerCreator();
		$subscription = new FluentCartSubscriptionCreator();
		$mollie_creator = new MollieSubscriptionCreator( $mollie );

		$importer = new SubscriptionImporter(
			$logger,
			$validator,
			$duplicate,
			$repository,
			$user,
			$customer,
			$subscription,
			$mollie_creator,
			$dry_run,
			$resume,
			$test_api,
			$skip_mollie
		);

		$data = $this->load_file( $file_path );
		if ( is_wp_error( $data ) ) {
			return array(
				'total'     => 0,
				'processed' => 0,
				'success'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'errors'    => array( $data->get_error_message() ),
			);
		}

		$subscriptions = isset( $data['subscriptions'] ) && is_array( $data['subscriptions'] ) ? $data['subscriptions'] : array();
		$total = count( $subscriptions );

		if ( $offset > 0 || ( $limit > 0 ) ) {
			$subscriptions = array_slice( $subscriptions, $offset, $limit > 0 ? $limit : null );
		}

		$processed = 0;
		$success   = 0;
		$skipped   = 0;
		$failed    = 0;
		$errors    = array();

		foreach ( $subscriptions as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$record = ImportSubscriptionRecord::from_array( $row );
			$result = $importer->import_one( $record );
			$processed++;

			if ( $result['success'] ) {
				if ( ! empty( $result['message'] ) && strpos( $result['message'], 'resume' ) !== false ) {
					$skipped++;
				} else {
					$success++;
				}
			} else {
				$failed++;
				$errors[] = 'Pronamic ID ' . $record->pronamic_subscription_id . ': ' . $result['message'];
			}
		}

		return array(
			'total'     => $total,
			'processed' => $processed,
			'success'   => $success,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'errors'    => $errors,
		);
	}

	/**
	 * Load and decode JSON file.
	 *
	 * @param string $file_path Path to file.
	 * @return array|\WP_Error Decoded data or WP_Error.
	 */
	private function load_file( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'file', 'File not readable: ' . $file_path );
		}
		$json = file_get_contents( $file_path );
		if ( $json === false ) {
			return new \WP_Error( 'file', 'Could not read file.' );
		}
		$data = json_decode( $json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'json', 'Invalid JSON: ' . json_last_error_msg() );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'json', 'JSON root must be an object.' );
		}
		return $data;
	}
}
