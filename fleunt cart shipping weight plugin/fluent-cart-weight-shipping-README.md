# FluentCart Weight Shipping (NL)

**Version:** 1.0.0 · **Author:** Site · **License:** GPL-2.0-or-later
**Requires:** WordPress 5.8+, PHP 7.4+, and **FluentCart** active (shows an admin notice if missing).

## Brief

Replaces FluentCart's zone-based shipping rates with a **weight-based** rate for physical orders, using Dutch (PostNL-style) tiers. It totals the weight of everything in the cart, maps it to a fixed price tier, and swaps the shipping option shown at checkout to match — all without editing FluentCart's own code.

## How it works

1. **Weight source:** For each physical line item it reads the product's weight (from the Etch bridge meta `_etch_fc_gewicht` / FluentCart product meta), multiplies by quantity, and sums across the cart to get total grams. Non-physical items are ignored.
2. **Tier table (grams → price):**

   | Total weight | Rate | Label |
   |---|---|---|
   | ≤ 49 g | €0.00 | Brievenbus |
   | 50–99 g | €2.88 | Brievenbuspakket |
   | 100–349 g | €3.84 | Brievenbuspakket |
   | 350–1499 g | €4.80 | Brievenbuspakket |
   | ≥ 1500 g | €7.25 | Pakketpost |

3. **Checkout patching:** It hooks FluentCart's cart/checkout filters to inject the computed tier charge and label. The shipping-options HTML is replaced two ways so the price is always right: via AJAX **fragments** during live checkout updates, and via an **output-buffer** swap on the first page load.
4. **Order correction:** When the order is drafted, FluentCart would otherwise set `shipping_total` from its zone calculation — the plugin overwrites that with the weight tier amount and syncs the pending charge transaction so the recorded total matches the cart.
5. **No core changes:** Everything is done through public FluentCart hooks and HTML replacement; FluentCart itself is never modified.

## Customising the tiers

The price/weight thresholds live in the `tier_from_total_grams()` method in `includes/class-fcws-shipping.php`. Edit the gram cut-offs and cent amounts there to fit different couriers or countries.
