<?php
/**
 * Admin page: webhook logs list.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Webhook Retry — Logs', 'fluentcart-webhook-retry' ); ?></h1>

	<p>
		<?php esc_html_e( 'All captured outgoing webhooks (failures by default). Click Retry to resend the exact original request.', 'fluentcart-webhook-retry' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=fcwr-settings' ) ); ?>">
			<?php esc_html_e( 'Configure which URLs are captured →', 'fluentcart-webhook-retry' ); ?>
		</a>
	</p>

	<div id="fcwr-logs-app">
		<p><?php esc_html_e( 'Loading…', 'fluentcart-webhook-retry' ); ?></p>
	</div>
</div>
