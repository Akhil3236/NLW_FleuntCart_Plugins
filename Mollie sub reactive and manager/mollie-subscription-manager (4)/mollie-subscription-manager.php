<?php
/**
 * Plugin Name: Mollie Subscription Manager
 * Description: All-in-one Mollie subscription tool for FluentCart. Reactivates cancelled subscriptions
 *              using an existing mandate (no customer payment needed) AND updates the recurring amount /
 *              billing interval on active subscriptions. Includes auto-reactivation via IPN + cron.
 * Version:     1.0.0
 * Author:      NextLevelWeb
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MSM_VERSION',    '1.0.0' );
define( 'MSM_FILE',       __FILE__ );
define( 'MSM_DIR',        plugin_dir_path( __FILE__ ) );
define( 'MSM_URL',        plugin_dir_url( __FILE__ ) );

// ─── Boot after plugins are loaded ───────────────────────────────────────────
add_action( 'plugins_loaded', 'msm_boot', 20 );

function msm_boot() {
    // Require FluentCart core + Pro (Subscription model)
    if ( ! function_exists( 'fluent_cart_get_option' )
        || ! class_exists( \FluentCart\App\Models\Subscription::class ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-warning"><p>'
                . esc_html( 'Mollie Subscription Manager requires FluentCart and FluentCart Pro to be active.' )
                . '</p></div>';
        } );
        return;
    }

    // ── Admin ──
    add_action( 'admin_menu',            'msm_register_menu' );
    add_action( 'admin_init',            'msm_register_settings' );
    add_action( 'admin_enqueue_scripts', 'msm_enqueue_admin_assets' );
    add_action( 'admin_footer',          'msm_inject_fluentcart_ui' );

    // ── REST API (amount update) ──
    add_action( 'rest_api_init', 'msm_register_rest_routes' );

    // ── AJAX (reactivation) ──
    add_action( 'wp_ajax_msm_get_sub_info',    'msm_ajax_get_sub_info' );
    add_action( 'wp_ajax_msm_reactivate',      'msm_ajax_reactivate' );
    add_action( 'wp_ajax_msm_clear_logs',      'msm_ajax_clear_logs' );

    // ── Auto-reactivation: cover every hook FluentCart might fire ──
    // We attach to many event names so ANY of them firing triggers a reactivation
    // attempt. msm_handle_event() is idempotent — it bails out if already active.
    $payment_hooks = [
        'fluent_cart/payment_success',
        'fluent_cart/subscription_payment_success',
        'fluent_cart/order_payment_success',
        'fluent_cart/order/payment_received',
        'fluent_cart/order_status_changed',
        'fluent_cart/order/status_changed',
        'fluent_cart/subscription_renewed',
        'fluent_cart/subscription/renewed',
        'fluent_cart/subscription/payment_received',
        'fluent_cart/payment/successful',
    ];
    foreach ( $payment_hooks as $h ) {
        add_action( $h, 'msm_handle_event', 10, 3 );
    }
    // Mollie-specific webhook hook
    add_action( 'fluent_cart/mollie_ipn', 'msm_on_mollie_ipn', 10, 2 );

    // ── Cron sweep — run hourly so a customer who pays is reactivated quickly ──
    if ( ! wp_next_scheduled( 'msm_cron_sweep' ) ) {
        wp_schedule_event( time() + 60, 'hourly', 'msm_cron_sweep' );
    }
    add_action( 'msm_cron_sweep', 'msm_cron_sweep_handler' );

    // ── Admin-only: customer portal injection is intentionally REMOVED.
    //    Customers must NOT be able to update their own amount or reactivate
    //    their own subscription — these are admin-only operations.
}

// ─────────────────────────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/** Get option with default */
function msm_opt( $key, $default = '' ) {
    return get_option( $key, $default );
}

/** Write to the plugin log (max 150 entries) */
function msm_log( string $msg ): void {
    $logs   = get_option( 'msm_logs', [] );
    $logs[] = '[' . current_time( 'Y-m-d H:i:s' ) . '] ' . $msg;
    if ( count( $logs ) > 150 ) {
        $logs = array_slice( $logs, -150 );
    }
    update_option( 'msm_logs', $logs, false );
}

