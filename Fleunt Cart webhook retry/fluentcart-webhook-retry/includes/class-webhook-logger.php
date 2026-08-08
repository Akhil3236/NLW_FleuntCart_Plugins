<?php
/**
 * Captures outgoing HTTP requests and logs failed webhooks to the DB.
 *
 * Strategy: hook WordPress core's `http_api_debug` action which fires
 * after every wp_remote_* call. This is plugin-agnostic — works for
 * FluentCart, WooCommerce, or anything else using the WP HTTP API.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCWR_Webhook_Logger {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'http_api_debug', array( $this, 'capture' ), 10, 5 );
	}

	/**
	 * Fired after every wp_remote_* request.
	 *
	 * @param array|WP_Error $response  HTTP response or WP_Error.
	 * @param string         $context   'response'.
	 * @param string         $class     Transport class.
	 * @param array          $args      Request args.
	 * @param string         $url       Request URL.
	 */
	public function capture( $response, $context, $class, $args, $url ) {
		if ( 'response' !== $context ) {
			return;
		}

		// Detect retry context first — retries always get logged, even on success,
		// so the admin can see the outcome of their click.
		$retry_of = null;
		if ( null !== FCWR_Retry_Service::$current_retry_of ) {
			$retry_of                              = (int) FCWR_Retry_Service::$current_retry_of;
			FCWR_Retry_Service::$current_retry_of = null; // consume
		}

		if ( ! $retry_of && ! $this->should_capture( $url ) ) {
			return;
		}

		$settings = FCWR_Settings::get();
		$is_error = is_wp_error( $response );
		$code     = $is_error ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$failed   = $is_error || $code === 0 || $code >= 400;

		// Skip successful non-retry captures unless explicitly enabled.
		if ( ! $retry_of && ! $failed && empty( $settings['log_successes'] ) ) {
			return;
		}

		$this->store( $url, $args, $response, $failed, $retry_of );
	}

	/**
	 * Decide whether a given URL should be captured.
	 * Matches any user-configured pattern (substring or simple wildcard).
	 *
	 * @param string $url Outgoing URL.
	 * @return bool
	 */
	private function should_capture( $url ) {
		$settings = FCWR_Settings::get();
		$patterns = array_filter( array_map( 'trim', explode( "\n", (string) $settings['watch_urls'] ) ) );

		if ( empty( $patterns ) ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			if ( $pattern === '' ) {
				continue;
			}

			// Simple wildcard: '*' becomes '.*'.
			$regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';

			if ( preg_match( $regex, $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Persist a captured request/response to the DB.
	 *
	 * @param string         $url
	 * @param array          $args
	 * @param array|WP_Error $response
	 * @param bool           $failed
	 * @param int|null       $parent_log_id If this row is a retry of a previous one.
	 * @return int|false Inserted row ID or false on failure.
	 */
	private function store( $url, $args, $response, $failed, $parent_log_id = null ) {
		global $wpdb;

		$table = $wpdb->prefix . FCWR_TABLE;

		$request_headers = isset( $args['headers'] ) ? wp_json_encode( $args['headers'] ) : null;
		$request_body    = isset( $args['body'] ) ? ( is_array( $args['body'] ) ? wp_json_encode( $args['body'] ) : (string) $args['body'] ) : null;

		$response_code = null;
		$response_body = null;
		$error_message = null;

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
		} else {
			$response_code = (int) wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
		}

		$order_id = $this->extract_order_id( $request_body, $url );

		// If this is a retry, carry over the parent's order_id for consistency.
		if ( $parent_log_id ) {
			global $wpdb;
			$parent_order_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT order_id FROM {$wpdb->prefix}" . FCWR_TABLE . " WHERE id = %d",
				$parent_log_id
			) );
			if ( $parent_order_id ) {
				$order_id = (int) $parent_order_id;
			}
		}

		$data = array(
			'order_id'        => $order_id,
			'url'             => $url,
			'method'          => isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'POST',
			'request_headers' => $request_headers,
			'request_body'    => $request_body,
			'response_code'   => $response_code,
			'response_body'   => $response_body !== null ? mb_substr( $response_body, 0, 65535 ) : null,
			'error_message'   => $error_message,
			'status'          => $failed ? 'failed' : 'success',
			'parent_log_id'   => $parent_log_id,
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);

		$wpdb->insert( $table, $data );

		$inserted_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a webhook has been captured and stored.
		 *
		 * @param int   $log_id  DB row ID.
		 * @param array $data    Row data.
		 */
		do_action( 'fcwr/webhook_captured', $inserted_id, $data );

		return $inserted_id;
	}

	/**
	 * Best-effort extraction of an order ID from the request body or URL.
	 * Customise via the `fcwr/extract_order_id` filter for unusual payload shapes.
	 *
	 * @param string|null $body
	 * @param string      $url
	 * @return int|null
	 */
	private function extract_order_id( $body, $url ) {
		$order_id = null;

		if ( ! empty( $body ) ) {
			$decoded = json_decode( $body, true );

			if ( is_array( $decoded ) ) {
				foreach ( array( 'order_id', 'orderId', 'order', 'id' ) as $key ) {
					if ( isset( $decoded[ $key ] ) ) {
						$candidate = is_array( $decoded[ $key ] ) && isset( $decoded[ $key ]['id'] )
							? $decoded[ $key ]['id']
							: $decoded[ $key ];

						if ( is_numeric( $candidate ) ) {
							$order_id = (int) $candidate;
							break;
						}
					}
				}
			}
		}

		/**
		 * Allow customising order ID extraction.
		 *
		 * @param int|null    $order_id
		 * @param string|null $body
		 * @param string      $url
		 */
		return apply_filters( 'fcwr/extract_order_id', $order_id, $body, $url );
	}
}
