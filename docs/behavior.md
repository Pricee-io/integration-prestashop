# PrestaShop integration

Simplified description of what the PrestaShop module (`pricee/`) does, to check its behavior against what is expected. Tested on PrestaShop 1.7.8 to 9.2.1 and installable on later versions, PHP 7.4+.

The module does two things:

1. **Sync** (shop → Pricee): sends the shop's products to Pricee so they get tracked.
2. **Webhook** (Pricee → shop): receives the price Pricee computed for each product, lower or higher than the current one, and writes it as the product's price.

## Module page

One page in the back office (Modules → Pricee.io → Configure), in French, with two blocks.

### Configuration block

| Field | Behavior |
|---|---|
| ID Client | Pricee API client ID. |
| Clé API | Pricee API key. Masked; leaving it empty on save keeps the stored key. |
| Activer la synchronisation webhook | Yes/No. When No, the webhook refuses every call. |
| URL du Webhook | Read-only, the shop's webhook URL to copy into Pricee. |
| Clé secrète webhook | Shared secret used to check that webhook calls come from Pricee. Must be the same on both sides. |

Without multistore, all settings are saved for the shop, as usual.

With multistore, the form title shows the level being edited ("Configuration — Toutes les boutiques", "— Groupe : X" or "— Boutique : X", from the shop selector at the top of the back office), and the settings are saved at that level:

- **Pricee credentials** (ID Client, Clé API): shared by default.
  - For all shops or a group: the credentials apply to every shop (of the group), and the help text lists the shops and groups that use their own credentials instead.
  - For one shop: a switch "Utiliser les identifiants communs". Yes (default): the shop uses the shared credentials, its credential fields are disabled, and saving removes any credentials of its own. No: the shop uses the credentials entered below; when the shop has no API key of its own yet, the Clé API must be filled in, otherwise nothing is saved and an error asks for it.
- **Webhook settings** (activation, URL, secret key): per shop only, because each shop is its own website with its own webhook on Pricee. For all shops or a group, they are replaced by a note asking to select a shop.

### Sync block

- **Boutique à synchroniser**: dropdown, only shown when several shops are in the back-office context. The current shop is preselected.
- **Category list** of the selected shop, with checkboxes. Each line shows the category name and the number of active products in that shop. Disabled categories are listed too, greyed out with "désactivée".
- Not listed: the root category and the shop's home category (every product is in it, so it would mean the whole catalog), and categories outside the shop's own tree.
- **Synchroniser les produits** button: runs the sync (only for employees allowed to configure the module). It asks to select a category if none is checked, shows the progress ("Synchronisation en cours : X / total") and ends with "N produits synchronisés", plus the number of products in error if any (details in the PrestaShop logs). If the sync stops on an error, the message says how many products were synced before it.

## Sync (shop → Pricee)

When the button is clicked, for the selected shop:

1. Log in to Pricee with the ID Client / Clé API **of that shop**.
2. Find the Pricee website matching the shop's front URL (its own domain in multistore). The match ignores `http`/`https` and a trailing `/`. If none exists, it is created.
3. Take the products that are **in the checked categories, associated with the shop and active in that shop**. Each product is sent once, even if it is in several checked categories. There is no limit on the number of products: they are sent in batches of 50, one request each, so a large catalog never hits a timeout. Steps 1 and 2 are repeated for each batch.
4. For each product, send to Pricee:
   - `url`: the product page URL on the shop, in the shop's default language.
   - `extId`: the PrestaShop product ID.
5. On the Pricee side, for each product:
   - Look for an existing product of that website by `extId`, then by `url` (exact match, trailing `/` ignored). A product is never matched to another product's entry: an entry found by URL is only adopted if it has no `extId`; if its `extId` is another product's, the product is in error (logged) and nothing is changed on Pricee.
   - Found: update its `url` and/or `extId` if they changed, otherwise do nothing.
   - Not found: create it.
6. A product that fails is logged in PrestaShop logs and skipped; the sync goes on. The displayed count is the number of products sent successfully.

The sync never deletes anything on Pricee: unchecking a category or disabling a product does not remove it from tracking.

## Webhook (Pricee → shop)

Pricee calls `https://<shop>/module/pricee/webhook` with a list of products, the price to set for each (from the website's pricing rule and the product's floor and reference price, see the Pricee app) and the price Pricee last read on the product page. Each shop has its own URL; the call only affects the shop that receives it.

