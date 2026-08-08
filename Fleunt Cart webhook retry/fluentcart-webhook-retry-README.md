# FluentCart Webhook Retry

**Version:** 1.0.0 · **Author:** NextLevelWeb (Akhil Tuluri) · **License:** GPL-2.0-or-later
**Requires:** WordPress 6.0+, PHP 7.4+. Works with FluentCart but also any plugin that uses the WP HTTP API.

## Brief

When an outgoing webhook from FluentCart to an integration service (e.g. an Exact Online / NestJS server) fails, there's normally no way to resend it. This plugin captures every matching outgoing webhook, stores the full request and response, and gives you a one-click **Retry** button — both on the FluentCart order screen and on a dedicated log/replay admin page — with rate-limiting and auto-cleanup built in.

## How it works

1. **Capture:** It hooks WordPress core's `http_api_debug` action (which fires after every `wp_remote_*` call), so it's plugin-agnostic. Outgoing URLs are matched against the patterns you configure (substring or `*` wildcard), and the full request (URL, method, headers, body) + response (code, body, error) is saved to a `{prefix}_fcwr_webhook_logs` table. By default only failures are stored; successes are optional.
2. **Retry:** Replaying an entry rebuilds the original request from the stored row and re-sends it. The result is logged as a linked child row (so you can see the outcome), the retry counter is bumped, and the retrying user is recorded.
3. **Guardrails:** A soft rate-limit (default 5 retries per 60s per entry) and a hard cap (default 10 total) stop an admin from hammering the receiving service.
4. **UI:** A REST API backs the whole thing (list / view / retry / delete, all permission-checked). Admin screens live under FluentCart (or standalone if FluentCart isn't present). On the order page it injects the Retry button next to the "Webhook failed" banner via a `MutationObserver` — **FluentCart's own code is never modified**.
5. **Housekeeping:** A daily cron purges log rows older than a configurable number of days (default 30; set 0 to keep forever); deactivation and uninstall clean up after themselves.

## Settings (FluentCart → Webhook Retry · Settings)

- **Watched URLs** — newline-separated patterns to capture, e.g. `https://your-integration.example.com/webhooks/*`
- **Log successes** — also store successful webhooks (default off)
- **Max retries / rate-limit window / retries per window** — the guardrails above
- **Auto-purge days** — log retention (0 = never delete)

## Extensibility

Filters `fcwr/extract_order_id` and `fcwr/retry_args`, plus actions `fcwr/webhook_captured` and `fcwr/retry_complete`, let you adapt payload parsing, regenerate headers before a retry, or trigger your own side effects.
