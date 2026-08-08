<?php
/**
 * Admin page: plugin settings.
 *
 * @package FluentCart_Webhook_Retry
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array $settings Passed in by the admin class. */
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Webhook Retry — Settings', 'fluentcart-webhook-retry' ); ?></h1>

	<form method="post" action="">
		<?php wp_nonce_field( 'fcwr_save_settings', 'fcwr_settings_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="watch_urls"><?php esc_html_e( 'Watched URLs', 'fluentcart-webhook-retry' ); ?></label>
				</th>
				<td>
					<textarea
						id="watch_urls"
						name="watch_urls"
						rows="6"
						cols="60"
						class="large-text code"
						placeholder="https://your-integration.example.com/webhooks/*&#10;https://another-service.com/api/hook"
					><?php echo esc_textarea( $settings['watch_urls'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One URL or pattern per line. Wildcards (*) are supported. Only requests matching one of these patterns are captured.', 'fluentcart-webhook-retry' ); ?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Example:', 'fluentcart-webhook-retry' ); ?></strong>
						<code>https://exact-server.nextlevelweb.com/webhooks/*</code>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="log_successes"><?php esc_html_e( 'Log successful webhooks', 'fluentcart-webhook-retry' ); ?></label>
				</th>
				<td>
					<label>
						<input
							type="checkbox"
							id="log_successes"
							name="log_successes"
							value="1"
							<?php checked( ! empty( $settings['log_successes'] ) ); ?>
						/>
						<?php esc_html_e( 'Also store successful (2xx) webhook calls. Useful for debugging; uses more DB space.', 'fluentcart-webhook-retry' ); ?>
					</label>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="max_retries"><?php esc_html_e( 'Max retries per log', 'fluentcart-webhook-retry' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="max_retries"
						name="max_retries"
						min="1"
						value="<?php echo esc_attr( (int) $settings['max_retries'] ); ?>"
					/>
					<p class="description"><?php esc_html_e( 'Hard cap on total retry attempts per single log entry.', 'fluentcart-webhook-retry' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Rate limit', 'fluentcart-webhook-retry' ); ?></th>
				<td>
					<input
						type="number"
						name="retries_per_window"
						min="1"
						value="<?php echo esc_attr( (int) $settings['retries_per_window'] ); ?>"
					/>
					<?php esc_html_e( 'retries per', 'fluentcart-webhook-retry' ); ?>
					<input
						type="number"
						name="retry_window_sec"
						min="1"
						value="<?php echo esc_attr( (int) $settings['retry_window_sec'] ); ?>"
					/>
					<?php esc_html_e( 'seconds', 'fluentcart-webhook-retry' ); ?>
					<p class="description"><?php esc_html_e( 'Prevents accidental hammering of the receiving service.', 'fluentcart-webhook-retry' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="auto_purge_days"><?php esc_html_e( 'Auto-purge old logs after', 'fluentcart-webhook-retry' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="auto_purge_days"
						name="auto_purge_days"
						min="0"
						value="<?php echo esc_attr( (int) $settings['auto_purge_days'] ); ?>"
					/>
					<?php esc_html_e( 'days', 'fluentcart-webhook-retry' ); ?>
					<p class="description"><?php esc_html_e( 'Set to 0 to keep logs forever. Runs once a day.', 'fluentcart-webhook-retry' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'fluentcart-webhook-retry' ) ); ?>
	</form>

	<hr/>

	<h2><?php esc_html_e( 'Quick check', 'fluentcart-webhook-retry' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %s: REST API URL */
			esc_html__( 'REST namespace: %s', 'fluentcart-webhook-retry' ),
			'<code>' . esc_html( rest_url( FCWR_Rest_API::NAMESPACE_V1 ) ) . '</code>'
		);
		?>
	</p>
</div>
