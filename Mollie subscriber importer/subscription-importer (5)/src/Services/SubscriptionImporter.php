<?php
/**
 * Per-record import flow: validate → duplicate check → claim → user → customer → order+subscription → Mollie → update map.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Services;

use SubscriptionImporter\Models\ImportSubscriptionRecord;
use SubscriptionImporter\Validation\SubscriptionValidator;
use SubscriptionImporter\Validation\DuplicateDetector;
use SubscriptionImporter\Storage\ImportStateRepository;
use SubscriptionImporter\Logging\ImportLogger;

class SubscriptionImporter {

	private $logger;
	private $validator;
	private $duplicate_detector;
	private $repository;
	private $user_creator;
	private $customer_creator;
	private $subscription_creator;
	private $mollie_creator;
	private $dry_run;
	private $resume;
	private $use_test_mapping;
	private $skip_mollie;

	public function __construct(
		ImportLogger $logger,
		SubscriptionValidator $validator,
		DuplicateDetector $duplicate,
		ImportStateRepository $repository,
		UserCreator $user,
		FluentCartCustomerCreator $customer,
		FluentCartSubscriptionCreator $subscription,
		MollieSubscriptionCreator $mollie,
		$dry_run = false,
		$resume = false,
		$use_test_mapping = false,
		$skip_mollie = false
	) {
		$this->logger               = $logger;
		$this->validator            = $validator;
		$this->duplicate_detector   = $duplicate;
		$this->repository           = $repository;
		$this->user_creator         = $user;
		$this->customer_creator     = $customer;
		$this->subscription_creator = $subscription;
		$this->mollie_creator       = $mollie;
		$this->dry_run              = $dry_run;
		$this->resume               = $resume;
		$this->use_test_mapping     = $use_test_mapping;
		$this->skip_mollie          = $skip_mollie;
	}

	public function import_one( ImportSubscriptionRecord $record ) {
		$pronamic_id = $record->pronamic_subscription_id;
		$email       = isset( $record->customer['email'] ) ? $record->customer['email'] : '';

		$errors = $this->validator->validate( $record );
		if ( ! empty( $errors ) ) {
			$this->logger->warning( 'Validation failed for Pronamic ID ' . $pronamic_id . ': ' . implode( '; ', $errors ), array( 'email' => $email ) );
			return array( 'success' => false, 'message' => 'Validation failed: ' . implode( '; ', $errors ) );
		}

		if ( $this->resume && $this->duplicate_detector->is_duplicate( $pronamic_id ) ) {
			$this->logger->info( 'Skipping already imported Pronamic ID ' . $pronamic_id, array( 'email' => $email ) );
			return array( 'success' => true, 'message' => 'Already imported (resume skip)' );
		}

		if ( ! $this->resume && $this->duplicate_detector->is_duplicate( $pronamic_id ) ) {
			$this->logger->warning( 'Duplicate detected for Pronamic ID ' . $pronamic_id, array( 'email' => $email ) );
			return array( 'success' => false, 'message' => 'Duplicate' );
		}

		if ( $this->dry_run ) {
			$this->logger->info( '[DRY-RUN] Would import Pronamic ID ' . $pronamic_id . ' for ' . $email );
			return array( 'success' => true, 'message' => 'Dry run: would import' );
		}

		if ( ! $this->repository->claim( $pronamic_id ) ) {
			$this->logger->warning( 'Could not claim Pronamic ID ' . $pronamic_id . ' (duplicate?)', array( 'email' => $email ) );
			return array( 'success' => false, 'message' => 'Duplicate (claim failed)' );
		}

		$created_wp_user_id         = null;
		$created_fc_order_id        = null;
		$created_fc_subscription_id = null;
		$wp_user_was_new            = false;

		try {
			// Step 1: WP user.
			$existing_user  = get_user_by( 'email', $email );
			$wp_user_id     = $this->user_creator->get_or_create( $record );
			if ( ! $wp_user_id ) {
				$this->logger->error( 'Failed to create user for ' . $email );
				$this->repository->mark_failed( $pronamic_id, 'User creation failed' );
				return array( 'success' => false, 'message' => 'User creation failed' );
			}
			$wp_user_was_new    = ! $existing_user;
			$created_wp_user_id = $wp_user_id;
			$this->logger->info( 'Creating user for ' . $email, array( 'wp_user_id' => $wp_user_id ) );

			// Step 2: FluentCart customer.
			$customer_id = $this->customer_creator->get_or_create( $wp_user_id, $record );
			if ( ! $customer_id ) {
				$this->logger->error( 'Failed to create FluentCart customer for ' . $email );
				$this->rollback( $pronamic_id, $wp_user_was_new ? $wp_user_id : null, null, null, $email );
				$this->repository->mark_failed( $pronamic_id, 'FluentCart customer creation failed' );
				return array( 'success' => false, 'message' => 'FluentCart customer creation failed' );
			}
			$this->logger->info( 'FluentCart customer created ID ' . $customer_id, array( 'email' => $email ) );

			$currency     = isset( $record->subscription['amount_currency'] ) ? trim( $record->subscription['amount_currency'] ) : 'EUR';
			$amount       = isset( $record->subscription['amount_value'] ) ? (float) $record->subscription['amount_value'] : 0;
			$amount_cents = (int) round( $amount * 100 );

			// Resolve test mapping before FluentCart subscription creation.
			$override_customer_id = null;
			$override_mandate_id  = null;
			if ( $this->use_test_mapping ) {
				$mapping = ImportStateRepository::get_test_mapping(
					isset( $record->mollie['customer_id'] ) ? $record->mollie['customer_id'] : '',
					isset( $record->mollie['mandate_id'] ) ? $record->mollie['mandate_id'] : ''
				);
				if ( $mapping ) {
					$override_customer_id = $mapping['test_customer_id'];
					$override_mandate_id  = $mapping['test_mandate_id'];
				}
			}

			// Step 3: FluentCart subscription + order.
			$result = $this->subscription_creator->create(
				$customer_id,
				$record,
				$currency,
				$amount_cents,
				$override_customer_id,
				$override_mandate_id
			);

			if ( is_wp_error( $result ) ) {
				$this->logger->error( 'FluentCart subscription create failed: ' . $result->get_error_message(), array( 'email' => $email ) );
				$this->rollback( $pronamic_id, $wp_user_was_new ? $wp_user_id : null, null, null, $email );
				$this->repository->mark_failed( $pronamic_id, 'FluentCart subscription failed: ' . $result->get_error_message() );
				return array( 'success' => false, 'message' => $result->get_error_message() );
			}

			$fc_subscription            = $result['subscription'];
			$created_fc_order_id        = $result['order_id'];
			$created_fc_subscription_id = $result['subscription_id'];
			$this->logger->info( 'FluentCart subscription created ID ' . $result['subscription_id'], array( 'email' => $email ) );

			// Step 4: Mollie subscription.
			$mollie_sub_id = '';
			$next_billing  = null;

			if ( $this->skip_mollie ) {
				$this->logger->info( '[SKIP-MOLLIE] Skipping Mollie step for Pronamic ID ' . $pronamic_id, array( 'email' => $email ) );
			} else {
				$mollie_response = $this->mollie_creator->create( $record, $fc_subscription, $override_customer_id, $override_mandate_id );

				if ( is_wp_error( $mollie_response ) ) {
					$this->logger->error( 'Mollie API failure: ' . $mollie_response->get_error_message(), array( 'email' => $email ) );

					// ROLLBACK: remove all records created in steps 1-3.
					$this->rollback(
						$pronamic_id,
						$wp_user_was_new ? $wp_user_id : null,
						$created_fc_order_id,
						$created_fc_subscription_id,
						$email
					);

					// Mark failed so --resume retries this record.
					$this->repository->mark_failed( $pronamic_id, 'Mollie: ' . $mollie_response->get_error_message() );

					return array( 'success' => false, 'message' => 'Mollie: ' . $mollie_response->get_error_message() );
				}

				$mollie_sub_id = isset( $mollie_response['id'] ) ? $mollie_response['id'] : '';
				$next_billing  = isset( $mollie_response['nextPaymentDate'] ) ? $mollie_response['nextPaymentDate'] : null;

				if ( $mollie_sub_id && $fc_subscription && ! empty( $fc_subscription->id ) ) {
					$fc_subscription->vendor_subscription_id = $mollie_sub_id;
					if ( $next_billing ) {
						$fc_subscription->next_billing_date = $next_billing;
					}
					$fc_subscription->save();
				}

				$this->logger->info( 'Mollie subscription created ' . $mollie_sub_id, array( 'email' => $email ) );
			}

			// All steps succeeded.
			$this->repository->update_mapping( $pronamic_id, array(
				'wp_user_id'                 => $wp_user_id,
				'fluentcart_customer_id'     => $customer_id,
				'fluentcart_subscription_id' => $result['subscription_id'],
				'mollie_subscription_id'     => $mollie_sub_id,
			) );

			return array(
				'success' => true,
				'message' => 'Imported',
				'ids'     => array(
					'wp_user_id'                 => $wp_user_id,
					'fluentcart_customer_id'     => $customer_id,
					'fluentcart_subscription_id' => $result['subscription_id'],
					'mollie_subscription_id'     => $mollie_sub_id,
				),
			);

		} catch ( \Exception $e ) {
			$this->logger->error( 'Import exception for Pronamic ID ' . $pronamic_id . ': ' . $e->getMessage() );
			$this->rollback(
				$pronamic_id,
				$wp_user_was_new ? $created_wp_user_id : null,
				$created_fc_order_id,
				$created_fc_subscription_id,
				$email
			);
			$this->repository->mark_failed( $pronamic_id, 'Exception: ' . $e->getMessage() );
			return array( 'success' => false, 'message' => $e->getMessage() );
		}
	}

	/**
	 * Roll back created records on failure.
	 */
	private function rollback( $pronamic_id, $wp_user_id, $fc_order_id, $fc_subscription_id, $email = '' ) {
		$this->logger->info( 'Rolling back Pronamic ID ' . $pronamic_id, array( 'email' => $email ) );

		if ( $fc_subscription_id && class_exists( 'FluentCart\App\Models\Subscription' ) ) {
			try {
				\FluentCart\App\Models\Subscription::query()->where( 'id', $fc_subscription_id )->delete();
				$this->logger->info( 'Rollback: deleted FluentCart subscription ID ' . $fc_subscription_id, array( 'email' => $email ) );
			} catch ( \Exception $e ) {
				$this->logger->error( 'Rollback: failed to delete FluentCart subscription ' . $fc_subscription_id . ': ' . $e->getMessage() );
			}
		}

		if ( $fc_order_id && class_exists( 'FluentCart\App\Models\Order' ) ) {
			try {
				\FluentCart\App\Models\Order::query()->where( 'id', $fc_order_id )->delete();
				$this->logger->info( 'Rollback: deleted FluentCart order ID ' . $fc_order_id, array( 'email' => $email ) );
			} catch ( \Exception $e ) {
				$this->logger->error( 'Rollback: failed to delete FluentCart order ' . $fc_order_id . ': ' . $e->getMessage() );
			}
		}

		if ( $wp_user_id ) {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			$deleted = wp_delete_user( $wp_user_id );
			if ( $deleted ) {
				$this->logger->info( 'Rollback: deleted WP user ID ' . $wp_user_id, array( 'email' => $email ) );
			} else {
				$this->logger->error( 'Rollback: failed to delete WP user ID ' . $wp_user_id );
			}
		}
	}
}