### The whole call is refused if

- the body or the `X-Signature` header is missing;
- the webhook is disabled or has no secret for this shop;
- the signature does not match the secret;
- the body is not a valid JSON list;
- an entry has no timestamp or no nonce, a timestamp more than 5 minutes off, or a nonce already used (replay protection; used nonces are remembered for 10 minutes, per shop, and a nonce is accepted only once even when several calls arrive at the same time).

### Price-writing rules

Writing a price is the critical action of the module, so the rule is: **when in doubt, skip**. An entry's price is written only if it passes every check below, in this order. A failed check skips that entry only (the others are still processed) and is logged in the PrestaShop logs with the reason.

| # | Check | Skipped when |
|---|---|---|
| 1 | Price received | `priceAmount` is missing. |
| 2 | Valid price | The price is not a number, or is 0 or negative. |
| 3 | Currency | The currency is missing or is not the shop's default currency (no conversion is done). |
| 4 | Product found | With an `extId`: no product has that ID (never falls back to the URL). Without `extId` (products synced before it existed): the URL does not point to an existing product, or the name in the URL is not that product's own. |
| 5 | Sent once | The same product is targeted by several entries of the call: none of them is applied. |
| 6 | Shop | The product is not associated with the shop receiving the call. |
| 7 | Price shown | The product is disabled, its price is hidden (not available for order and "show price" off), or visitors see no prices at all (catalog mode without prices, or "show prices" off for the visitor group). |
| 8 | Simple product | The product has variants (combinations) or is a pack. |
| 9 | No promotion | Any promotion, quantity discount or catalog price rule exists for this product in this shop or for all shops, for anyone (any customer, group, currency, country), current or scheduled. Only expired ones and those of other shops are ignored. Promotions are never created or edited. |
| 10 | No ecotax | Ecotax is enabled and the product has an ecotax (the displayed price includes it). |
| 11 | No group discount | Visitors (not logged in) get a customer group discount, globally or on the product's category. |
| 12 | Price unchanged in the shop | The price Pricee last read (`currentPriceAmount`) is missing, or is no longer the price visitors see (rounded to the currency's decimals): the merchant changed it since, and their change wins. |
| 13 | Not below cost | The new price, tax excluded, is below the product's cost price (when one is set). |
| 14 | Changed | The new price is the current one as visitors see it (rounded to the currency's decimals): nothing is written. |
| 15 | Unit price | The product has a price per unit (e.g. per kg) but a current price of 0, so its unit price cannot be adjusted. |

When every check passes, the **base price** of the product is replaced:

- The received price is the price visitors see. If the shop displays prices tax included to visitors, the tax of the shop's default country is removed first (PrestaShop stores prices tax excluded).
- The price per unit (e.g. per kg), when set, changes in the same proportion, so it stays consistent with the new price.
- Nothing else changes: no promotion, no other shop's price (in multistore, the price is written for the receiving shop only).

There is no limit on the size of a change on the plugin side: Pricee never sends a price below the product's floor (its minimum price, or its reference price lowered by the largest decrease set for the website or the product, whichever is higher), and never raises a price above its reference price.

The call answers 200 "Webhook received and validated" even when some entries were skipped, with the number of entries per outcome (`outcomes`: `updated_price`, `unchanged`, `skipped_promotion`…) and the outcome of each entry in payload order (`results`), which Pricee records for each price it sent.

The call is answered before the shop's front office starts: no visitor cookie, cart or statistics are created, and it works whatever the maintenance mode or country restrictions. It may run for up to 5 minutes, as each product written runs the shop's hooks.

## Install / uninstall

- Install: creates the settings (empty) and the `pricee_webhook_nonce` table (nonces of recent webhook calls).
- Upgrade to 1.4.0: creates that table, drops the unused `pricee` table of earlier versions and the nonces they stored in the settings.
- Uninstall: deletes all the settings for every shop and the `pricee_webhook_nonce` table (temporary data only).

## Tests

`make test` runs the integration tests inside the PrestaShop container, on a disposable copy of the dev database (`test_prestashop`): the dev data is never modified. CI also runs them on every supported PrestaShop version (1.7.8, 8.2 and 9.2), single-shop and multistore. Each rule of the table above, the call-level refusals and the sync behavior have their own test.
