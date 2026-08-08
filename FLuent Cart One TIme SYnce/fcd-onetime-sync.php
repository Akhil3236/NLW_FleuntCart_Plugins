<?php
/**
 * Plugin Name: FCD One-Time Donation Sync
 * Description: Three-path sync for one-time direct donations: webhook (instant), sync-on-redirect (donor's own return triggers their sync), and 1-min cron (safety net). Creates FluentCart order + transaction. Idempotent.
 * Version:     1.2.0
 * Author:      NextLevelWeb
 * License:     GPL-2.0+
 *
 * Flow:
 *   one-time donation -> fluentcart-donations direct -> Mollie (paid)
 *     -> Mollie calls THIS webhook
 *     -> fetch payment, verify paid + oneoff + source in allowed list
 *     -> create Customer + Order + OrderItem + OrderTransaction (matching real shape)
 *
 * The donation plugin's Mollie payment must point its webhookUrl here. This
 * plugin exposes that URL and filters the donation plugin's webhook to use it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FCD_SYNC_QV_KEY', 'fcd-onetime-sync' );
define( 'FCD_SYNC_QV_VAL', 'mollie-ipn' );

/* -------------------------------------------------------------------------
 * Tell the donation plugin to send its one-time Mollie webhook HERE.
 * The fluentcart-donations direct flow exposes this filter.
 * ---------------------------------------------------------------------- */
add_filter( 'fluentcart_donations/mollie_direct/webhook_url', function ( $url ) {
	return fcd_sync_webhook_url();
} );

// Also make sure the donation plugin INCLUDES a webhook even on local, so Mollie calls us.
add_filter( 'fluentcart_donations/mollie_direct/include_webhook', '__return_true' );

/**
 * Public webhook URL Mollie should call. Honors the tunnel base used site-wide.
 */
function fcd_sync_webhook_url() {
	$url = home_url( '/?' . FCD_SYNC_QV_KEY . '=' . FCD_SYNC_QV_VAL );

	if ( defined( 'FCT_MOLLIE_WEBHOOK_BASE' ) && FCT_MOLLIE_WEBHOOK_BASE ) {
		$parsed = wp_parse_url( $url );
		$base   = rtrim( FCT_MOLLIE_WEBHOOK_BASE, '/' );
		$path   = ! empty( $parsed['path'] ) ? $parsed['path'] : '/';
		$query  = ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		return $base . $path . $query;
	}
	return $url;
}

/* -------------------------------------------------------------------------
 * Webhook listener (query-arg, fires early on init).
 * ---------------------------------------------------------------------- */
add_action( 'init', 'fcd_sync_maybe_handle_webhook', 1 );

function fcd_sync_maybe_handle_webhook() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$flag = isset( $_GET[ FCD_SYNC_QV_KEY ] ) ? sanitize_text_field( wp_unslash( $_GET[ FCD_SYNC_QV_KEY ] ) ) : '';
	if ( FCD_SYNC_QV_VAL !== $flag ) {
		return;
	}

	// Mollie posts the payment id as `id`.
	$payment_id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
	// Mollie payment ids are prefixed `tr_`. Anything else is junk or an
	// unauthenticated probe — ack and drop WITHOUT scheduling a job or making
	// an outbound API call, so this endpoint can't be used for amplification.
	if ( '' === $payment_id || 0 !== strpos( $payment_id, 'tr_' ) ) {
		status_header( 200 ); // nothing actionable, ack so Mollie stops
		exit;
	}

	// Fast ack: schedule the heavy work for immediate background execution
	// and return 200 to Mollie in ~50ms. Under bursts, we don't tie up Mollie's
	// connection while we do DB inserts. wp_schedule_single_event fires on the
	// very next request or WP-Cron tick — usually within seconds.
	if ( ! wp_next_scheduled( 'fcd_sync_process_payment_async', array( $payment_id ) ) ) {
		wp_schedule_single_event( time(), 'fcd_sync_process_payment_async', array( $payment_id ) );
	}
	// Kick WP-Cron now so the queued job runs immediately even on low-traffic sites.
	if ( function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}

	status_header( 200 );
	exit;
}

/**
 * Background handler that actually processes the payment. Called by
 * wp_schedule_single_event from the webhook. Result is logged.
 */
