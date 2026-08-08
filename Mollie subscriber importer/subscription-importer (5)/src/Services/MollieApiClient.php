<?php
/**
 * Mollie API v2 client for mandate check and subscription create.
 * Security hardening: importer is test-only and blocks live keys.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Services;

use SubscriptionImporter\Support\MollieHttpOptions;
use SubscriptionImporter\Support\MollieTestApiKey;

/**
 * MollieApiClient
 */
class MollieApiClient {

	const API_URL = 'https://api.mollie.com/v2/';

	/** @var string|null API key (decrypted). If null, read from FluentCart Mollie settings. */
	private $api_key;

	/** @var string Always 'test' (live is blocked). */
	private $mode;

	/**
	 * @param string|null $api_key Optional. If null, uses FluentCart Pro Mollie test key.
	 * @param string      $mode    Ignored. Importer always runs in test mode.
	 */
	public function __construct( $api_key = null, $mode = 'test' ) {
		$this->api_key = $api_key;
		$this->mode    = 'test';
	}

	/**
	 * Get API key (from param or FluentCart).
	 *
	 * @return string
	 */
	private function get_api_key() {
		if ( $this->api_key !== null && $this->api_key !== '' ) {
			return $this->api_key;
		}
		return MollieTestApiKey::resolve();
	}

	/**
	 * GET a single mandate.
	 *
	 * @param string $customer_id Mollie customer ID.
	 * @param string $mandate_id  Mollie mandate ID.
	 * @return array|\WP_Error Response array or WP_Error.
	 */
	public function get_mandate( $customer_id, $mandate_id ) {
		$path = 'customers/' . $customer_id . '/mandates/' . $mandate_id;
		return $this->request( $path, array(), 'GET' );
	}

	/**
	 * POST create subscription for a customer.
	 *
	 * @param string $customer_id Mollie customer ID.
	 * @param array  $payload     amount, interval, startDate, mandateId, description, webhookUrl, etc.
	 * @return array|\WP_Error Response array or WP_Error.
	 */
	public function create_subscription( $customer_id, array $payload ) {
		$path = 'customers/' . $customer_id . '/subscriptions';
		return $this->request( $path, $payload, 'POST' );
	}

	/**
	 * DELETE cancel/remove a subscription in Mollie test mode.
	 *
	 * @param string $customer_id     Mollie customer ID.
	 * @param string $subscription_id Mollie subscription ID.
	 * @return array|\WP_Error Response or WP_Error.
	 */
	public function delete_subscription( $customer_id, $subscription_id ) {
		$path = 'customers/' . $customer_id . '/subscriptions/' . $subscription_id;
		return $this->request( $path, array(), 'DELETE' );
	}

	/**
	 * DELETE a customer (e.g. test customer for rollback).
	 *
	 * @param string $customer_id Mollie customer ID.
	 * @return array|\WP_Error
	 */
	public function delete_customer( $customer_id ) {
		$path = 'customers/' . $customer_id;
		return $this->request( $path, array(), 'DELETE' );
	}

	/**
	 * Make HTTP request to Mollie API.
	 *
	 * @param string $path   Path (e.g. customers/xxx/mandates/yyy).
	 * @param array  $data   Body or query data.
	 * @param string $method GET or POST.
	 * @return array|\WP_Error
	 */
	private function request( $path, $data = array(), $method = 'GET' ) {
		$api_key = trim( (string) $this->get_api_key() );
		if ( empty( $api_key ) ) {
			return new \WP_Error( 'mollie_api', 'Mollie API key not configured.' );
		}
		if ( stripos( $api_key, 'live_' ) === 0 ) {
			return new \WP_Error( 'mollie_api_live_blocked', 'Live Mollie API key detected. Importer runs in test-only mode.' );
		}

		$url = self::API_URL . $path;
		$headers = array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		);

		$timeout = ( $method === 'DELETE' ) ? 10 : 30;
		$http_args = array_merge(
			MollieHttpOptions::base_args(),
			array(
				'headers' => $headers,
				'timeout' => $timeout,
			)
		);

		if ( $method === 'GET' ) {
			$response = wp_remote_get( $url, $http_args );
		} elseif ( $method === 'DELETE' ) {
			$response = wp_remote_request(
				$url,
				array_merge(
					$http_args,
					array( 'method' => 'DELETE' )
				)
			);
		} else {
			$response = wp_remote_post(
				$url,
				array_merge(
					$http_args,
					array( 'body' => wp_json_encode( $data ) )
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( $code >= 300 ) {
			// Customer already removed — treat as success during cleanup.
			if ( $method === 'DELETE' && (int) $code === 404 ) {
				return array( 'status' => 'already_deleted' );
			}
			$message = isset( $decoded['detail'] ) ? $decoded['detail'] : ( isset( $decoded['title'] ) ? $decoded['title'] : 'Mollie API error' );
			return new \WP_Error( 'mollie_api', $message, $decoded );
		}

		return is_array( $decoded ) ? $decoded : array();
	}
}
