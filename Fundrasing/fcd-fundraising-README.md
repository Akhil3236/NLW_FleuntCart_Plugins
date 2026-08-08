# FCD Fundraising

**Version:** 2.4.1 · **Author:** NextLevelWeb · **License:** GPL-2.0+
**Requires:** A configured Mollie key (from FluentCart Donations settings or the `FCD_MOLLIE_API_KEY` constant). Pairs with the *FCD One-Time Donation Sync* plugin, which turns each paid donation into a FluentCart order.

## Brief

Adds fundraising projects with a branded donation form (navy card + steel-blue progress bar) to a WordPress site. Admins create projects with a goal and start amount, pick a progress-bar style, and watch money come in. Donors pick or type an amount and pay through the existing FluentCart–Mollie flow, and each payment is tagged to its project and reliably reconciled so totals stay accurate.

## How it works

1. **Setup:** On activation it creates a `{prefix}_fcd_fundraising_payments` table and schedules a 5-minute reconcile cron.
2. **Admin ("Fundraising" menu):** Manage projects (name, goal, start amount), choose a progress template (pot / thermometer / ring / bar), and view the last 50 payments with status.
3. **Front-end:** The `[fcd_fundraising]` shortcode renders the donation card with preset + custom (pay-what-you-want) amounts, a project dropdown, and a live progress visual. An output-buffer fallback still renders it even if a page builder prints the shortcode as plain text.
4. **Payment:** A self-contained AJAX endpoint creates a Mollie payment directly, tags metadata (`source`, `project`, optional donor name/email), points the webhook at the sync plugin for instant order creation, and records the payment immediately as `open`. A per-request lock blocks duplicate clicks.
5. **Reconcile:** A cron job (plus a throttled run on admin page loads and a manual "refresh now" button) pulls recent Mollie payments that carry a project tag and upserts them idempotently by `payment_id`, keeping status/amount in sync.
6. **Totals:** A project's collected amount = start amount + sum of all *paid* online donations.

## Shortcode usage

```
[fcd_fundraising]                              // uses the globally chosen template
[fcd_fundraising template="pot"]               // override the progress style
[fcd_fundraising amounts="25,50,100,250"]      // custom preset amounts
[fcd_fundraising project="Projectnaam"]        // pin to one project, hide the dropdown
```