add_action( 'fcd_sync_process_payment_async', 'fcd_sync_process_payment_async_handler' );
function fcd_sync_process_payment_async_handler( $payment_id ) {
	$t0 = microtime( true );
	$result = fcd_sync_process_payment( $payment_id );
	fcd_sync_log_event( 'webhook_async', $payment_id, $result, $t0 );
}

/* -------------------------------------------------------------------------
 * SYNC-ON-REDIRECT
 *
 * When Mollie sends the donor back to the site (?fcd_donation=paid), do a
 * quick sync of the last 10 minutes of Mollie payments in the same request,
 * BEFORE the page renders. Their payment appears in FluentCart by the time
 * they see the "thank you" page — no wait for cron, no wait for webhook.
 *
 * Guarded so we only do the API call once per session per short window
 * (a transient) — avoids hammering Mollie if the donor refreshes.
 * ---------------------------------------------------------------------- */
add_action( 'template_redirect', 'fcd_sync_maybe_handle_donor_return', 5 );

function fcd_sync_maybe_handle_donor_return() {
	// Only fire on the exact redirect Mollie sends the donor to.
	if ( empty( $_GET['fcd_donation'] ) || 'paid' !== $_GET['fcd_donation'] ) {
		return;
	}
	if ( ! fcd_sync_fc_available() ) {
		return;
	}

	// Deduplicate: at most one poll per 60s per client IP+UA. Cheap and effective.
	$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
	$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( wp_hash( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 12 ) : '0';
	$lock_k = 'fcd_sync_ret_' . md5( $ip . '|' . $ua );
	if ( get_transient( $lock_k ) ) {
		return;
	}
	set_transient( $lock_k, 1, 60 );

	// Freshly-made payment: look back 10 minutes, and cap to a SINGLE Mollie
	// page so the donor's thank-you page never blocks on more than one API
	// call. Their payment is the newest listed, so one page is enough.
	add_filter( 'fcd_sync_poll_since', 'fcd_sync_recent_window' );
	add_filter( 'fcd_sync_poll_max_pages', 'fcd_sync_single_page' );
	fcd_sync_cron_poll();
	remove_filter( 'fcd_sync_poll_max_pages', 'fcd_sync_single_page' );
	remove_filter( 'fcd_sync_poll_since', 'fcd_sync_recent_window' );
}

function fcd_sync_recent_window() {
	return gmdate( 'c', time() - 10 * MINUTE_IN_SECONDS );
}

function fcd_sync_single_page() {
	return 1;
}

/* -------------------------------------------------------------------------
 * ADMIN AUTO-POLL
 *
 * Whenever someone is working in wp-admin (e.g. viewing FluentCart Orders),
 * run a quick throttled poll so new donations appear without pressing any
 * button. Throttle: at most once per 60s site-wide. Window: last 15 minutes.
 * Runs after the page renders (shutdown) so it never slows the admin UI.
 * ---------------------------------------------------------------------- */
add_action( 'admin_init', function () {
	// Skip AJAX/REST/cron admin requests — only real page loads.
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	if ( get_transient( 'fcd_sync_admin_poll_lock' ) ) {
		return;
	}
	set_transient( 'fcd_sync_admin_poll_lock', 1, 60 );

	// Defer to shutdown so the admin page renders at full speed first.
	add_action( 'shutdown', function () {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request(); // flush response to the browser, keep working
		}
		add_filter( 'fcd_sync_poll_since', 'fcd_sync_admin_poll_window' );
		fcd_sync_cron_poll();
		remove_filter( 'fcd_sync_poll_since', 'fcd_sync_admin_poll_window' );
	}, 99 );
} );

function fcd_sync_admin_poll_window() {
	return gmdate( 'c', time() - 15 * MINUTE_IN_SECONDS );
}

/**
 * Fetch the Mollie payment and, if it's a paid one-time direct donation that
 * isn't already in FluentCart, create the order. Idempotent on vendor_charge_id.
 *
 * @return true|WP_Error
 */
