<?php
/**
 * Mollie Subscription PATCH – update subscription amount and/or interval via Mollie API.
 *
 * @see https://docs.mollie.com/reference/update-subscription
 * PATCH https://api.mollie.com/v2/customers/{customerId}/subscriptions/{subscriptionId}
 * Body: { "amount": { "currency": "EUR", "value": "50.00" }, "interval": "1 month" }
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fct_Mollie_Subscription_Patch
{
    protected static $api_url = 'https://api.mollie.com/v2/';

    /** FluentCart billing_interval => Mollie interval string */
    protected static $interval_to_mollie = [
        'daily'       => '1 day',
        'weekly'      => '1 week',
        'monthly'     => '1 month',
        'quarterly'   => '3 months',
        'half_yearly' => '6 months',
        'yearly'      => '12 months',
    ];

    /** Mollie interval string => FluentCart billing_interval */
    protected static $mollie_to_interval = [
        '1 day'     => 'daily',
        '1 week'    => 'weekly',
        '1 month'   => 'monthly',
        '3 months'  => 'quarterly',
        '6 months'  => 'half_yearly',
        '12 months' => 'yearly',
    ];

    /**
     * Update subscription amount and/or interval at Mollie and in FluentCart.
     *
     * @param int    $subscription_id FluentCart subscription ID
     * @param float  $new_amount       New amount per billing cycle (e.g. 50.00 for €50)
     * @param string $currency        Optional. If empty, uses subscription currency
     * @param string $new_interval    Optional. FluentCart billing_interval (daily, weekly, monthly, quarterly, half_yearly, yearly)
     * @return array|\WP_Error Updated subscription data or error
     */
    public static function update_subscription_amount($subscription_id, $new_amount, $currency = '', $new_interval = '')
    {
        $subscription = \FluentCart\App\Models\Subscription::with('order')->find($subscription_id);
        if (!$subscription) {
            return new \WP_Error('subscription_not_found', __('Abonnement niet gevonden.', 'fluentcart-mollie-subscription-amount'));
        }

        if ($subscription->current_payment_method !== 'mollie') {
            return new \WP_Error('not_mollie', __('Dit abonnement gebruikt geen Mollie.', 'fluentcart-mollie-subscription-amount'));
        }

        if (empty($subscription->vendor_customer_id) || empty($subscription->vendor_subscription_id)) {
            return new \WP_Error('missing_mollie_ids', __('Mollie klant- of abonnement-ID ontbreekt.', 'fluentcart-mollie-subscription-amount'));
        }

        $currency = $currency ? strtoupper($currency) : $subscription->currency;
        $value_str = self::format_amount_for_mollie($new_amount, $currency);

        $api_key = self::get_mollie_api_key($subscription);
        if (is_wp_error($api_key)) {
            return $api_key;
        }

        $path = 'customers/' . $subscription->vendor_customer_id . '/subscriptions/' . $subscription->vendor_subscription_id;
        $interval_fct = $new_interval ? sanitize_text_field($new_interval) : $subscription->billing_interval;
        $body = [
            'amount'      => [
                'currency' => $currency,
                'value'    => $value_str,
            ],
            'description' => self::build_subscription_description($subscription, $value_str, $currency, $interval_fct),
        ];
        if (!empty($interval_fct) && isset(self::$interval_to_mollie[ $interval_fct ])) {
            $body['interval'] = self::$interval_to_mollie[ $interval_fct ];
        }

        $response = self::mollie_patch($path, $body, $api_key);
        if (is_wp_error($response)) {
            return $response;
        }

        $new_total_cents = self::amount_to_cents($new_amount, $currency);
        $config = $subscription->config;
        if (!is_array($config)) {
            $config = [];
        }
        $config['current_renewal_amount'] = $new_total_cents;

        $subscription->recurring_total = $new_total_cents;
        $subscription->config = $config;
        if (!empty($interval_fct) && isset(self::$interval_to_mollie[ $interval_fct ])) {
            $subscription->billing_interval = $interval_fct;
            $subscription->item_name = self::normalize_item_name_interval(
                self::deduplicate_item_name($subscription->item_name ?: ''),
                $interval_fct
            );
            if (empty($subscription->item_name)) {
                $subscription->item_name = __('Abonnement', 'fluentcart-mollie-subscription-amount');
            }
        }
        $subscription->save();

        $interval_display = isset(self::$interval_to_mollie[ $subscription->billing_interval ])
            ? self::$interval_to_mollie[ $subscription->billing_interval ]
            : '1 month';

        return [
            'id'                => $subscription->id,
            'recurring_total'   => $subscription->recurring_total,
            'currency'          => $currency,
            'amount_display'    => $value_str . ' ' . $currency,
            'billing_interval'  => $subscription->billing_interval,
            'interval_display'  => $interval_display,
        ];
    }

    /**
     * Get subscription info for Mollie amount update (current amount, currency, etc.)
     *
     * @param int $subscription_id FluentCart subscription ID
     * @return array|\WP_Error
     */
    public static function get_subscription_mollie_info($subscription_id)
    {
        $subscription = \FluentCart\App\Models\Subscription::find($subscription_id);
        if (!$subscription) {
            return new \WP_Error('subscription_not_found', __('Abonnement niet gevonden.', 'fluentcart-mollie-subscription-amount'));
        }

        if ($subscription->current_payment_method !== 'mollie') {
            return new \WP_Error('not_mollie', __('Dit abonnement gebruikt geen Mollie.', 'fluentcart-mollie-subscription-amount'));
        }

        $current = $subscription->getCurrentRenewalAmount();
        $cents   = (int) $current;
        $currency = $subscription->currency;
        $amount_display = self::format_amount_for_mollie($cents / 100.0, $currency);

        $interval_display_map = [
            'daily'       => '1 dag',
            'weekly'      => '1 week',
            'monthly'     => '1 maand',
            'quarterly'   => '3 maanden',
            'half_yearly' => '6 maanden',
            'yearly'      => '12 maanden',
        ];
        $billing_interval = $subscription->billing_interval ?? 'monthly';
        $current_interval_display = isset($interval_display_map[ $billing_interval ]) ? $interval_display_map[ $billing_interval ] : '1 maand';

        return [
            'success'        => true,
            'subscription_id' => $subscription->id,
            'currency'       => $currency,
            'current_amount' => round($cents / 100, 2),
            'current_display' => $amount_display . ' ' . $currency,
            'vendor_subscription_id' => $subscription->vendor_subscription_id,
            'vendor_customer_id'     => $subscription->vendor_customer_id,
            'billing_interval'       => $billing_interval,
            'interval_display'       => $current_interval_display,
            'interval_options'       => array_keys(self::$interval_to_mollie),
        ];
    }

    /**
     * Get Mollie API key from FluentCart settings (same as FluentCart Pro Mollie gateway).
     *
     * @param \FluentCart\App\Models\Subscription $subscription
     * @return string|\WP_Error
     */
    protected static function get_mollie_api_key($subscription)
    {
        $settings = fluent_cart_get_option('fluent_cart_payment_settings_mollie', []);
        if (is_string($settings)) {
            $settings = maybe_unserialize($settings);
        }
        if (!is_array($settings) || empty($settings)) {
            return new \WP_Error('mollie_settings_missing', __('Mollie-instellingen niet gevonden. Controleer FluentCart Pro Mollie-configuratie.', 'fluentcart-mollie-subscription-amount'));
        }

        $mode = 'test';
        if ($subscription->order && isset($subscription->order->mode)) {
            $mode = $subscription->order->mode === 'live' ? 'live' : 'test';
        } else {
            $store_mode = class_exists(\FluentCart\Api\StoreSettings::class)
                ? (new \FluentCart\Api\StoreSettings())->get('order_mode')
                : 'test';
            $mode = $store_mode === 'live' ? 'live' : 'test';
        }

        $key_name = $mode === 'live' ? 'live_api_key' : 'test_api_key';
        $raw_key  = isset($settings[ $key_name ]) ? $settings[ $key_name ] : '';
        if (empty($raw_key)) {
            return new \WP_Error('mollie_api_key_missing', __('Mollie API-sleutel niet geconfigureerd voor deze modus.', 'fluentcart-mollie-subscription-amount'));
        }

        if (class_exists(\FluentCart\App\Helpers\Helper::class)) {
            $api_key = \FluentCart\App\Helpers\Helper::decryptKey($raw_key);
        } else {
            $api_key = $raw_key;
        }

        if (empty($api_key) || $api_key === false) {
            return new \WP_Error('mollie_api_key_invalid', __('Mollie API-sleutel kon niet worden gebruikt.', 'fluentcart-mollie-subscription-amount'));
        }

        return $api_key;
    }

    /**
     * Format amount for Mollie API (string with 2 decimals for most currencies).
     *
     * @param float  $amount   Amount in "units" (e.g. 50.00 for €50)
     * @param string $currency Currency code
     * @return string
     */
    protected static function format_amount_for_mollie($amount, $currency)
    {
        $zero_decimal = [ 'JPY', 'KRW', 'VND', 'CLP', 'TWD' ];
        if (in_array(strtoupper($currency), $zero_decimal, true)) {
            return number_format(round($amount), 0, '.', '');
        }
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Convert amount to cents (FluentCart recurring_total is in cents).
     *
     * @param float  $amount   Amount in units
     * @param string $currency Currency code
     * @return int
     */
    protected static function amount_to_cents($amount, $currency)
    {
        $zero_decimal = [ 'JPY', 'KRW', 'VND', 'CLP', 'TWD' ];
        if (in_array(strtoupper($currency), $zero_decimal, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }

    /**
     * Build subscription description for Mollie (zelfde formaat als FluentCart Pro).
     * Zodat "Omschrijving" in het Mollie-dashboard klopt met het actuele bedrag en cyclus.
     * De productnaam wordt aangepast zodat interval-tekst (bijv. "maandelijks") overeenkomt met de gekozen cyclus.
     *
     * @param \FluentCart\App\Models\Subscription $subscription
     * @param string $amount_formatted Bedrag geformatteerd voor Mollie (bijv. "55.00")
     * @param string $currency
     * @param string $interval_fct    Optional. FluentCart billing_interval (voor beschrijving)
     * @return string
     */
    protected static function build_subscription_description($subscription, $amount_formatted, $currency, $interval_fct = '')
    {
        $key = $interval_fct ? $interval_fct : ( $subscription->billing_interval ?? '' );
        $interval_elke = self::get_interval_elke_label($key);
        $item_name = $subscription->item_name ?: __('Abonnement', 'fluentcart-mollie-subscription-amount');
        $item_name = self::deduplicate_item_name($item_name);
        $item_name = self::normalize_item_name_interval($item_name, $key);
        $description = $item_name . ' - ' . $amount_formatted . ' ' . $interval_elke;

        $config = is_array($subscription->config) ? $subscription->config : [];
        $initial_trial = (int) ( $subscription->trial_days ?? 0 );
        if ($initial_trial > 0 && ( isset($config['is_trial_days_simulated']) ? $config['is_trial_days_simulated'] : '' ) !== 'yes') {
            $description .= ' ( ' . __('na', 'fluentcart-mollie-subscription-amount') . ' ' . $initial_trial . ' ' . __('dag', 'fluentcart-mollie-subscription-amount') . ' ' . __('proefperiode', 'fluentcart-mollie-subscription-amount') . ' )';
        }
        $signup_fee = (int) ( $subscription->signup_fee ?? 0 );
        if ($signup_fee > 0) {
            $fee_str = self::format_amount_for_mollie($signup_fee / 100, $currency);
            $description .= ' ' . __('met', 'fluentcart-mollie-subscription-amount') . ' ' . $fee_str . ' ' . __('inschrijfgeld', 'fluentcart-mollie-subscription-amount');
        }

        return apply_filters('fluentcart_mollie_amount_subscription_description', $description, $subscription, $amount_formatted, $currency);
    }

    /**
     * Label voor "elke X" in omschrijving: correct Nederlands (elke week, elke maand, geen "elke 1 week").
     *
     * @param string $interval_fct FluentCart billing_interval
     * @return string
     */
    protected static function get_interval_elke_label($interval_fct)
    {
        $labels = [
            'daily'       => 'elke dag',
            'weekly'      => 'elke week',
            'monthly'     => 'elke maand',
            'quarterly'   => 'elke 3 maanden',
            'half_yearly' => 'elke 6 maanden',
            'yearly'      => 'elk jaar',
        ];
        return isset($labels[ $interval_fct ]) ? $labels[ $interval_fct ] : 'elke maand';
    }

    /**
     * Pas interval-tekst in de productnaam aan op de gekozen betalingscyclus.
     * Bijv. "Donatie (maandelijks)" met interval quarterly → "Donatie (per 3 maanden)".
     *
     * @param string $item_name  Productnaam (kan oude interval bevatten)
     * @param string $interval_fct FluentCart billing_interval (daily, weekly, monthly, etc.)
     * @return string
     */
    protected static function normalize_item_name_interval($item_name, $interval_fct)
    {
        $interval_name_labels = [
            'daily'       => '(dagelijks)',
            'weekly'      => '(wekelijks)',
            'monthly'     => '(maandelijks)',
            'quarterly'   => '(per 3 maanden)',
            'half_yearly' => '(per 6 maanden)',
            'yearly'      => '(jaarlijks)',
        ];
        $replacement = isset($interval_name_labels[ $interval_fct ]) ? $interval_name_labels[ $interval_fct ] : '(maandelijks)';

        $old_phrases = [
            '(maandelijks)', '(wekelijks)', '(jaarlijks)', '(dagelijks)',
            '(per 3 maanden)', '(per 6 maanden)', '(elk kwartaal)', '(kwartaal)',
            '(per maand)', '(per week)', '(per jaar)', '(per dag)',
            '(elke maand)', '(elke week)', '(elke 3 maanden)', '(elke 6 maanden)',
            '(maand)', '(week)', '(jaar)',
        ];
        $item_name = str_ireplace($old_phrases, array_fill(0, count($old_phrases), $replacement), $item_name);

        return $item_name;
    }

    /**
     * Verwijder dubbele productnaam (bijv. "Donatie - Donatie (maandelijks)" → "Donatie (maandelijks)").
     *
     * @param string $item_name
     * @return string
     */
    protected static function deduplicate_item_name($item_name)
    {
        if (strpos($item_name, ' - ') === false) {
            return $item_name;
        }
        $parts = explode(' - ', $item_name, 2);
        if (count($parts) !== 2) {
            return $item_name;
        }
        $first = trim($parts[0]);
        $rest  = trim($parts[1]);
        if ($first !== '' && $rest !== '' && strpos($rest, $first) === 0) {
            return $rest;
        }
        return $item_name;
    }

    /**
     * Perform Mollie API PATCH request.
     *
     * @param string $path    API path (e.g. customers/cst_xxx/subscriptions/sub_xxx)
     * @param array  $body    JSON body
     * @param string $api_key Bearer token
     * @return array|\WP_Error
     */
    protected static function mollie_patch($path, $body, $api_key)
    {
        $url  = self::$api_url . $path;
        $args = [
            'method'  => 'PATCH',
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ];

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 300) {
            $message = isset($data['detail']) ? $data['detail'] : (isset($data['title']) ? $data['title'] : __('Mollie API-fout', 'fluentcart-mollie-subscription-amount'));
            return new \WP_Error('mollie_api_error', $message, $data);
        }

        return $data;
    }
}