/**
 * Get Mollie API key from FluentCart Pro settings.
 * Falls back to manually entered key in MSM settings.
 *
 * @param \FluentCart\App\Models\Subscription|null $subscription
 * @return string|\WP_Error
 */
function msm_get_api_key( $subscription = null ) {
    // Try FluentCart's stored Mollie key first
    $settings = fluent_cart_get_option( 'fluent_cart_payment_settings_mollie', [] );
    if ( is_string( $settings ) ) {
        $settings = maybe_unserialize( $settings );
    }

    if ( is_array( $settings ) && ! empty( $settings ) ) {
        $mode = 'test';
        if ( $subscription && $subscription->order && isset( $subscription->order->mode ) ) {
            $mode = $subscription->order->mode === 'live' ? 'live' : 'test';
        } else {
            $store_mode = class_exists( \FluentCart\Api\StoreSettings::class )
                ? ( new \FluentCart\Api\StoreSettings() )->get( 'order_mode' )
                : 'test';
            $mode = $store_mode === 'live' ? 'live' : 'test';
        }
        $raw = $settings[ $mode === 'live' ? 'live_api_key' : 'test_api_key' ] ?? '';
        if ( $raw ) {
            $key = class_exists( \FluentCart\App\Helpers\Helper::class )
                ? \FluentCart\App\Helpers\Helper::decryptKey( $raw )
                : $raw;
            if ( $key ) return $key;
        }
    }

    // Fallback to manual key
    $manual = msm_opt( 'msm_mollie_api_key' );
    if ( $manual ) return $manual;

    return new \WP_Error( 'no_api_key', 'Mollie API key not found. Check FluentCart Pro Mollie settings or enter it manually in Subscription Manager → Settings.' );
}

/**
 * Generic Mollie API call.
 *
 * @param string     $method   GET | POST | PATCH
 * @param string     $endpoint e.g. customers/cst_xxx/mandates
 * @param array|null $body
 * @param mixed      $subscription  For API key resolution
 * @return array|\WP_Error
 */
function msm_mollie( string $method, string $endpoint, array $body = null, $subscription = null ) {
    $api_key = msm_get_api_key( $subscription );
    if ( is_wp_error( $api_key ) ) return $api_key;

    $args = [
        'method'  => strtoupper( $method ),
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 20,
    ];
    if ( $body !== null ) {
        $args['body'] = wp_json_encode( $body );
    }

    $response = wp_remote_request( 'https://api.mollie.com/v2/' . $endpoint, $args );
    if ( is_wp_error( $response ) ) return $response;

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code >= 300 ) {
        $msg = $data['detail'] ?? $data['title'] ?? 'Mollie API error';
        return new \WP_Error( 'mollie_error', $msg, $data );
    }
    return $data;
}

/**
 * Get first valid Mollie mandate for a customer.
 *
 * @param string $mollie_customer_id
 * @param mixed  $subscription
 * @return string|\WP_Error  mandate ID or WP_Error
 */
function msm_get_valid_mandate( string $mollie_customer_id, $subscription = null ) {
    $res = msm_mollie( 'GET', "customers/{$mollie_customer_id}/mandates", null, $subscription );
    if ( is_wp_error( $res ) ) return $res;

    $mandates = $res['_embedded']['mandates'] ?? [];
    $valid    = array_values( array_filter( $mandates, fn( $m ) => ( $m['status'] ?? '' ) === 'valid' ) );

    if ( empty( $valid ) ) {
        return new \WP_Error( 'no_mandate', 'No valid Mollie mandate found for this customer. The customer must complete a payment first.' );
    }

    // Prefer mandate stored in subscription's vendor_response
    if ( $subscription ) {
        $vr = is_array( $subscription->config ) ? $subscription->config : [];
        $preferred = $vr['mandateId'] ?? null;
        if ( $preferred ) {
            foreach ( $valid as $m ) {
                if ( $m['id'] === $preferred ) return $m['id'];
            }
        }
    }

    return $valid[0]['id'];
}