function fcd_sync_process_payment( $payment_id ) {
	if ( ! fcd_sync_fc_available() ) {
		return new WP_Error( 'fc_unavailable', 'FluentCart not available' );
	}

	global $wpdb;

	// Serialize processing of the SAME payment id across every trigger
	// (webhook, donor-return, admin poll, cron). Without this, two triggers
	// firing at nearly the same moment can both pass the "already synced?"
	// check in the inner function and each create an order — duplicate
	// donations. A named MySQL lock works across separate PHP processes
	// (cron runs in its own request with its own DB connection). Lock name is
	// capped well under MySQL's 64-char limit.
	$lock      = 'fcd_sync_' . substr( md5( $payment_id ), 0, 24 );
	$have_lock = ( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 10 ) ) );

	try {
		return fcd_sync_process_payment_locked( $payment_id );
	} finally {
		if ( $have_lock ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}

/**
 * Inner processor. Assumes the caller (fcd_sync_process_payment) holds the
 * per-payment lock. Fetches the Mollie payment and, if it's a paid one-time
 * direct donation not already in FluentCart, creates the order. Idempotent on
 * vendor_charge_id.
 *
 * @return true|WP_Error
 */
function fcd_sync_process_payment_locked( $payment_id ) {
	if ( ! fcd_sync_fc_available() ) {
		return new WP_Error( 'fc_unavailable', 'FluentCart not available' );
	}

	// Idempotency: if a transaction already carries this Mollie id, stop.
	$existing = \FluentCart\App\Models\OrderTransaction::query()
		->where( 'vendor_charge_id', $payment_id )->first();
	if ( $existing ) {
		return true; // already synced (or created by another flow)
	}

	$key = fcd_sync_mollie_key();
	if ( '' === $key ) {
		return new WP_Error( 'transient', 'No Mollie key' );
	}

	// Fetch the real payment from Mollie.
	$res = wp_remote_get( 'https://api.mollie.com/v2/payments/' . rawurlencode( $payment_id ), array(
		'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		'timeout' => 10,
	) );
	if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
		return new WP_Error( 'transient', 'Mollie fetch failed' );
	}
	$p = json_decode( wp_remote_retrieve_body( $res ), true );

	// Only handle PAID, one-time, direct-donation payments. Everything else: not ours.
	$status   = $p['status'] ?? '';
	$seq      = $p['sequenceType'] ?? '';
	$source   = $p['metadata']['source'] ?? '';
	if ( 'paid' !== $status ) {
		return new WP_Error( 'not_paid', 'Not paid' );
	}
	if ( 'oneoff' !== $seq ) {
		return new WP_Error( 'not_oneoff', 'Not one-time' ); // monthly handled by FluentCart itself
	}
	if ( ! in_array( $source, fcd_sync_allowed_sources(), true ) ) {
		return new WP_Error( 'not_ours', 'Not a direct donation' );
	}

	$value    = $p['amount']['value'] ?? '0';
	$currency = $p['amount']['currency'] ?? 'EUR';
	$cents    = (int) round( ( (float) $value ) * 100 );
	if ( $cents <= 0 ) {
		return new WP_Error( 'bad_amount', 'Bad amount' );
	}

	$email = $p['metadata']['donor_email'] ?? ( $p['billingAddress']['email'] ?? '' );
	$email = sanitize_email( (string) $email );

	// Resolve the donor name from the first non-empty source. Note: `??` only
	// falls through on unset/null, so a form that submits donor_name="" would
	// otherwise stick us with a blank name — we test the trimmed value instead.
	// When the donor leaves the (optional) name field empty, fall back to the
	// account holder name Mollie returns from the bank/card. `details` is
	// populated once a payment is paid, which is always true here.
	$name = '';
	foreach ( array(
		$p['metadata']['donor_name'] ?? '',                              // what the donor typed (optional field)
		trim( ( $p['billingAddress']['givenName'] ?? '' ) . ' ' . ( $p['billingAddress']['familyName'] ?? '' ) ),
		$p['details']['consumerName'] ?? '',                             // iDEAL / SEPA / bank transfer: account holder
		$p['details']['cardHolder'] ?? '',                               // credit / debit card: name on card
		$p['details']['bankHolderName'] ?? '',                           // some bank-transfer variants
	) as $candidate ) {
		$candidate = trim( (string) $candidate );
		if ( '' !== $candidate ) {
			$name = $candidate;
			break;
		}
	}
	$name  = sanitize_text_field( $name );
	$mode  = ( ( $p['mode'] ?? 'test' ) === 'live' ) ? 'live' : 'test';
	$paid_at = ! empty( $p['paidAt'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $p['paidAt'] ) ) : current_time( 'mysql' );

	$parts = preg_split( '/\s+/', $name, 2 );
	$first = $parts[0] ?? '';
	$last  = $parts[1] ?? '';

	// ---- Mollie metadata → stored on the FluentCart order -------------------
	// Mirrors the payment's `metadata` block plus the Mollie id/method, so the
	// order itself carries who donated, for which project, and via what.
	$meta_in = is_array( $p['metadata'] ?? null ) ? $p['metadata'] : array();
	$order_meta = array(
		'source'            => sanitize_text_field( (string) ( $meta_in['source'] ?? '' ) ),
		'project'           => sanitize_text_field( (string) ( $meta_in['project'] ?? '' ) ),
		'client_request_id' => sanitize_text_field( (string) ( $meta_in['client_request_id'] ?? '' ) ),
		'donor_email'       => $email,
		'donor_name'        => $name,
		'mollie_payment_id' => $payment_id,
		'mollie_method'     => sanitize_text_field( (string) ( $p['method'] ?? '' ) ),
	);
	// -------------------------------------------------------------------------

	try {
		global $wpdb;
		// Atomic: if anything below fails, roll back so no half-created orders remain.
		$wpdb->query( 'START TRANSACTION' );

		// Customer: find by email else create.
		$customer = null;
		if ( $email ) {
			$customer = \FluentCart\App\Models\Customer::query()->where( 'email', $email )->first();
		}
		if ( ! $customer ) {
			$customer = \FluentCart\App\Models\Customer::query()->create( array(
				'email'      => $email ?: '',
				'first_name' => $first ?: 'Donateur',
				'last_name'  => $last,
				'status'     => 'active',
				'uuid'       => md5( time() . wp_generate_uuid4() ),
			) );
		}

		// Order — mirrors the real paid Mollie order shape (completed / paid / cents).
		// `config` carries the Mollie metadata (donor, project, method) so the
		// order itself records who gave, for what, and how. `note` (if the
		// column exists) shows a readable summary on the order screen.
		$order = \FluentCart\App\Models\Order::query()->create( fcd_sync_only_real_columns( 'fct_orders', array(
			'status'           => 'completed',
			'payment_status'   => 'paid',
			'type'             => 'payment',
			'fulfillment_type' => 'digital',
			'customer_id'      => $customer->id,
			'payment_method'   => 'mollie',
			'currency'         => $currency,
			'subtotal'         => $cents,
			'total_amount'     => $cents,
			'total_paid'       => $cents,
			'mode'             => $mode,
			'completed_at'     => $paid_at,
			'note'             => implode( ' | ', array_filter( array(
				$order_meta['project'] ? 'Project: ' . $order_meta['project'] : '',
				$order_meta['donor_name'] ? 'Donateur: ' . $order_meta['donor_name'] : '',
				$order_meta['donor_email'] ? 'E-mail: ' . $order_meta['donor_email'] : '',
				$order_meta['mollie_method'] ? 'Methode: ' . $order_meta['mollie_method'] : '',
				'Mollie: ' . $payment_id,
			) ) ),
			'config'           => $order_meta,
		), array( 'config' ) ) );

		// Donation line item — insert ONLY columns that exist in this install's table.
		// Title carries the project ("Donatie — School Renovation") so it's
		// visible at a glance in the FluentCart orders list.
		$item_title = $order_meta['project'] ? 'Donatie — ' . $order_meta['project'] : 'Donatie';
		\FluentCart\App\Models\OrderItem::query()->create( fcd_sync_only_real_columns( 'fct_order_items', array(
			'order_id'         => $order->id,
			'post_id'          => 0,
			'object_id'        => 0,
			'object_type'      => 'custom',
			'cart_index'       => 0,
			'post_title'       => $item_title,
			'title'            => $item_title,
			'fulfillment_type' => 'digital',
			'payment_type'     => 'onetime',
			'quantity'         => 1,
			'unit_price'       => $cents,
			'subtotal'         => $cents,
			'line_total'       => $cents,
			'tax_amount'       => 0,
			'discount_total'   => 0,
			'refund_total'     => 0,
			'rate'             => 1,
		) ) );

		// Transaction — succeeded, carries the Mollie tr_ id (matches real shape).
		\FluentCart\App\Models\OrderTransaction::query()->create( fcd_sync_only_real_columns( 'fct_order_transactions', array(
			'order_id'            => $order->id,
			'order_type'          => 'payment',
			'vendor_charge_id'    => $payment_id,
			'payment_method'      => 'mollie',
			'payment_method_type' => $order_meta['mollie_method'], // paypal / ideal / creditcard…
			'payment_mode'        => $mode,
			'transaction_type'    => 'charge',
			'status'              => 'succeeded',
			'total'               => $cents,
			'currency'            => $currency,
			'meta'                => $order_meta,
			'uuid'                => md5( time() . wp_generate_uuid4() ),
			'created_at'          => $paid_at,
		) ) );

		$wpdb->query( 'COMMIT' );

		do_action( 'fcd_sync_order_created', $order, $p );
		return true;
	} catch ( \Throwable $e ) {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		if ( function_exists( 'fluent_cart_add_log' ) ) {
			fluent_cart_add_log( 'FCD One-Time Sync error', $e->getMessage(), 'error' );
		}
		return new WP_Error( 'create_failed', $e->getMessage() );
	}
}

