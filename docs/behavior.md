# PrestaShop integration

Simplified description of what the PrestaShop module (`pricee/`) does, to check its behavior against what is expected. Compatible with PrestaShop 1.7.8 to 9.2.1, PHP 7.4+.

The module does two things:

1. **Sync** (shop → Pricee): sends the shop's products to Pricee so they get tracked.
2. **Webhook** (Pricee → shop): receives the best price found by Pricee and writes it as the product's price.

## Module page

One page in the back office (Modules → Pricee.io → Configure), in French, with two blocks.

### Configuration block

| Field | Behavior |
|---|---|
| ID Client | Pricee API client ID. |
| Clé API | Pricee API key. Masked; leaving it empty on save keeps the stored key. |
| Activer la synchronisation webhook | Yes/No. When No, the webhook refuses every call. |
| URL du Webhook | Read-only, to copy into Pricee. In multistore with several shops in context: one field per shop, labelled with the shop name. |
| Clé secrète webhook | Shared secret used to check that webhook calls come from Pricee. Must be the same on both sides. |

All values are saved for the shop context selected in the back office (a single shop, a group or all shops), with PrestaShop's usual fallback: shop → group → global.

### Sync block

- **Boutique à synchroniser**: dropdown, only shown when several shops are in the back-office context. The current shop is preselected.
- **Category list** of the selected shop, with checkboxes. Each line shows the category name and the number of active products in that shop. Disabled categories are listed too, greyed out with "désactivée".
- Not listed: the root category and the shop's home category (every product is in it, so it would mean the whole catalog), and categories outside the shop's own tree.
- **Synchroniser les produits** button: runs the sync and shows "N produits synchronisés" or an error.

## Sync (shop → Pricee)

When the button is clicked, for the selected shop:

1. Log in to Pricee with the ID Client / Clé API **of that shop**.
2. Find the Pricee website matching the shop's front URL (its own domain in multistore). The match ignores `http`/`https` and a trailing `/`. If none exists, it is created.
3. Take the products that are **in the checked categories, associated with the shop and active in that shop**. Each product is sent once, even if it is in several checked categories. **At most 500 products per sync**; the rest are silently ignored.
4. For each product, send to Pricee:
   - `url`: the product page URL on the shop, in the shop's default language.
   - `extId`: the PrestaShop product ID.
5. On the Pricee side, for each product:
   - Look for an existing product of that website by `extId`, then by `url` (exact match, trailing `/` ignored). A product is never matched to another product's entry.
   - Found: update its `url` and/or `extId` if they changed, otherwise do nothing.
   - Not found: create it.
6. A product that fails is logged in PrestaShop logs and skipped; the sync goes on. The displayed count is the number of products sent successfully.

The sync never deletes anything on Pricee: unchecking a category or disabling a product does not remove it from tracking.

## Webhook (Pricee → shop)

Pricee calls `https://<shop>/module/pricee/webhook` with a list of products and their best price. Each shop has its own URL; the call only affects the shop that receives it.

### The whole call is refused if

- the body or the `X-Signature` header is missing;
- the webhook is disabled or has no secret for this shop;
- the signature does not match the secret;
- the body is not a valid JSON list;
- an entry has no timestamp or no nonce, a timestamp more than 5 minutes off, or a nonce already used (replay protection; used nonces are remembered for 10 minutes).

The webhook still works when the shop is in maintenance mode or restricts countries.

### Price-writing rules

Writing a price is the critical action of the module, so the rule is: **when in doubt, skip**. An entry's price is written only if it passes every check below, in this order. A failed check skips that entry only (the others are still processed) and is logged in the PrestaShop logs with the reason.

| # | Check | Skipped when |
|---|---|---|
| 1 | Price received | `bestPriceAmount` is missing. |
| 2 | Valid price | The price is not a number, or is 0 or negative. |
| 3 | Currency | The currency is missing or is not the shop's default currency (no conversion is done). |
| 4 | Product found | With an `extId`: no product has that ID (never falls back to the URL). Without `extId` (products synced before it existed): the URL does not point to an existing product, or the name in the URL is not that product's own. |
| 5 | Sent once | The same product is targeted by several entries of the call: none of them is applied. |
| 6 | Shop | The product is not associated with the shop receiving the call. |
| 7 | Price shown | The product is disabled, or its price is hidden (not available for order and "show price" off). |
| 8 | Simple product | The product has variants (combinations) or is a pack. |
| 9 | No promotion | Any promotion, quantity discount or catalog price rule exists for this product in this shop or for all shops, for anyone (any customer, group, currency, country), current or scheduled. Only expired ones and those of other shops are ignored. Promotions are never created or edited. |
| 10 | No ecotax | Ecotax is enabled and the product has an ecotax (the displayed price includes it). |
| 11 | No group discount | Visitors (not logged in) get a customer group discount, globally or on the product's category. |
| 12 | Not below cost | The new price, tax excluded, is below the product's cost price (when one is set). |
| 13 | Changed | The new price is the current one: nothing is written. |

When every check passes, the **base price** of the product is replaced:

- The received price is the price visitors see. If the shop displays prices tax included to visitors, the tax of the shop's default country is removed first (PrestaShop stores prices tax excluded).
- Nothing else changes: no promotion, no other shop's price (in multistore, the price is written for the receiving shop only).

There is no limit on the size of a change on the plugin side: Pricee only sends prices within the margin set for the website or the product.

The call answers 200 "Webhook received and validated" even when some entries were skipped.

## Install / uninstall

- Install: creates the settings (empty) and an empty `pricee` table.
- Uninstall: deletes all the settings for every shop. The `pricee` table is kept (it is unused).

## Tests

`make test` runs the integration tests inside the PrestaShop container, on a disposable copy of the dev database (`test_prestashop`): the dev data is never modified. Each rule of the table above, the call-level refusals and the sync behavior have their own test.
