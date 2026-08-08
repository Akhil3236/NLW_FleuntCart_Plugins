# FCD One-Time Donation Sync

**Version:** 1.2.0 · **Author:** NextLevelWeb · **License:** GPL-2.0+
**Requires:** FluentCart (Pro) active + a Mollie key. Consumes the payments created by the *FCD Fundraising* / donations flow.

## Brief

Turns every paid one-time Mollie donation into a proper FluentCart order — creating the Customer, Order, OrderItem, and OrderTransaction so donations show up in FluentCart exactly like real purchases. It runs three redundant sync paths so a donation is never missed, and is fully idempotent so the same payment is never recorded twice.

## How it works

1. **Three sync paths (belt and braces):**
   - **Webhook (instant):** It filters the donation flow so Mollie calls this plugin's endpoint (`?fcd-onetime-sync=mollie-ipn`); it fast-acks Mollie with a `200` and queues the work in the background.
   - **Sync-on-redirect:** When the donor lands back on `?fcd_donation=paid`, it polls the last 10 minutes of Mollie before the page renders, so their donation is already in FluentCart on the thank-you screen.
   - **1-minute cron (safety net):** Polls recent paid payments (24h window) and syncs any that slipped through; also runs throttled on admin page loads.
2. **What qualifies:** An order is created only when the payment is `status=paid`, `sequenceType=oneoff`, and `metadata.source` is in the allowed list (`fluentcart-donations-direct`, `fcd-mollie-pay`). Monthly donations are left to FluentCart.
3. **No duplicates:** A named MySQL lock serializes each payment across all triggers, and idempotency is enforced on the transaction's `vendor_charge_id` (the Mollie id).
4. **Safe order creation:** Order + item + transaction are written inside a DB transaction (rolls back on error), only into columns that actually exist in the install, with the donor name resolved from a fallback chain (typed name → billing → bank/card holder). Project, donor, and method are stored on the order.
5. **Admin (Settings → "Donation Sync"):** Shows environment health (FluentCart present, Mollie key mode/source, webhook host match), cron status, last poll stats, and the last 25 sync events, plus "Run poll now" and "Reschedule cron" buttons.

## Order of events

```
Fundraising creates & tags the Mollie payment
        → Mollie confirms (paid)
        → One-Time Sync writes the matching FluentCart order
```