/**
 * Filter an insert array down to columns that actually exist in the table.
 * Cached per table per request. $always_keep passes through model-cast
 * virtual attributes (e.g. 'config') that models serialize themselves.
 */
function fcd_sync_only_real_columns( $table_no_prefix, array $data, array $always_keep = array() ) {
	static $cache = array();
	global $wpdb;

	$table = $wpdb->prefix . $table_no_prefix;
	if ( ! isset( $cache[ $table ] ) ) {
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		$cache[ $table ] = is_array( $cols ) ? array_flip( $cols ) : array();
	}
	$existing = $cache[ $table ];
	if ( empty( $existing ) ) {
		return $data; // can't verify — pass through unchanged
	}

	$out = array();
	foreach ( $data as $col => $val ) {
		if ( isset( $existing[ $col ] ) || in_array( $col, $always_keep, true ) ) {
			$out[ $col ] = $val;
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */
function fcd_sync_fc_available() {
	return class_exists( '\FluentCart\App\Models\Order' )
		&& class_exists( '\FluentCart\App\Models\OrderItem' )
		&& class_exists( '\FluentCart\App\Models\OrderTransaction' )
		&& class_exists( '\FluentCart\App\Models\Customer' );
}

/**
 * Resolve the Mollie API key. FluentCart's saved key is the source of truth
 * per environment; the FCD_MOLLIE_API_KEY constant is only a last-resort
 * fallback so a cloned dev config can't hijack live/staging.
 *
 * Returns [ key, source ] — source is 'fluentcart' | 'constant' | 'none'.
 */
function fcd_sync_mollie_key_with_source() {
	$s = get_option( 'fct_payment_mollie_settings', array() );
	if ( is_array( $s ) && ! empty( $s['api_key'] ) ) {
		return array( (string) $s['api_key'], 'fluentcart' );
	}
	// Some FluentCart versions store gateway settings under a different option.
	$g = get_option( 'fluent_cart_payment_settings', array() );
	if ( is_array( $g ) && ! empty( $g['mollie']['api_key'] ) ) {
		return array( (string) $g['mollie']['api_key'], 'fluentcart' );
	}
	if ( defined( 'FCD_MOLLIE_API_KEY' ) && FCD_MOLLIE_API_KEY ) {
		return array( (string) FCD_MOLLIE_API_KEY, 'constant' );
	}
	return array( '', 'none' );
}

function fcd_sync_mollie_key() {
	list( $key ) = fcd_sync_mollie_key_with_source();
	return $key;
}

/**
 * The Mollie metadata.source values we treat as our one-time donations.
 * fluentcart-donations exposes TWO one-time paths that stamp different sources:
 *   - fluentcart-donations-direct (REST /donation-mollie-direct path)
 *   - fcd-mollie-pay              (form-post /fcd-mollie-checkout.php path)
 * Which one runs depends on the site (whether fcd-mollie-checkout.php exists).
 * Both are legitimate — accept both. Filterable so ops can add more if needed.
 */
function fcd_sync_allowed_sources() {
	return apply_filters( 'fcd_sync_allowed_sources', array(
		'fluentcart-donations-direct',
		'fcd-mollie-pay',
	) );
}

/**
 * Ring buffer of recent sync events. Kept in an option (not autoloaded),
 * capped so it can't grow unbounded. Also mirrors to FluentCart's activity log.
 *
 * @param string $trigger 'webhook_async' | 'cron' | 'redirect'
 * @param string $payment_id Mollie payment id
 * @param true|WP_Error $result what fcd_sync_process_payment returned
 * @param float  $t0 microtime(true) at start
 */
function fcd_sync_log_event( $trigger, $payment_id, $result, $t0 ) {
	$ms      = (int) round( ( microtime( true ) - $t0 ) * 1000 );
	$is_ok   = ( true === $result );
	$outcome = $is_ok ? 'ok' : ( is_wp_error( $result ) ? $result->get_error_code() : 'unknown' );
	$msg     = $is_ok ? '' : ( is_wp_error( $result ) ? $result->get_error_message() : '' );

	$entry = array(
		'ts'      => time(),
		'trigger' => $trigger,
		'pid'     => $payment_id,
		'outcome' => $outcome,
		'ms'      => $ms,
		'msg'     => substr( (string) $msg, 0, 200 ),
	);

	$log = get_option( 'fcd_sync_events', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift( $log, $entry );
	if ( count( $log ) > 100 ) {
		$log = array_slice( $log, 0, 100 );
	}
	update_option( 'fcd_sync_events', $log, false );

	// Mirror errors to FluentCart's log so ops see them in the normal place.
	if ( ! $is_ok && function_exists( 'fluent_cart_add_log' ) ) {
		fluent_cart_add_log(
			'FCD One-Time Sync',
			sprintf( '[%s] %s payment=%s ms=%d %s', $trigger, $outcome, $payment_id, $ms, $msg ),
			'error'
		);
	}
}

/* -------------------------------------------------------------------------
 * CRON POLLER (safety net + local-dev friendly)
 *
 * Runs every minute. Lists recent paid Mollie payments and processes any
 * one-time direct donations that aren't yet in FluentCart. Same processor as
 * the webhook, so results are identical. Idempotent on vendor_charge_id.
 * ---------------------------------------------------------------------- */

const FCD_SYNC_CRON_HOOK = 'fcd_sync_poll_mollie';

// Custom 1-min schedule.
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( empty( $schedules['fcd_one_minute'] ) ) {
		$schedules['fcd_one_minute'] = array( 'interval' => 60, 'display' => 'Every Minute' );
	}
	return $schedules;
} );

// Activation / deactivation.
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( FCD_SYNC_CRON_HOOK ) ) {
		wp_schedule_event( time() + 30, 'fcd_one_minute', FCD_SYNC_CRON_HOOK );
	}
} );
register_deactivation_hook( __FILE__, function () {
	$ts = wp_next_scheduled( FCD_SYNC_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, FCD_SYNC_CRON_HOOK );
	}
} );

