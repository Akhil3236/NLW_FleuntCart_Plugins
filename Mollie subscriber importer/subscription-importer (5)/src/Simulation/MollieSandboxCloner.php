<?php
/**
 * Creates Mollie test customers and mandates from export data; stores mapping in wp_mollie_test_mapping.
 *
 * @package SubscriptionImporter
 */

namespace SubscriptionImporter\Simulation;

use SubscriptionImporter\Support\MollieHttpOptions;
use SubscriptionImporter\Support\MollieTestApiKey;
use SubscriptionImporter\Storage\ImportStateRepository;
use SubscriptionImporter\Storage\DatabaseMigrator;

/**
 * MollieSandboxCloner
 */
class MollieSandboxCloner {

	const TEST_IBAN = 'NL55INGB0000000000';
	const API_URL   = 'https://api.mollie.com/v2/';

	/** @var string Test API key (must be set). */
	private $api_key;

	/**
	 * @param string $test_api_key Mollie test API key.
	 */
	public function __construct( $test_api_key = '' ) {
		$this->api_key = is_string( $test_api_key ) ? trim( $test_api_key ) : '';
		if ( $this->api_key === '' ) {
			$this->api_key = MollieTestApiKey::resolve();
		}
	}

	/**
	 * Run clone: for each unique customer in export, create test customer + mandates; store mapping.
	 *
	 * @param array $subscriptions Array of subscription rows from export JSON.
	 * @return array{ created_customers: int, created_mandates: int, mapped: int, errors: string[] }
	 */
	public function run( array $subscriptions ) {
		$created_customers = 0;
		$created_mandates  = 0;
		$mapped            = 0;
		$errors            = array();

		if ( empty( $this->api_key ) ) {
			$errors[] = 'Mollie test API key not configured.';
			return array(
				'created_customers' => 0,
				'created_mandates'  => 0,
				'mapped'            => 0,
				'errors'            => $errors,
			);
		}

		// Group by (export_customer_id, export_mandate_id); keep best display name/email per export customer.
		$by_customer = array();
		$customer_profiles = array();
		foreach ( $subscriptions as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cust_id = isset( $row['mollie']['customer_id'] ) ? trim( $row['mollie']['customer_id'] ) : '';
			$mand_id = isset( $row['mollie']['mandate_id'] ) ? trim( $row['mollie']['mandate_id'] ) : '';
			if ( ! $cust_id || ! $mand_id ) {
				continue;
			}

			$customer = isset( $row['customer'] ) && is_array( $row['customer'] ) ? $row['customer'] : array();
			$display_name = self::resolve_display_name( $customer );
			$email        = isset( $customer['email'] ) ? trim( (string) $customer['email'] ) : '';

			if ( ! isset( $customer_profiles[ $cust_id ] ) ) {
				$customer_profiles[ $cust_id ] = array(
					'name'  => $display_name,
					'email' => $email,
				);
			} else {
				if ( $display_name !== '' ) {
					$customer_profiles[ $cust_id ]['name'] = $display_name;
				}
				if ( $email !== '' ) {
					$customer_profiles[ $cust_id ]['email'] = $email;
				}
			}

			$key = $cust_id . '|' . $mand_id;
			if ( ! isset( $by_customer[ $key ] ) ) {
				$by_customer[ $key ] = array(
					'customer_id' => $cust_id,
					'mandate_id'  => $mand_id,
					'email'       => $email,
					'name'        => $display_name,
				);
			}
		}

		$test_customers = array();
		foreach ( $by_customer as $key => $info ) {
			$export_customer_id = $info['customer_id'];
			$export_mandate_id  = $info['mandate_id'];

			$profile = isset( $customer_profiles[ $export_customer_id ] )
				? $customer_profiles[ $export_customer_id ]
				: array( 'name' => $info['name'], 'email' => $info['email'] );

			if ( ! isset( $test_customers[ $export_customer_id ] ) ) {
				$test_customer = $this->create_test_customer( $profile['name'], $profile['email'] );
				if ( is_wp_error( $test_customer ) ) {
					$errors[] = 'Customer ' . $export_customer_id . ': ' . $test_customer->get_error_message();
					continue;
				}
				$test_customer_id = isset( $test_customer['id'] ) ? $test_customer['id'] : '';
				if ( ! $test_customer_id ) {
					$errors[] = 'Customer ' . $export_customer_id . ': No id in response.';
					continue;
				}
				$test_customers[ $export_customer_id ] = $test_customer_id;
				$created_customers++;
			}

			$test_customer_id = $test_customers[ $export_customer_id ];
			$mandate_name = $info['name'] !== '' ? $info['name'] : $profile['name'];
			$test_mandate = $this->create_test_mandate( $test_customer_id, $mandate_name );
			if ( is_wp_error( $test_mandate ) ) {
				$errors[] = 'Mandate ' . $export_mandate_id . ': ' . $test_mandate->get_error_message();
				continue;
			}
			$test_mandate_id = isset( $test_mandate['id'] ) ? $test_mandate['id'] : '';
			if ( ! $test_mandate_id ) {
				$errors[] = 'Mandate ' . $export_mandate_id . ': No id in response.';
				continue;
			}
			$created_mandates++;

			if ( ImportStateRepository::save_test_mapping( $export_customer_id, $test_customer_id, $export_mandate_id, $test_mandate_id ) ) {
				$mapped++;
			}
		}

		return array(
			'created_customers' => $created_customers,
			'created_mandates'  => $created_mandates,
			'mapped'           => $mapped,
			'errors'            => $errors,
		);
	}

