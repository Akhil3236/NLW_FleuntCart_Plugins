<?php
/**
 * Admin UI: menu pages, settings form, JS/CSS enqueueing.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCWR_Admin {

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
	}

	/**
	 * Add submenu under FluentCart (if it exists) and a fallback top-level entry.
	 */
	public function register_menu() {
		$capability = apply_filters( 'fcwr/required_capability', 'manage_options' );

		// Attempt to nest under FluentCart for better UX.
		if ( $this->fluentcart_menu_exists() ) {
			add_submenu_page(
				'fluent-cart',
				__( 'Webhook Retry', 'fluentcart-webhook-retry' ),
				__( 'Webhook Retry', 'fluentcart-webhook-retry' ),
				$capability,
				'fcwr-logs',
				array( $this, 'render_logs_page' )
			);

			add_submenu_page(
				'fluent-cart',
				__( 'Webhook Retry Settings', 'fluentcart-webhook-retry' ),
				__( 'Webhook Retry · Settings', 'fluentcart-webhook-retry' ),
				$capability,
				'fcwr-settings',
				array( $this, 'render_settings_page' )
			);
		} else {
			// Standalone fallback.
			add_menu_page(
				__( 'Webhook Retry', 'fluentcart-webhook-retry' ),
				__( 'Webhook Retry', 'fluentcart-webhook-retry' ),
				$capability,
				'fcwr-logs',
				array( $this, 'render_logs_page' ),
				'dashicons-update',
				58
			);

			add_submenu_page(
				'fcwr-logs',
				__( 'Settings', 'fluentcart-webhook-retry' ),
				__( 'Settings', 'fluentcart-webhook-retry' ),
				$capability,
				'fcwr-settings',
				array( $this, 'render_settings_page' )
			);
		}
	}

	/**
	 * Detect whether FluentCart's menu is currently registered.
	 *
	 * @return bool
	 */
	private function fluentcart_menu_exists() {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return false;
		}
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && 'fluent-cart' === $item[2] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Enqueue admin assets on:
	 *  - our own plugin pages
	 *  - FluentCart's order admin page (so the button injects on the order screen)
	 *
	 * @param string $hook
	 */
	public function enqueue_assets( $hook ) {
		$is_fcwr_page = isset( $_GET['page'] ) && in_array( $_GET['page'], array( 'fcwr-logs', 'fcwr-settings' ), true ); // phpcs:ignore WordPress.Security.NonceVerification
		$is_fc_page   = isset( $_GET['page'] ) && 'fluent-cart' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $is_fcwr_page && ! $is_fc_page ) {
			return;
		}

		wp_enqueue_style(
			'fcwr-admin',
			FCWR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			FCWR_VERSION
		);

		wp_enqueue_script(
			'fcwr-admin',
			FCWR_PLUGIN_URL . 'assets/js/admin.js',
			array( 'wp-api-fetch' ),
			FCWR_VERSION,
			true
		);

		wp_localize_script( 'fcwr-admin', 'FCWR', array(
			'restRoot'    => esc_url_raw( rest_url( FCWR_Rest_API::NAMESPACE_V1 ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'isFCPage'    => $is_fc_page,
			'isFCWRPage'  => $is_fcwr_page,
			'adminUrl'    => admin_url( 'admin.php' ),
			'i18n'        => array(
				'retry'           => __( 'Retry Webhook', 'fluentcart-webhook-retry' ),
				'retrying'        => __( 'Retrying…', 'fluentcart-webhook-retry' ),
				'success'         => __( 'Webhook resent successfully.', 'fluentcart-webhook-retry' ),
				'failed'          => __( 'Retry failed.', 'fluentcart-webhook-retry' ),
				'confirmDelete'   => __( 'Delete this log entry?', 'fluentcart-webhook-retry' ),
				'noFailedWebhook' => __( 'No matching failed webhook found for this order.', 'fluentcart-webhook-retry' ),
				'viewDetails'     => __( 'View Details', 'fluentcart-webhook-retry' ),
			),
		) );
	}

	/**
	 * Handle settings form submission.
	 */
	public function handle_settings_save() {
		if ( empty( $_POST['fcwr_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fcwr_settings_nonce'] ) ), 'fcwr_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( apply_filters( 'fcwr/required_capability', 'manage_options' ) ) ) {
			return;
		}

		$values = array(
			'watch_urls'         => isset( $_POST['watch_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['watch_urls'] ) ) : '',
			'log_successes'      => ! empty( $_POST['log_successes'] ) ? 1 : 0,
			'max_retries'        => isset( $_POST['max_retries'] ) ? max( 1, (int) $_POST['max_retries'] ) : 10,
			'retry_window_sec'   => isset( $_POST['retry_window_sec'] ) ? max( 1, (int) $_POST['retry_window_sec'] ) : 60,
			'retries_per_window' => isset( $_POST['retries_per_window'] ) ? max( 1, (int) $_POST['retries_per_window'] ) : 5,
			'auto_purge_days'    => isset( $_POST['auto_purge_days'] ) ? max( 0, (int) $_POST['auto_purge_days'] ) : 30,
		);

		FCWR_Settings::update( $values );

		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Settings saved.', 'fluentcart-webhook-retry' ) .
				'</p></div>';
		} );
	}

	/**
	 * Render the logs page.
	 */
	public function render_logs_page() {
		require FCWR_PLUGIN_DIR . 'templates/admin-logs.php';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		$settings = FCWR_Settings::get();
		require FCWR_PLUGIN_DIR . 'templates/admin-settings.php';
	}
}