// ─────────────────────────────────────────────────────────────────────────────
//  REACTIVATION CORE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Reactivate a cancelled Mollie subscription.
 * Finds the FluentCart subscription by ID, gets the customer's valid mandate,
 * creates a new Mollie subscription, and updates FluentCart + logs.
 *
 * @param int    $fc_subscription_id  FluentCart subscription ID
 * @param string $start_date          YYYY-MM-DD  (defaults to today)
 * @param float  $amount_override     Optional override; if null, reads from subscription
 * @param string $description_suffix
 * @return array|\WP_Error  New Mollie subscription data or error
 */
function msm_reactivate_subscription( int $fc_subscription_id, string $start_date = '', float $amount_override = null, string $description_suffix = '' ) {
    $sub = \FluentCart\App\Models\Subscription::with( 'order' )->find( $fc_subscription_id );
    if ( ! $sub ) {
        return new \WP_Error( 'not_found', "FluentCart subscription #{$fc_subscription_id} not found." );
    }

    if ( $sub->current_payment_method !== 'mollie' ) {
        return new \WP_Error( 'not_mollie', "Subscription #{$fc_subscription_id} does not use Mollie." );
    }

    $mollie_customer_id = $sub->vendor_customer_id ?? '';
    if ( ! $mollie_customer_id ) {
        return new \WP_Error( 'no_customer', "Subscription #{$fc_subscription_id} has no Mollie customer ID." );
    }

    // Already active? Skip.
    if ( in_array( $sub->status ?? '', [ 'active', 'pending' ], true ) ) {
        return new \WP_Error( 'already_active', "Subscription #{$fc_subscription_id} is already {$sub->status}." );
    }

    // Get valid mandate
    $mandate_id = msm_get_valid_mandate( $mollie_customer_id, $sub );
    if ( is_wp_error( $mandate_id ) ) return $mandate_id;

    // Resolve amount
    $cents    = (int) ( $sub->recurring_total ?? 0 );
    $amount   = $amount_override ?? ( $cents > 0 ? round( $cents / 100, 2 ) : null );
    $currency = strtoupper( $sub->currency ?: 'EUR' );
    if ( ! $amount || $amount < 0.01 ) {
        return new \WP_Error( 'no_amount', "Could not determine subscription amount for #{$fc_subscription_id}." );
    }

    // Resolve interval
    $interval_map = [
        'daily'       => '1 day',
        'weekly'      => '1 week',
        'monthly'     => '1 month',
        'quarterly'   => '3 months',
        'half_yearly' => '6 months',
        'yearly'      => '12 months',
    ];
    $mollie_interval = $interval_map[ $sub->billing_interval ?? 'monthly' ] ?? '1 month';

    // Start date
    if ( ! $start_date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
        $start_date = date( 'Y-m-d' );
    }

    // Description
    $item    = $sub->item_name ?: 'Subscription';
    $tag     = $description_suffix ?: '[R-' . date( 'Ymd-His' ) . ']';
    $desc    = "{$item} - " . number_format( $amount, 2, '.', '' ) . " {$currency} {$tag}";

    $payload = [
        'amount'      => [ 'currency' => $currency, 'value' => number_format( $amount, 2, '.', '' ) ],
        'interval'    => $mollie_interval,
        'startDate'   => $start_date,
        'mandateId'   => $mandate_id,
        'description' => $desc,
    ];

    $new_sub = msm_mollie( 'POST', "customers/{$mollie_customer_id}/subscriptions", $payload, $sub );
    if ( is_wp_error( $new_sub ) ) return $new_sub;

    $new_sub_id = $new_sub['id'] ?? null;
    if ( ! $new_sub_id ) {
        return new \WP_Error( 'no_sub_id', 'Mollie returned no subscription ID.' );
    }

    // Update FluentCart subscription
    $sub->vendor_subscription_id = $new_sub_id;
    $sub->status                  = 'active';
    // Clear cancellation fields if they exist on the model
    if ( isset( $sub->cancelled_at ) ) $sub->cancelled_at = null;
    if ( isset( $sub->expire_at ) )    $sub->expire_at    = null;
    $config                            = is_array( $sub->config ) ? $sub->config : [];
    $config['msm_reactivated_at']      = current_time( 'mysql' );
    $config['msm_reactivated_mandate'] = $mandate_id;
    $sub->config                       = $config;
    $sub->save();
    wp_cache_flush();

    msm_log( "Reactivated FC#{$fc_subscription_id} -> Mollie:{$new_sub_id} | mandate:{$mandate_id} | start:{$start_date}" );

    return [
        'fc_subscription_id'  => $fc_subscription_id,
        'new_mollie_sub_id'   => $new_sub_id,
        'mandate_id'          => $mandate_id,
        'status'              => $new_sub['status']         ?? 'active',
        'start_date'          => $new_sub['startDate']      ?? $start_date,
        'next_payment'        => $new_sub['nextPaymentDate'] ?? 'N/A',
        'amount'              => number_format( $amount, 2, '.', '' ) . ' ' . $currency,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

/** Pre-fetch subscription info for the modal */
function msm_ajax_get_sub_info() {
    check_ajax_referer( 'msm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

    $fc_id = intval( $_POST['fc_id'] ?? 0 );
    if ( ! $fc_id ) wp_send_json_error( 'Missing subscription ID.' );

    $sub = \FluentCart\App\Models\Subscription::find( $fc_id );
    if ( ! $sub ) wp_send_json_error( "Subscription #{$fc_id} not found." );

    $is_mollie   = $sub->current_payment_method === 'mollie';
    $customer_id = $sub->vendor_customer_id ?? '';
    $sub_id      = $sub->vendor_subscription_id ?? '';
    $status      = $sub->status ?? '';
    $cents       = (int) ( $sub->recurring_total ?? 0 );
    $amount      = $cents > 0 ? round( $cents / 100, 2 ) : '';
    $currency    = strtoupper( $sub->currency ?: 'EUR' );

    // Check mandate
    $has_mandate = false;
    $mandate_id  = '';
    if ( $is_mollie && $customer_id ) {
        $res = msm_mollie( 'GET', "customers/{$customer_id}/mandates", null, $sub );
        if ( ! is_wp_error( $res ) ) {
            $valid = array_values( array_filter(
                $res['_embedded']['mandates'] ?? [],
                fn( $m ) => ( $m['status'] ?? '' ) === 'valid'
            ) );
            $has_mandate = ! empty( $valid );
            if ( $has_mandate ) $mandate_id = $valid[0]['id'];
        }
    }

    wp_send_json_success( [
        'fc_id'          => $fc_id,
        'is_mollie'      => $is_mollie,
        'customer_id'    => $customer_id,
        'sub_id'         => $sub_id,
        'status'         => $status,
        'amount'         => $amount ? number_format( (float)$amount, 2, '.', '' ) : '',
        'currency'       => $currency,
        'has_mandate'    => $has_mandate,
        'mandate_id'     => $mandate_id,
        'can_reactivate' => $has_mandate && ! in_array( $status, [ 'active', 'pending' ], true ),
    ] );
}

/** Manual reactivation triggered from the admin UI */
function msm_ajax_reactivate() {
    check_ajax_referer( 'msm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );

    $fc_id      = intval( $_POST['fc_id']      ?? 0 );
    $start_date = sanitize_text_field( $_POST['start_date'] ?? '' );
    $amount_raw = sanitize_text_field( $_POST['amount']     ?? '' );
    $override   = ( $amount_raw !== '' && is_numeric( $amount_raw ) ) ? (float) $amount_raw : null;

    if ( ! $fc_id ) wp_send_json_error( 'Missing subscription ID.' );

    $result = msm_reactivate_subscription( $fc_id, $start_date, $override );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( $result );
}

/** Clear the plugin log */
function msm_ajax_clear_logs() {
    check_ajax_referer( 'msm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.' );
    delete_option( 'msm_logs' );
    wp_send_json_success( 'Logs cleared.' );
}

// ─────────────────────────────────────────────────────────────────────────────
//  AUTO-REACTIVATION HOOKS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Universal event handler for ANY FluentCart payment-related hook.
 * Strategy: extract whatever ID we can (sub, order, customer) from any of the
 * args, then sweep that customer's cancelled Mollie subscriptions and try to
 * reactivate each one with a valid mandate.
 *
 * This makes us hook-name agnostic — if FluentCart adds/renames hooks across
 * versions we still catch the event.
 *
 * @param mixed $a First hook arg (usually order, payment, or subscription)
 * @param mixed $b Second hook arg (varies)
 * @param mixed $c Third hook arg (varies)
 */
function msm_handle_event( $a = null, $b = null, $c = null ) {
    static $running = false;
    if ( $running ) return;          // prevent recursion
    $running = true;

    try {
        $candidate_ids = [];
        $customer_ids  = [];

        foreach ( [ $a, $b, $c ] as $arg ) {
            if ( ! is_object( $arg ) ) continue;
            // Subscription-like
            if ( isset( $arg->billing_interval ) || isset( $arg->vendor_subscription_id ) ) {
                if ( ! empty( $arg->id ) )                  $candidate_ids[] = (int) $arg->id;
                if ( ! empty( $arg->vendor_customer_id ) )  $customer_ids[]  = $arg->vendor_customer_id;
                if ( ! empty( $arg->customer_id ) )         $customer_ids[]  = (int) $arg->customer_id;
            }
            // Order-like
            elseif ( isset( $arg->status ) || isset( $arg->order_status ) ) {
                if ( ! empty( $arg->subscription_id ) )     $candidate_ids[] = (int) $arg->subscription_id;
                if ( ! empty( $arg->customer_id ) )         $customer_ids[]  = (int) $arg->customer_id;
            }
            // Payment-like
            elseif ( isset( $arg->payment_method ) || isset( $arg->payment_total ) ) {
                if ( ! empty( $arg->subscription_id ) )     $candidate_ids[] = (int) $arg->subscription_id;
                if ( ! empty( $arg->customer_id ) )         $customer_ids[]  = (int) $arg->customer_id;
            }
        }

        $candidate_ids = array_unique( array_filter( $candidate_ids ) );
        $customer_ids  = array_unique( array_filter( $customer_ids ) );

        if ( empty( $candidate_ids ) && empty( $customer_ids ) ) {
            $running = false;
            return;
        }

        // 1. Try direct subscription ID matches
        foreach ( $candidate_ids as $fc_id ) {
            msm_try_reactivate_silent( (int) $fc_id );
        }

        // 2. Sweep cancelled Mollie subs for any matched customer
        foreach ( $customer_ids as $cust ) {
            $is_mollie_id = is_string( $cust ) && strpos( $cust, 'cst_' ) === 0;
            $query = \FluentCart\App\Models\Subscription::where( 'status', 'cancelled' )
                ->where( 'current_payment_method', 'mollie' );
            if ( $is_mollie_id ) {
                $query->where( 'vendor_customer_id', $cust );
            } else {
                $query->where( 'customer_id', $cust );
            }
            $subs = $query->orderBy( 'id', 'desc' )->limit( 5 )->get();
            if ( ! $subs ) continue;
            foreach ( $subs as $s ) {
                msm_try_reactivate_silent( (int) $s->id );
            }
        }
    } catch ( \Throwable $e ) {
        msm_log( "Event handler error: " . $e->getMessage() );
    }

    $running = false;
}

/**
 * Try reactivating a subscription, log result, swallow errors quietly.
 * Designed to be safe for use in hook handlers — never throws.
 */
function msm_try_reactivate_silent( int $fc_id ): void {
    try {
        $sub = \FluentCart\App\Models\Subscription::find( $fc_id );
        if ( ! $sub )                                                        return;
        if ( $sub->current_payment_method !== 'mollie' )                     return;
        if ( in_array( $sub->status ?? '', [ 'active', 'pending' ], true ) ) return;
        if ( empty( $sub->vendor_customer_id ) )                             return;

        $mandate = msm_get_valid_mandate( $sub->vendor_customer_id, $sub );
        if ( is_wp_error( $mandate ) ) return;  // no mandate, can't auto-reactivate

        $result = msm_reactivate_subscription( $fc_id );
        if ( is_wp_error( $result ) ) {
            msm_log( "Auto-reactivate failed for FC#{$fc_id}: " . $result->get_error_message() );
        } else {
            msm_log( "Auto-reactivated FC#{$fc_id} -> {$result['new_mollie_sub_id']}" );
        }
    } catch ( \Throwable $e ) {
        msm_log( "Auto-reactivate exception for FC#{$fc_id}: " . $e->getMessage() );
    }
}

/**
 * Mollie webhook handler — fired when Mollie posts a status update.
 */
function msm_on_mollie_ipn( $payload, $extra = null ) {
    if ( ! is_array( $payload ) ) return;

    $mollie_sub_id  = $payload['subscriptionId'] ?? '';
    $mollie_cust_id = $payload['customerId']     ?? '';
    $status         = $payload['status']         ?? '';

    // Only react to successful paid statuses
    if ( ! in_array( $status, [ 'paid', 'authorized' ], true ) ) return;
    if ( ! $mollie_cust_id ) return;

    // First, try direct match on Mollie sub ID
    if ( $mollie_sub_id ) {
        $direct = \FluentCart\App\Models\Subscription::where( 'vendor_subscription_id', $mollie_sub_id )
            ->orderBy( 'id', 'desc' )->first();
        if ( $direct ) msm_try_reactivate_silent( (int) $direct->id );
    }

    // Then sweep all of this customer's cancelled subs
    $subs = \FluentCart\App\Models\Subscription::where( 'vendor_customer_id', $mollie_cust_id )
        ->where( 'status', 'cancelled' )
        ->orderBy( 'id', 'desc' )
        ->limit( 5 )
        ->get();
    if ( ! $subs ) return;
    foreach ( $subs as $s ) {
        msm_try_reactivate_silent( (int) $s->id );
    }
}

/**
 * Cron: sweep for cancelled Mollie subs that have a valid mandate.
 * Runs hourly. Reactivates any cancelled sub where the customer has a
 * valid mandate (meaning they paid recently or never had it revoked).
 */
function msm_cron_sweep_handler() {
    if ( ! class_exists( \FluentCart\App\Models\Subscription::class ) ) return;

    $cancelled = \FluentCart\App\Models\Subscription::where( 'status', 'cancelled' )
        ->where( 'current_payment_method', 'mollie' )
        ->whereNotNull( 'vendor_customer_id' )
        ->orderBy( 'id', 'desc' )
        ->limit( 30 )
        ->get();

    if ( ! $cancelled || $cancelled->isEmpty() ) return;

    $reactivated = 0;
    foreach ( $cancelled as $sub ) {
        // Skip if we already tried very recently (avoid spam)
        $config = is_array( $sub->config ) ? $sub->config : [];
        $last_try = $config['msm_last_cron_attempt'] ?? 0;
        if ( $last_try && ( time() - (int) $last_try ) < 3600 ) continue;

        // Mark attempt time
        $config['msm_last_cron_attempt'] = time();
        $sub->config = $config;
        $sub->save();

        msm_try_reactivate_silent( (int) $sub->id );
        $reactivated++;
    }
    if ( $reactivated > 0 ) msm_log( "Cron sweep ran on {$reactivated} cancelled subs" );
}

// ─────────────────────────────────────────────────────────────────────────────
//  REST API — AMOUNT UPDATE (from Plugin B)
// ─────────────────────────────────────────────────────────────────────────────

function msm_register_rest_routes() {
    // Admin: update amount
    register_rest_route( 'msm/v1', '/subscriptions/(?P<id>\d+)/amount', [
        'methods'             => \WP_REST_Server::EDITABLE,
        'callback'            => 'msm_rest_update_amount',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
        'args'                => [
            'id'       => [ 'required' => true,  'type' => 'integer',   'sanitize_callback' => 'absint' ],
            'amount'   => [ 'required' => true,  'type' => 'number',    'minimum' => 0.01, 'sanitize_callback' => 'floatval' ],
            'currency' => [ 'required' => false, 'type' => 'string',    'default' => '',   'sanitize_callback' => 'sanitize_text_field' ],
            'interval' => [ 'required' => false, 'type' => 'string',    'default' => '',   'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    // Admin: get amount info
    register_rest_route( 'msm/v1', '/subscriptions/(?P<id>\d+)/mollie-info', [
        'methods'             => \WP_REST_Server::READABLE,
        'callback'            => 'msm_rest_get_mollie_info',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
        'args'                => [ 'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ] ],
    ] );

    // NOTE: Customer-facing REST endpoint intentionally NOT registered.
    // Subscription amount changes and reactivations are admin-only operations.
}

function msm_rest_update_amount( \WP_REST_Request $request ) {
    require_once MSM_DIR . 'includes/class-mollie-subscription-patch.php';
    $body     = $request->get_json_params() ?: [];
    $amount   = isset( $body['amount'] )   ? floatval( $body['amount'] )                   : $request->get_param( 'amount' );
    $currency = isset( $body['currency'] ) ? sanitize_text_field( $body['currency'] )       : $request->get_param( 'currency' );
    $interval = isset( $body['interval'] ) ? sanitize_text_field( $body['interval'] )       : $request->get_param( 'interval' );
    $result   = Fct_Mollie_Subscription_Patch::update_subscription_amount( $request->get_param( 'id' ), $amount, $currency, $interval );
    if ( is_wp_error( $result ) ) {
        return new \WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 400 );
    }
    msm_log( "Amount updated: FC#{$request->get_param('id')} -> {$amount} {$currency}" );
    return new \WP_REST_Response( [ 'success' => true, 'message' => 'Subscription amount updated at Mollie and in FluentCart.', 'subscription' => $result ], 200 );
}

function msm_rest_get_mollie_info( \WP_REST_Request $request ) {
    require_once MSM_DIR . 'includes/class-mollie-subscription-patch.php';
    $info = Fct_Mollie_Subscription_Patch::get_subscription_mollie_info( $request->get_param( 'id' ) );
    if ( is_wp_error( $info ) ) {
        return new \WP_REST_Response( [ 'success' => false, 'message' => $info->get_error_message() ], 404 );
    }
    return new \WP_REST_Response( $info, 200 );
}

// ─────────────────────────────────────────────────────────────────────────────
//  ADMIN MENU & SETTINGS
// ─────────────────────────────────────────────────────────────────────────────

function msm_register_menu() {
    add_menu_page(
        'Subscription Manager',
        'Subscription Manager',
        'manage_options',
        'msm-dashboard',
        'msm_page_dashboard',
        'dashicons-update',
        58
    );
    add_submenu_page( 'msm-dashboard', 'Dashboard',  'Dashboard',  'manage_options', 'msm-dashboard',  'msm_page_dashboard' );
    add_submenu_page( 'msm-dashboard', 'Logs',       'Logs',       'manage_options', 'msm-logs',       'msm_page_logs' );
    add_submenu_page( 'msm-dashboard', 'Settings',   'Settings',   'manage_options', 'msm-settings',   'msm_page_settings' );
}

function msm_register_settings() {
    register_setting( 'msm_settings_group', 'msm_mollie_api_key' );
    register_setting( 'msm_settings_group', 'msm_default_description' );
}

// ─────────────────────────────────────────────────────────────────────────────
//  ASSET ENQUEUE
// ─────────────────────────────────────────────────────────────────────────────

function msm_enqueue_admin_assets( $hook ) {
    $is_msm_page   = strpos( $hook, 'msm-' ) !== false;
    $is_fluentcart = ( strpos( $hook, 'fluent-cart' ) !== false );

    if ( ! $is_msm_page && ! $is_fluentcart ) return;

    wp_enqueue_style( 'msm-admin', MSM_URL . 'assets/css/admin-combined.css', [], MSM_VERSION );
    // Load in HEADER (false) so MSM variable is available before admin_footer fires
    wp_enqueue_script( 'msm-admin', MSM_URL . 'assets/js/admin-combined.js', [ 'jquery' ], MSM_VERSION, false );
    wp_localize_script( 'msm-admin', 'MSM', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'rest_url' => rest_url( 'msm/v1/' ),
        'nonce'    => wp_create_nonce( 'msm_nonce' ),
        'wp_nonce' => wp_create_nonce( 'wp_rest' ),
        'today'    => date( 'Y-m-d' ),
        'def_desc' => msm_opt( 'msm_default_description', 'Monthly Subscription' ),
        'is_fc'    => $is_fluentcart ? '1' : '0',
    ] );
}

// ─────────────────────────────────────────────────────────────────────────────
//  FLUENTCART SPA INJECTION (admin_footer)
// ─────────────────────────────────────────────────────────────────────────────

function msm_inject_fluentcart_ui() {
    // Card is built and injected by admin-combined.js on the FluentCart subscription view.
    // We don't need any preset HTML here — the JS reads MSM.is_fc and handles everything.
    // This stub is kept so the existing add_action hook stays valid; no output.
}


// ─────────────────────────────────────────────────────────────────────────────
//  ADMIN PAGES
// ─────────────────────────────────────────────────────────────────────────────

function msm_page_dashboard() {
    $today = date( 'Y-m-d' );
    ?>
    <div class="wrap msm-wrap">
        <h1>Mollie Subscription Manager</h1>

        <div class="msm-status-bar">
            <div>
                <span class="msm-status-dot msm-status-dot-active"></span>
                <strong>Auto-Reactivation Active</strong>
                <p>When a customer pays again on a cancelled Mollie subscription, the plugin re-creates the subscription automatically. An hourly cron sweep ensures nothing is missed.</p>
            </div>
        </div>

        <div class="msm-card">
            <div class="msm-card-header">Manual Reactivation</div>
            <div class="msm-card-body">
                <p class="msm-card-desc">Reactivate a cancelled Mollie subscription using the customer's existing mandate. No new payment is required from the customer.</p>

                <div class="msm-field">
                    <label for="msm-fc-id">FluentCart Subscription ID</label>
                    <input type="number" id="msm-fc-id" class="msm-input" placeholder="e.g. 10" min="1" />
                    <small class="msm-hint">Found in the URL when viewing a subscription: <code>#/subscriptions/<strong>10</strong>/view</code></small>
                </div>

                <div class="msm-field">
                    <label for="msm-dash-start">Start Date</label>
                    <input type="date" id="msm-dash-start" class="msm-input" value="<?php echo esc_attr($today); ?>" min="<?php echo esc_attr($today); ?>" />
                    <small class="msm-hint">First charge date. This sets the recurring billing cycle.</small>
                </div>

                <div class="msm-field">
                    <label for="msm-dash-amount">Amount <span class="msm-optional">optional</span></label>
                    <input type="text" id="msm-dash-amount" class="msm-input" placeholder="Leave blank to use DB value" />
                    <small class="msm-hint">Leave blank to use the original subscription amount.</small>
                </div>

                <button id="msm-dash-reactivate-btn" class="msm-btn msm-btn-primary">Reactivate Subscription</button>
                <div id="msm-dash-result"></div>
            </div>
        </div>
    </div>
    <?php
}

function msm_page_logs() {
    $logs = array_reverse( get_option( 'msm_logs', [] ) );
    ?>
    <div class="wrap msm-wrap">
        <h1>Activity Log</h1>
        <div class="msm-card">
            <div class="msm-card-body">
                <div class="msm-log-header">
                    <span><?php echo count($logs); ?> entries</span>
                    <button id="msm-clear-logs" class="msm-btn msm-btn-danger msm-btn-sm">Clear Logs</button>
                </div>
                <div class="msm-log-box">
                    <?php if ( empty( $logs ) ) : ?>
                        <p class="msm-log-empty">No log entries yet.</p>
                    <?php else : foreach ( $logs as $l ) : ?>
                        <div class="msm-log-entry"><?php echo esc_html( $l ); ?></div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function msm_page_settings() {
    ?>
    <div class="wrap msm-wrap">
        <h1>Settings</h1>
        <div class="msm-card">
            <div class="msm-card-body">
                <form method="post" action="options.php">
                    <?php settings_fields( 'msm_settings_group' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="msm_mollie_api_key">Mollie API Key (fallback)</label></th>
                            <td>
                                <input type="password" id="msm_mollie_api_key" name="msm_mollie_api_key"
                                    value="<?php echo esc_attr( msm_opt('msm_mollie_api_key') ); ?>" class="regular-text" />
                                <p class="description">
                                    Only needed if FluentCart Pro Mollie settings are not configured.<br>
                                    The plugin reads your key automatically from <strong>FluentCart Pro &rarr; Mollie</strong> settings first.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="msm_default_description">Default subscription description</label></th>
                            <td>
                                <input type="text" id="msm_default_description" name="msm_default_description"
                                    value="<?php echo esc_attr( msm_opt('msm_default_description', 'Monthly Subscription') ); ?>" class="regular-text" />
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
        </div>
    </div>
    <?php
}
