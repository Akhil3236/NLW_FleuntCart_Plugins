# Mollie Subscription Manager

**Version:** 1.0.0 · **Author:** NextLevelWeb · **License:** GPL-2.0+ (implied)
**Requires:** WordPress 5.8+, PHP 7.4+, and **FluentCart + FluentCart Pro** active (Subscription model). All operations are **admin-only**.

## Brief

An all-in-one admin tool for managing Mollie subscriptions in FluentCart. It does two main jobs: **reactivating cancelled subscriptions** using the customer's existing mandate (no new payment needed from the customer), and **updating the recurring amount / billing interval** on active ones. Reactivation can be triggered manually or happen automatically when a customer pays again.

## How it works

1. **API key:** It reads the Mollie key straight from FluentCart Pro's Mollie settings (picking live vs test to match the order's mode), with a manual key in the plugin's own Settings as a fallback.
2. **Manual reactivation:** From the dashboard you enter a FluentCart subscription ID (plus optional start date/amount). The plugin finds a valid Mollie mandate for that customer, creates a fresh Mollie subscription against it, and updates the FluentCart record to `active` — stamping the mandate and timestamp onto the subscription's `config`.
3. **Auto-reactivation:** It listens on a broad set of FluentCart payment/renewal hooks *and* Mollie's IPN, plus an hourly cron sweep. When a payment lands for a customer with cancelled Mollie subs, it silently reactivates them. The handler is idempotent — already-active subscriptions are skipped, and a per-sub cooldown stops repeat cron attempts.
4. **Amount / interval updates:** A REST endpoint (`msm/v1/subscriptions/{id}/amount`) sends a `PATCH` to Mollie updating the recurring amount and/or interval, then mirrors the new values back into FluentCart (recurring total, billing interval, item name).
5. **UI & logging:** Admin pages (Dashboard, Logs, Settings) plus a card injected into FluentCart's subscription view via JS. Every action is written to an in-DB activity log (last 150 entries) that you can view or clear.

## Admin pages (Subscription Manager menu)

- **Dashboard** — status + manual reactivation form (subscription ID, start date, optional amount)
- **Logs** — the last 150 activity entries, with a clear button
- **Settings** — fallback Mollie API key and default subscription description

## Safety note

Customer-facing endpoints are deliberately **not** registered — customers cannot change their own amount or reactivate their own subscription. Reactivation depends on the customer already having a valid Mollie mandate; if none exists, the customer must complete a payment first.