add_action( FCD_SYNC_CRON_HOOK, 'fcd_sync_cron_poll' );

/**
 * Poll Mollie for recent paid payments and sync any missing one-time donations.
 * Returns array( processed, created, skipped, errors ) — used by admin "Run now".
 */
function fcd_sync_cron_poll() {
	$stats = array( 'processed' => 0, 'created' => 0, 'skipped' => 0, 'errors' => 0 );

	if ( ! fcd_sync_fc_available() ) {
		return $stats;
	}
	$key = fcd_sync_mollie_key();
	if ( '' === $key ) {
		return $stats;
	}

	// Time-box the window: only fetch payments from the last 24h by default.
	// Filterable so ops can widen for a backfill run.
	$since = apply_filters( 'fcd_sync_poll_since', gmdate( 'c', time() - 24 * HOUR_IN_SECONDS ) );

	// Mollie /v2/payments returns most recent first, paginated by cursor.
	// We walk pages until we cross the time floor OR hit a safety cap.
	$limit     = (int) apply_filters( 'fcd_sync_poll_limit', 100 );        // per-page
	$max_pages = (int) apply_filters( 'fcd_sync_poll_max_pages', 5 );      // 5 pages * 100 = 500 payments/tick
	$next_url  = add_query_arg( array( 'limit' => $limit ), 'https://api.mollie.com/v2/payments' );
	$since_ts  = strtotime( $since );
	$pages     = 0;
	$total_listed = 0;
	$stop      = false;
	$ok        = true;

	while ( $next_url && $pages < $max_pages && ! $stop ) {
		$pages++;
		$res = wp_remote_get( $next_url, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
			$stats['errors']++;
			$ok = false;
			break;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$list = $body['_embedded']['payments'] ?? array();
		$total_listed += count( $list );

		foreach ( $list as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) ) {
				continue;
			}
			// Time floor: if this payment is older than the window, we're done —
			// everything after it is older too (Mollie sorts newest first).
			if ( ! empty( $p['createdAt'] ) && strtotime( $p['createdAt'] ) < $since_ts ) {
				$stop = true;
				break;
			}
			// Cheap filters first — skip anything obviously not ours.
			if ( ( $p['status'] ?? '' ) !== 'paid' ) {
				continue;
			}
			if ( ( $p['sequenceType'] ?? '' ) !== 'oneoff' ) {
				continue;
			}
			if ( ! in_array( $p['metadata']['source'] ?? '', fcd_sync_allowed_sources(), true ) ) {
				continue;
			}

			$stats['processed']++;
			$t0 = microtime( true );
			$result = fcd_sync_process_payment( $p['id'] );
			fcd_sync_log_event( 'cron', $p['id'], $result, $t0 );

			if ( true === $result ) {
				$stats['created']++;
			} elseif ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();
				if ( in_array( $code, array( 'not_paid', 'not_oneoff', 'not_ours' ), true ) ) {
					$stats['skipped']++;
				} else {
					$stats['errors']++;
				}
			}
		}

		$next_url = $body['_links']['next']['href'] ?? '';
	}

	update_option( 'fcd_sync_last_poll', array(
		'time'  => time(),
		'ok'    => $ok,
		'stats' => $stats,
		'count' => $total_listed,
		'pages' => $pages,
	), false );

	return $stats;
}

