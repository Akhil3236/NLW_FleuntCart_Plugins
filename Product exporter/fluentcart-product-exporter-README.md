# FluentCart Product Exporter (CSV)

**Version:** 2.2.0 · **Author:** Akhil · **License:** GPL-2.0+
**Requires:** FluentCart installed. Read-only — it never modifies any data.

## Brief

Exports the entire FluentCart catalogue — products, variations, and bundles — to a CSV that matches FluentCart's own **Bulk Product Import** format (WooCommerce-style parent/child rows). It reads straight from the `fct_*` tables, converts prices from cents to decimals, and streams a ready-to-reimport file, making it useful for backups, migrations, or bulk edits in a spreadsheet.

## How it works

1. **Admin (Tools → "FC Product Export"):** Shows how many products and variation rows exist, with a nonce-protected "Download Products CSV" button.
2. **Row shape:** A **simple** product (one variation) becomes a single `simple` row; a **variable** product becomes one `variable` parent row (no price) plus one `variation` child row per variant, linked back to the parent by SKU.
3. **Data pulled per product:** categories, product + variation images (from `fct_product_meta`), attribute name/value per variation, and pricing — with `compare_price` mapped to regular-vs-sale price and cents converted to decimals.
4. **Subscriptions:** Payment Type, Interval, Trial Days, Installment count, and Setup/Signup Fee columns are filled from each variation's stored subscription data.
5. **Bundles:** Exported as a normal product, with the bundled child SKUs/IDs listed in a trailing `Bundle (FYI only)` column. The importer ignores that column, so the bundle link must be rebuilt manually after import.
6. **Output:** A UTF-8 (BOM) CSV with the importer's exact header order and a timestamped filename; missing SKUs fall back to a generated `FC-{id}` pattern.