	/**
	 * POST create test customer.
	 *
	 * @param string $name  Name.
	 * @param string $email Email.
	 * @return array|\WP_Error
	 */
	/**
	 * Build display name from export customer fields (non-empty values only).
	 *
	 * @param array $customer customer block from export row.
	 * @return string
	 */
	private static function resolve_display_name( array $customer ) {
		$full = isset( $customer['full_name'] ) ? trim( (string) $customer['full_name'] ) : '';
		if ( $full !== '' ) {
			return $full;
		}

		$first = isset( $customer['first_name'] ) ? trim( (string) $customer['first_name'] ) : '';
		$last  = isset( $customer['last_name'] ) ? trim( (string) $customer['last_name'] ) : '';
		$combined = trim( $first . ' ' . $last );
		if ( $combined !== '' ) {
			return $combined;
		}
		if ( $first !== '' ) {
			return $first;
		}
		if ( $last !== '' ) {
			return $last;
		}

		return '';
	}

	private function create_test_customer( $name, $email ) {
		$url = self::API_URL . 'customers';
		$name = trim( (string) $name );
		$email = trim( (string) $email );
		if ( $name === '' && $email !== '' && strpos( $email, '@' ) !== false ) {
			$local = strstr( $email, '@', true );
			$name  = $local !== false ? str_replace( array( 'user+', '.' ), array( '', ' ' ), $local ) : $email;
		}
		$body = array(
			'name'  => $name !== '' ? $name : 'Test Customer',
			'email' => $email !== '' ? $email : ( 'test+' . wp_rand( 1000, 9999 ) . '@example.com' ),
		);
		return $this->post_json( $url, $body );
	}

	/**
	 * POST create test mandate (directdebit, test IBAN).
	 *
	 * @param string $customer_id   Mollie test customer ID.
	 * @param string $consumer_name Consumer name.
	 * @return array|\WP_Error
	 */
	private function create_test_mandate( $customer_id, $consumer_name ) {
		$url = self::API_URL . 'customers/' . $customer_id . '/mandates';
		$body = array(
			'method'          => 'directdebit',
			'consumerName'    => $consumer_name ?: 'Test Consumer',
			'consumerAccount' => self::TEST_IBAN,
		);
		return $this->post_json( $url, $body );
	}

	/**
	 * POST JSON to Mollie API.
	 *
	 * @param string $url  Full API URL.
	 * @param array  $body Request body.
	 * @return array|\WP_Error
	 */
	private function post_json( $url, array $body ) {
		$response = wp_remote_post(
			$url,
			array_merge(
				MollieHttpOptions::base_args(),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
					'timeout' => 30,
				)
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 300 ) {
			$msg = isset( $data['detail'] ) ? $data['detail'] : ( isset( $data['title'] ) ? $data['title'] : 'API error' );
			return new \WP_Error( 'mollie', $msg );
		}
		return is_array( $data ) ? $data : array();
	}
}
