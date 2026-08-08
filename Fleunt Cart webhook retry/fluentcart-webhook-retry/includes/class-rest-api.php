<?php
/**
 * REST API endpoints for the retry UI.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCWR_Rest_API {

	const NAMESPACE_V1 = 'fluentcart-webhook-retry/v1';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// GET /logs — list with filtering.
		register_rest_route( self::NAMESPACE_V1, '/logs', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'list_logs' ),
			'permission_callback' => array( $this, 'permission_check' ),
			'args'                => array(
				'status'   => array(
					'type'     => 'string',
					'enum'     => array( 'all', 'failed', 'success' ),
					'default'  => 'failed',
				),
				'order_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'page'     => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'per_page' => array(
					'type'              => 'integer',
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// GET /logs/{id} — single log entry detail.
		register_rest_route( self::NAMESPACE_V1, '/logs/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_log' ),
			'permission_callback' => array( $this, 'permission_check' ),
		) );

		// POST /logs/{id}/retry — retry a single log entry.
		register_rest_route( self::NAMESPACE_V1, '/logs/(?P<id>\d+)/retry', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'retry_log' ),
			'permission_callback' => array( $this, 'permission_check' ),
		) );

		// DELETE /logs/{id} — remove a log entry.
		register_rest_route( self::NAMESPACE_V1, '/logs/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'delete_log' ),
			'permission_callback' => array( $this, 'permission_check' ),
		) );
	}

	/**
	 * Capability gate. Anyone who can manage WooCommerce-style orders can use this;
	 * default to manage_options. Filter to widen.
	 *
	 * @return bool
	 */
	public function permission_check() {
		$capability = apply_filters( 'fcwr/required_capability', 'manage_options' );
		return current_user_can( $capability );
	}

	/**
	 * GET /logs
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function list_logs( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . FCWR_TABLE;

		$status   = $request->get_param( 'status' );
		$order_id = $request->get_param( 'order_id' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$values = array();

		if ( $status && 'all' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		if ( $order_id ) {
			$where[]  = 'order_id = %d';
			$values[] = $order_id;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) : $wpdb->get_var( $count_sql ) );

		$rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$query_values = array_merge( $values, array( $per_page, $offset ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( $rows_sql, $query_values ), ARRAY_A );

		return new WP_REST_Response( array(
			'items'    => array_map( array( $this, 'format_log' ), $rows ?: array() ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		), 200 );
	}

	/**
	 * GET /logs/{id}
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_log( WP_REST_Request $request ) {
		global $wpdb;
		$id    = (int) $request->get_param( 'id' );
		$table = $wpdb->prefix . FCWR_TABLE;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'fcwr_not_found', __( 'Log entry not found.', 'fluentcart-webhook-retry' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->format_log( $row, true ), 200 );
	}

	/**
	 * POST /logs/{id}/retry
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function retry_log( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$service = new FCWR_Retry_Service();
		$result  = $service->retry( $id );

		$status = $result['success'] ? 200 : 400;

		// Special case: rate limit returns 429.
		if ( ! $result['success'] && false !== strpos( strtolower( $result['message'] ), 'too many' ) ) {
			$status = 429;
		}

		return new WP_REST_Response( $result, $status );
	}

	/**
	 * DELETE /logs/{id}
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_log( WP_REST_Request $request ) {
		global $wpdb;
		$id    = (int) $request->get_param( 'id' );
		$table = $wpdb->prefix . FCWR_TABLE;

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $deleted ) {
			return new WP_Error( 'fcwr_delete_failed', __( 'Failed to delete log entry.', 'fluentcart-webhook-retry' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'deleted' => (bool) $deleted ), 200 );
	}

	/**
	 * Shape a log row for API output.
	 *
	 * @param array $row
	 * @param bool  $include_bodies Whether to include request/response bodies.
	 * @return array
	 */
	private function format_log( $row, $include_bodies = false ) {
		$out = array(
			'id'             => (int) $row['id'],
			'order_id'       => $row['order_id'] !== null ? (int) $row['order_id'] : null,
			'url'            => $row['url'],
			'method'         => $row['method'],
			'response_code'  => $row['response_code'] !== null ? (int) $row['response_code'] : null,
			'status'         => $row['status'],
			'retry_count'    => (int) $row['retry_count'],
			'parent_log_id'  => $row['parent_log_id'] !== null ? (int) $row['parent_log_id'] : null,
			'retried_by'     => $row['retried_by'] !== null ? (int) $row['retried_by'] : null,
			'error_message'  => $row['error_message'],
			'created_at'     => $row['created_at'],
			'updated_at'     => $row['updated_at'],
		);

		if ( $include_bodies ) {
			$out['request_headers'] = $row['request_headers'] ? json_decode( $row['request_headers'], true ) : null;
			$out['request_body']    = $row['request_body'];
			$out['response_body']   = $row['response_body'];
		}

		return $out;
	}
}
