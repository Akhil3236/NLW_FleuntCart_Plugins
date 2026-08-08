# Subscription Importer

**Version:** 1.0.0 · **Author:** Subscription Migration · **License:** GPL-2.0-or-later
**Requires:** WordPress + FluentCart (with Mollie active), and **WP-CLI**. Loads only under WP-CLI — it has no browser UI. **Test-only:** live Mollie keys are blocked in code.

## Brief

A WP-CLI migration tool that imports Pronamic Pay subscription export JSON into FluentCart + Mollie. It's built for a careful, staged migration: validate the file, create matching Mollie *Test* customers and mandates from sanitized dummy IDs, dry-run, then import for real — with full logging, resumability, and duplicate protection so a run can be stopped and restarted without creating anything twice.

## How it works

1. **Setup:** On activation it creates two tables — `wp_subscription_migration_map` (per-Pronamic-ID import state) and `wp_mollie_test_mapping` (dummy export IDs → real Mollie Test IDs). The Mollie test key comes from the `SUBSCRIPTION_MOLLIE_TEST_API_KEY` constant in `wp-config.php`.
2. **Why the "simulate" step exists:** The sanitized export replaces real Mollie customer/mandate IDs with `*_dummy_*` placeholders that don't exist in Mollie. `simulate-mollie` creates real Test customers + mandates for each and stores the mapping, so the importer sends valid IDs.
3. **Validation:** `validate` checks every record (email, mandate, interval, amount, next-payment date) before anything is written.
4. **Import:** For each record it creates the WP user (if needed), FluentCart customer, FluentCart subscription, and the Mollie Test subscription — storing both the mapped test IDs and the original dummy IDs on the subscription's `config` for audit. Idempotent per Pronamic ID, so `--resume` skips already-imported records and retries failed ones.
5. **Safety:** Any `live_` key is rejected — every subscription it creates lives in Mollie **Test mode** only. All activity is logged to `wp-content/uploads/subscription-migration.log`.

## Commands

| Command | Description |
|---|---|
| `wp subs mollie-config` | Confirm the Mollie test key is configured |
| `wp subs validate --file=...` | Validate the export JSON |
| `wp subs simulate-mollie --file=...` | Create Mollie Test customers/mandates + store the mapping (**run before import**) |
| `wp subs import --file=... [--dry-run] [--limit=N] [--offset=N] [--resume]` | Run the import |

## Typical workflow

```bash
wp subs mollie-config
wp subs validate      --file=wp-content/uploads/exports/subscriptions-export-sanitized.json
wp subs simulate-mollie --file=...            # must run before import
wp subs import --file=... --dry-run --limit=1 # safe preview
wp subs import --file=... --limit=1           # one real record
wp subs import --file=...                      # full run (add --resume to continue)
```

> Always verify results in the Mollie dashboard with **Test mode ON**.
