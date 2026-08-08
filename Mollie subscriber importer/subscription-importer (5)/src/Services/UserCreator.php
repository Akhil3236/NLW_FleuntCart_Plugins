<?php
/**
 * Creates or finds WordPress user by email.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Services;

use SubscriptionImporter\Models\ImportSubscriptionRecord;

/**
 * UserCreator
 */
class UserCreator {

	/**
	 * Get or create WP user for record. Returns user ID or 0 on failure.
	 *
	 * @param ImportSubscriptionRecord $record Export record.
	 * @return int User ID (0 if failed).
	 */
	public function get_or_create( ImportSubscriptionRecord $record ) {
		$email = isset( $record->customer['email'] ) ? trim( $record->customer['email'] ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return (int) $user->ID;
		}

		// Create user: login = email, random password, no notification.
		$username = sanitize_user( $email, true );
		if ( empty( $username ) ) {
			$username = 'user_' . wp_rand( 10000, 99999 );
		}
		// Ensure unique login.
		if ( username_exists( $username ) ) {
			$username = $username . '_' . wp_rand( 100, 999 );
		}

		$user_id = wp_create_user( $username, wp_generate_password( 24, true ), $email );
		if ( is_wp_error( $user_id ) ) {
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

		wp_update_user( array(
			'ID'         => $user_id,
			'first_name' => $first,
			'last_name'  => $last,
			'role'       => 'subscriber',
		) );

		// Mark as created by importer (for safe rollback: only delete these users).
		update_user_meta( $user_id, 'subscription_importer_created', '1' );

		return (int) $user_id;
	}
}
