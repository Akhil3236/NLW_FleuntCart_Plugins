# Code Snippets

Standalone PHP snippets for the FluentCart / Mollie setup — the small bits of glue that don't
justify a full plugin. Drop them into a snippets manager (FluentSnippets, Code Snippets, WPCode)
or into the active theme's `functions.php`.

## Conventions

- One snippet per file, named `NN-short-description.php`.
- Every file starts with a header comment: what it does, where it runs, and which plugin it
  depends on.
- Snippets assume **FluentCart** is active. Guard anything that touches FluentCart Pro or Mollie
  with a `class_exists()` / `function_exists()` check so a deactivated plugin can't fatal the site.
- No opening `?>` at the end of the file.

## Snippet header template

```php
<?php
/**
 * Title:    What this snippet does, in one line.
 * Runs on:  front-end | admin | both
 * Requires: FluentCart (+ Pro / Mollie key, if applicable)
 * Notes:    Any gotchas, hook order, or manual steps.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
```

## Related plugins in this repo

| Folder | Plugin |
|---|---|
| `FLuent Cart One TIme SYnce` | FCD One-Time Donation Sync — turns paid Mollie donations into FluentCart orders |
| `Fundrasing` | FCD Fundraising — donation projects, form, and progress bars |
| `Fleunt Cart webhook retry` | FluentCart Webhook Retry |
| `Mollie sub reactive and manager` | Mollie Subscription Manager — reactivate cancelled subs, update amount/interval |
| `Mollie subscriber importer` | Subscription Importer |
| `Product exporter` | FluentCart Product Exporter |
| `fleunt cart shipping weight plugin` | FluentCart Weight Shipping (NL) — weight-based PostNL tiers |

Each plugin folder holds its distributable `.zip` plus a `-README.md` describing how it works.
