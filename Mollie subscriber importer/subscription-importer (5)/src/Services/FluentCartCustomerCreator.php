<?php
/**
 * Creates or finds FluentCart customer by email.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Services;

use SubscriptionImporter\Models\ImportSubscriptionRecord;

/**
 * FluentCartCustomerCreator
 */
class FluentCartCustomerCreator {

	/**
	 * Get or create FluentCart customer. Returns customer ID or 0 on failure.
	 *
	 * @param int                     $wp_user_id WordPress user ID (from UserCreator).
	 * @param ImportSubscriptionRecord $record    Export record.
	 * @return int FluentCart customer ID (0 if failed).
	 */
	public function get_or_create( $wp_user_id, ImportSubscriptionRecord $record ) {
		if ( ! class_exists( 'FluentCart\App\Models\Customer' ) ) {
			return 0;
		}

		$email = isset( $record->customer['email'] ) ? trim( $record->customer['email'] ) : '';
		if ( ! $email ) {
			return 0;
		}

		$first = isset( $record->customer['first_name'] ) ? trim( $record->customer['first_name'] ) : '';
		$last  = isset( $record->customer['last_name'] ) ? trim( $record->customer['last_name'] ) : '';
		if ( ( $first === '' || $last === '' ) && ! empty( $record->customer['full_name'] ) ) {
			$parts = preg_split( '/\s+/', trim( $record->customer['full_name'] ), 2 );
			if ( count( $parts ) >= 2 ) {
				$first = $parts[0];
				$last  = $parts[1];
			} else {
				$first = trim( $record->customer['full_name'] );
			}
		}

		$customer = \FluentCart\App\Models\Customer::query()->where( 'email', $email )->first();
		if ( $customer ) {
			// Optionally link WP user if not set.
			if ( $wp_user_id && ( ! $customer->user_id || (int) $customer->user_id !== (int) $wp_user_id ) ) {
				$customer->user_id = $wp_user_id;
				$customer->save();
			}
			return (int) $customer->id;
		}

		$customer = \FluentCart\App\Models\Customer::query()->create( array(
			'user_id'    => $wp_user_id ?: null,
			'email'      => $email,
			'first_name' => $first,
			'last_name'  => $last,
			'status'     => 'active',
			'country'    => isset( $record->customer['country'] ) ? trim( $record->customer['country'] ) : '',
		) );

		return $customer && isset( $customer->id ) ? (int) $customer->id : 0;
	}
}
