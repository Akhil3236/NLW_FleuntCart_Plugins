=== FluentCart Webhook Retry ===
Contributors: nextlevelweb
Tags: fluentcart, webhooks, retry, integration, debugging
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Adds a one-click Retry button to FluentCart's order page when an integration webhook fails, plus a full log/replay admin screen.

== Description ==

When a FluentCart order's outgoing webhook to an integration service (e.g. an Exact Online / NestJS integration server) fails, you normally have no way to retry it without manually re-creating the order or hand-firing the payload. This plugin:

* **Captures every outgoing webhook** that matches a URL pattern you configure (uses WordPress's built-in `http_api_debug` hook, so it works with FluentCart, WooCommerce, or anything else using `wp_remote_*`).
* **Stores the full request** — URL, method, headers, body, response code, response body, error message — in a dedicated DB table.
* **Adds a Retry button** in the FluentCart order screen next to the red "Webhook failed" banner, and on the Activity timeline rows.
* **Adds a standalone admin page** to browse all captured webhooks, retry them, view full request/response detail, and delete entries.
* **Rate-limits retries** so an admin can't accidentally hammer the receiving service.
* **Auto-purges old log entries** after a configurable number of days.

== Installation ==

1. Upload the `fluentcart-webhook-retry` folder to `/wp-content/plugins/`.
2. Activate via the **Plugins** menu in WordPress.
3. Go to **FluentCart → Webhook Retry · Settings** (or the standalone **Webhook Retry → Settings** menu if FluentCart isn't installed).
4. Enter the URL(s) of your integration service in the **Watched URLs** field (one per line, wildcards supported). Example:
   `https://your-integration.example.com/webhooks/*`
5. Save.

That's it. From now on every webhook to that URL will be captured. Failed ones get a Retry button on the FluentCart order page.

== Frequently Asked Questions ==

= Does it modify FluentCart's code? =

No. The plugin hooks into WordPress core's `http_api_debug` action and injects the Retry button via JavaScript using a `MutationObserver`. FluentCart is untouched.

= What happens during a retry? =

The plugin re-sends the **exact original request** — same URL, same method, same headers, same body. The result (new response code + body) is stored as a child row linked to the original via `parent_log_id`.

= I changed my integration to use HMAC signatures with timestamps. Retries fail. =

Expected. A retry with the original headers will fail signature checks if the receiving server enforces freshness/replay protection. Use the `fcwr/retry_args` filter to regenerate the signature and timestamp at retry time:

`add_filter( 'fcwr/retry_args', function ( $args, $log ) {
    $args['headers']['X-Timestamp'] = (string) time();
    $args['headers']['X-Signature'] = hash_hmac( 'sha256', $args['body'], MY_SECRET );
    return $args;
}, 10, 2 );`

= How do I limit who can retry webhooks? =

Use the `fcwr/required_capability` filter:

`add_filter( 'fcwr/required_capability', fn() => 'manage_woocommerce' );`

== Hooks & Filters ==

**Actions**

* `fcwr/webhook_captured` — fires after a row is inserted. Args: `$log_id`, `$data`.
* `fcwr/retry_complete`   — fires after a retry attempt. Args: `$log_id`, `$new_log_id`, `$succeeded`, `$response_code`.

**Filters**

* `fcwr/required_capability` — gate access. Default: `manage_options`.
* `fcwr/extract_order_id`    — customise order ID extraction from request body. Args: `$order_id|null`, `$body`, `$url`.
* `fcwr/retry_args`          — modify the request args before retry. Args: `$args`, `$log`.

== Changelog ==

= 1.0.0 =
* Initial release.
