# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] - 2026-11-16

- Large catalogs sync to the end: the sync no longer logs in to Pricee for each batch of products, which Pricee limits
- A price update from Pricee retried after a slow answer is now reported as unchanged instead of skipped
- The check that the API key belongs to this shop's website works however many websites your Pricee account has

## [2.0.0] - 2026-11-15

- Products are synced to the Pricee website your API key belongs to: create the API key from the Integrations page of your shop's website on Pricee
- The sync stops with a clear message when the API key belongs to another website, or is not tied to a website
- The module no longer creates websites on Pricee
- Multistore: Pricee credentials are now set shop by shop, as each shop has its own website on Pricee. Credentials set for all shops or a group are copied to each shop when upgrading

## [1.5.1] - 2026-11-15

- Report to Pricee the outcome of each price update, so you can see in Pricee which prices were applied or skipped and why

## [1.4.1] - 2026-11-15

- Follow the updated format of Pricee's webhook calls, required for automatic price updates

## [1.4.0] - 2026-11-15

- Support PrestaShop 9.2.1, and allow installing the module on later PrestaShop versions
- Fix the back office of PrestaShop 1.7.8 shops failing to load once the module is installed
- Fix the sync always failing with "Invalid token" on PrestaShop 9
- Fix the module being disabled when upgrading from version 1.0.0
- Multistore: choose the shop to sync; each shop is registered on Pricee with its own URL and only shows its own categories, including products disabled only in the default shop
- Multistore: show which level the settings form edits; Pricee credentials are shared by default and a shop can use its own, with its own API key, and the webhook is configured shop by shop
- Sync large catalogs without timeouts and without the 500-product limit, with a progress indicator and the number of products in error
- Send product URLs in the shop's default language, and report a product in error instead of taking over another product's tracking
- Only employees allowed to configure the module can run a sync
- Only update the base price of simple products through the webhook: products with variants, packs, or any promotion, quantity discount, catalog price rule, ecotax or visitor group discount are skipped, and promotions are never modified
- Skip webhook price updates below the product's cost price, in another currency, for disabled products or prices hidden from visitors (catalog mode included), for products of another shop, or sent twice in a call
- Never overwrite a price you changed yourself since Pricee last read it, and no longer rewrite a price visitors already see
- Fix webhook prices being saved tax included in shops displaying tax-included prices, which added the tax a second time
- Keep the price per unit (e.g. per kg) consistent when the webhook changes a product's price
- Match webhook updates to the right product: an unknown product ID is skipped instead of being looked up by URL, and a URL only matches the product whose name it contains
- The webhook keeps working in maintenance mode, no longer creates visitor statistics, no longer stops halfway on large updates, and reliably rejects replayed calls
- Show a confirmation when the settings are saved, and fix the API key field being narrower than the others

## [1.3.5] - 2026-11-04

- Fix products outside the selected categories being synced: the home category is no longer selectable and, in multistore setups, products from other shops or disabled in the current shop are excluded

## [1.3.4] - 2026-11-02

- Fix some product URLs sent during sync that were not working because URL rewriting was not applied, preventing Pricee from reading those product pages

## [1.3.3] - 2026-10-30

- Fix webhook price updates never being applied: match products by external ID when present, with a fallback to the product URL

## [1.3.2] - 2026-10-30

- Adopt products synced before external IDs existed during sync instead of creating duplicates

## [1.3.1] - 2026-10-30

- Fix product URLs sent during sync in multilingual shops (wrong language and shop were used when generating links)
- Fix duplicate products created during sync when the Pricee API lookup fails
- Update a product URL during sync only when it changed
- Count only successfully synced products and log per-product sync errors in PrestaShop logs

## [1.3.0] - 2026-10-27

- Reject replayed webhook payloads: every entry must carry a fresh signed timestamp and a nonce unused within the accepted window (both already sent by the app)
- Validate received prices before writing them and update the active promotion (specific price) instead of the base price when one applies, so the effective price follows Pricee
- Remove unused front office assets and the empty back office JavaScript file
- Fix module description in composer.json (wrongly inherited from Sylius)
- Add CSRF token validation to the sync endpoint
- Harden sync: paginate the Pricee websites API, sync each product only once when categories overlap, and return JSON for all sync errors; webhook events now use PrestaShop logs instead of a web-accessible file

## [1.2.1] - 2026-10-22

- Handle Pricee API errors during product sync: return a JSON error with the API detail and log it in PrestaShop logs instead of an HTTP 500
- Fix API key being erased when re-saving the configuration form: password fields are never repopulated by PrestaShop, so an empty submitted key is now kept instead of overwriting the stored value, and submitted values are trimmed

## [1.2.0] - 2026-10-19

- Show disabled categories in the sync category list
- Support PrestaShop 9.2.0

## [1.1.0] - 2026-03-31

- Add webhook support
- Update url of products during syncing if needed
- Support PrestaShop 9.1.0

## [1.0.0] - 2026-01-23

- Initial version