/* -------------------------------------------------------------------------
 * Admin page — shows env status so misconfigurations are obvious.
 * ---------------------------------------------------------------------- */
add_action( 'admin_menu', function () {
	add_options_page( 'Donation Sync', 'Donation Sync', 'manage_options', 'fcd-sync', 'fcd_sync_render_admin' );
} );

function fcd_sync_render_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle "Run poll now" and "Reschedule cron".
	if ( isset( $_POST['fcd_sync_action'] ) && check_admin_referer( 'fcd_sync_admin' ) ) {
		$action = sanitize_text_field( wp_unslash( $_POST['fcd_sync_action'] ) );
		if ( 'poll_now' === $action ) {
			$stats = fcd_sync_cron_poll();
			echo '<div class="notice notice-success"><p>Poll ran. ' . esc_html(
				sprintf( 'Processed %d · created %d · skipped %d · errors %d',
					$stats['processed'], $stats['created'], $stats['skipped'], $stats['errors'] )
			) . '</p></div>';
		} elseif ( 'reschedule' === $action ) {
			$ts = wp_next_scheduled( FCD_SYNC_CRON_HOOK );
			if ( $ts ) {
				wp_unschedule_event( $ts, FCD_SYNC_CRON_HOOK );
			}
			wp_schedule_event( time() + 30, 'fcd_one_minute', FCD_SYNC_CRON_HOOK );
			echo '<div class="notice notice-success"><p>Cron rescheduled.</p></div>';
		}
	}

	list( $key, $key_source ) = fcd_sync_mollie_key_with_source();
	$key_mode  = $key ? ( strpos( $key, 'live_' ) === 0 ? 'LIVE' : 'TEST' ) : '';
	$key_mask  = $key ? substr( $key, 0, 8 ) . '...' . substr( $key, -4 ) : '(none)';
	$webhook   = fcd_sync_webhook_url();
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$wh_host   = wp_parse_url( $webhook, PHP_URL_HOST );
	$wh_ok     = ( $site_host === $wh_host );
	$next_run  = wp_next_scheduled( FCD_SYNC_CRON_HOOK );
	$last_poll = get_option( 'fcd_sync_last_poll', array() );

	?>
	<div class="wrap">
		<h1>One-Time Donation Sync</h1>
		<p>Syncs paid one-time direct donations from Mollie into FluentCart. Runs on <strong>webhook</strong> (instant when reachable), <strong>donor return</strong> (syncs their payment when they land back on the site), and <strong>1-min cron</strong> (safety net).</p>

		<h2>Environment</h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<tr>
					<th style="width:220px">FluentCart available</th>
					<td><?php echo fcd_sync_fc_available() ? '✅ yes' : '❌ NO — activate FluentCart Pro'; ?></td>
				</tr>
				<tr>
					<th>Mollie API key</th>
					<td>
						<?php if ( '' === $key ) : ?>
							❌ NOT FOUND — configure Mollie in FluentCart settings.
						<?php else : ?>
							<code><?php echo esc_html( $key_mask ); ?></code>
							&nbsp;<strong>mode:</strong> <?php echo esc_html( $key_mode ); ?>
							&nbsp;<strong>source:</strong> <?php echo esc_html( $key_source ); ?>
							<?php if ( 'constant' === $key_source ) : ?>
								<br><span style="color:#c55a11">⚠ Using FCD_MOLLIE_API_KEY constant (wp-config). Prefer FluentCart's own key so each environment has the right one.</span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>Site URL</th>
					<td><code><?php echo esc_html( home_url() ); ?></code></td>
				</tr>
				<tr>
					<th>Webhook URL sent to Mollie</th>
					<td>
						<code style="word-break:break-all"><?php echo esc_html( $webhook ); ?></code>
						<?php if ( ! $wh_ok ) : ?>
							<br><span style="color:#c62828">⚠ Host <code><?php echo esc_html( $wh_host ); ?></code> does not match site host <code><?php echo esc_html( $site_host ); ?></code>. Most likely the <code>FCT_MOLLIE_WEBHOOK_BASE</code> constant is set in wp-config from a dev copy. Remove it on this environment.</span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<h2>Cron poller</h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<tr>
					<th style="width:220px">Next scheduled run</th>
					<td><?php echo $next_run ? esc_html( gmdate( 'Y-m-d H:i:s', $next_run ) . ' UTC (' . human_time_diff( time(), $next_run ) . ')' ) : '❌ not scheduled'; ?></td>
				</tr>
				<tr>
					<th>Last poll</th>
					<td>
						<?php if ( empty( $last_poll ) ) : ?>
							(never)
						<?php else :
							$s = $last_poll['stats'] ?? array();
							echo esc_html( gmdate( 'Y-m-d H:i:s', $last_poll['time'] ) . ' UTC — '
								. sprintf( 'ok=%s, processed=%d, created=%d, skipped=%d, errors=%d, listed=%d',
									! empty( $last_poll['ok'] ) ? 'yes' : 'NO',
									(int) ( $s['processed'] ?? 0 ),
									(int) ( $s['created'] ?? 0 ),
									(int) ( $s['skipped'] ?? 0 ),
									(int) ( $s['errors'] ?? 0 ),
									(int) ( $last_poll['count'] ?? 0 )
								)
							);
						endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<form method="post" style="margin-top:16px;display:flex;gap:10px">
			<?php wp_nonce_field( 'fcd_sync_admin' ); ?>
			<button class="button button-primary" name="fcd_sync_action" value="poll_now">Run poll now</button>
			<button class="button" name="fcd_sync_action" value="reschedule">Reschedule cron</button>
		</form>

		<h2 style="margin-top:24px">Recent sync events <small style="font-weight:normal;color:#888">(last 25)</small></h2>
		<?php
		$events = get_option( 'fcd_sync_events', array() );
		if ( ! is_array( $events ) || ! $events ) {
			echo '<p style="color:#888">No events yet.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:900px;font-size:13px"><thead><tr>'
				. '<th>Time (UTC)</th><th>Trigger</th><th>Payment</th><th>Outcome</th><th>ms</th><th>Msg</th>'
				. '</tr></thead><tbody>';
			foreach ( array_slice( $events, 0, 25 ) as $e ) {
				$is_err = ! in_array( $e['outcome'], array( 'ok', 'not_paid', 'not_oneoff', 'not_ours' ), true );
				$color  = $is_err ? '#c62828' : ( 'ok' === $e['outcome'] ? '#2e7d32' : '#888' );
				echo '<tr>'
					. '<td>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $e['ts'] ) ) . '</td>'
					. '<td>' . esc_html( $e['trigger'] ) . '</td>'
					. '<td><code>' . esc_html( $e['pid'] ) . '</code></td>'
					. '<td style="color:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( $e['outcome'] ) . '</td>'
					. '<td>' . (int) ( $e['ms'] ?? 0 ) . '</td>'
					. '<td style="color:#666">' . esc_html( $e['msg'] ?? '' ) . '</td>'
					. '</tr>';
			}
			echo '</tbody></table>';
		}
		?>

		<h2 style="margin-top:24px">How it decides which payment is a donation</h2>
		<p>Both the webhook and the cron only create an order when the Mollie payment has:</p>
		<ul style="list-style:disc;margin-left:20px">
			<li><code>status = paid</code></li>
			<li><code>sequenceType = oneoff</code> (monthly is handled by FluentCart itself)</li>
			<li><code>metadata.source</code> in: <code>fluentcart-donations-direct</code>, <code>fcd-mollie-pay</code></li>
		</ul>
	</div>
	<?php
}
