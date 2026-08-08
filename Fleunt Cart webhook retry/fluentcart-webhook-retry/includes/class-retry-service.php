<?php
/**
 * Replays a previously captured failed webhook.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCWR_Retry_Service {

	/**
	 * Static flag the logger reads to know the next captured request
	 * is a retry of a specific log entry. Set before wp_remote_request,
	 * cleared by the logger once consumed.
	 *
	 * @var int|null
	 */
	public static $current_retry_of = null;

	/**
	 * Retry a stored log entry by ID.
	 *
	 * @param int $log_id
	 * @return array {
	 *     @type bool   $success
	 *     @type int    $response_code
	 *     @type string $message
	 *     @type int    $new_log_id
	 * }
	 */
	public function retry( $log_id ) {
		global $wpdb;

		$table = $wpdb->prefix . FCWR_TABLE;
		$log   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id ), ARRAY_A );

		if ( ! $log ) {
			return array(
				'success'       => false,
				'response_code' => 0,
				'message'       => __( 'Log entry not found.', 'fluentcart-webhook-retry' ),
				'new_log_id'    => 0,
			);
		}

		// Rate-limit check.
		$rate_check = $this->check_rate_limit( $log );
		if ( is_wp_error( $rate_check ) ) {
			return array(
				'success'       => false,
				'response_code' => 0,
				'message'       => $rate_check->get_error_message(),
				'new_log_id'    => 0,
			);
		}

		// Hard max-retries check.
		$settings = FCWR_Settings::get();
		if ( (int) $log['retry_count'] >= (int) $settings['max_retries'] ) {
			return array(
				'success'       => false,
				'response_code' => 0,
				'message'       => sprintf(
					/* translators: %d: max retries */
					__( 'Max retry attempts (%d) reached for this log entry.', 'fluentcart-webhook-retry' ),
					(int) $settings['max_retries']
				),
				'new_log_id'    => 0,
			);
		}

		// Rebuild request args from the stored row.
		$args = array(
			'method'  => $log['method'] ?: 'POST',
			'headers' => $log['request_headers'] ? json_decode( $log['request_headers'], true ) : array(),
			'body'    => $log['request_body'],
			'timeout' => 30,
		);

		if ( ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		/**
		 * Allow last-mile customisation of the retry args.
		 * Useful if a header (e.g. timestamp) has to be regenerated.
		 *
		 * @param array $args
		 * @param array $log
		 */
		$args = apply_filters( 'fcwr/retry_args', $args, $log );

		// Signal the logger that this is a retry, not a fresh capture.
		self::$current_retry_of = (int) $log['id'];

		$response = wp_remote_request( $log['url'], $args );

		// In case the logger didn't catch it (e.g. http_api_debug suppressed), clear the flag.
		self::$current_retry_of = null;

		$is_error      = is_wp_error( $response );
		$response_code = $is_error ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$succeeded     = ! $is_error && $response_code >= 200 && $response_code < 300;

		// Bump retry counter on the parent log row.
		$wpdb->update(
			$table,
			array(
				'retry_count' => (int) $log['retry_count'] + 1,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $log_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		// Find the row the logger inserted for this retry.
		$new_log_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE parent_log_id = %d ORDER BY id DESC LIMIT 1",
			$log_id
		) );

		// Stamp the retrying user on the new row if it exists.
		if ( $new_log_id ) {
			$wpdb->update(
				$table,
				array( 'retried_by' => get_current_user_id() ),
				array( 'id' => $new_log_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		/**
		 * Fires after a retry completes (success or failure).
		 *
		 * @param int   $log_id        Original log row ID.
		 * @param int   $new_log_id    New child row ID (0 if none).
		 * @param bool  $succeeded
		 * @param int   $response_code
		 */
		do_action( 'fcwr/retry_complete', $log_id, $new_log_id, $succeeded, $response_code );

		return array(
			'success'       => $succeeded,
			'response_code' => $response_code,
			'message'       => $is_error
				? $response->get_error_message()
				: ( $succeeded
					? __( 'Webhook resent successfully.', 'fluentcart-webhook-retry' )
					: sprintf(
						/* translators: %d: HTTP response code */
						__( 'Retry failed with response code %d.', 'fluentcart-webhook-retry' ),
						$response_code
					)
				),
			'new_log_id'    => $new_log_id,
		);
	}

	/**
	 * Soft rate-limit: N retries per window per log entry.
	 *
	 * @param array $log
	 * @return true|WP_Error
	 */
	private function check_rate_limit( $log ) {
		global $wpdb;

		$settings = FCWR_Settings::get();
		$window   = max( 1, (int) $settings['retry_window_sec'] );
		$max      = max( 1, (int) $settings['retries_per_window'] );
		$table    = $wpdb->prefix . FCWR_TABLE;

		$since = gmdate( 'Y-m-d H:i:s', time() - $window );

		$recent = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE parent_log_id = %d AND created_at >= %s",
			(int) $log['id'],
			$since
		) );

		if ( $recent >= $max ) {
			return new WP_Error(
				'fcwr_rate_limited',
				sprintf(
					/* translators: 1: count, 2: seconds */
					__( 'Too many retries (%1$d) in the last %2$d seconds. Wait before trying again.', 'fluentcart-webhook-retry' ),
					$recent,
					$window
				)
			);
		}

		return true;
	}
}
