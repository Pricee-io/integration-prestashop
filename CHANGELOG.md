# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- Multistore: choose the shop to sync in the sync form; each shop is registered on Pricee with its own URL, and only shows its own categories and product counts
- Multistore: show which level the settings form edits; Pricee credentials are shared by default and a shop can switch to its own, and webhook settings are configured shop by shop
- Sync large catalogs without timeouts: products are sent in batches with a progress indicator, and the 500-product limit is removed
- Show the number of products in error at the end of a sync, and ask to select a category when none is checked
- Send product URLs in the shop's default language instead of the back-office user's language
- Fix products enabled in a shop but disabled in the default shop being left out of the sync
- Only update the base price of a product through the webhook: products with a promotion, quantity discount, catalog price rule, ecotax or visitor group discount are no longer updated, and promotions are never modified
- Skip webhook price updates that would go below the product's cost price, for disabled products or products with a hidden price, for products of another shop, in a currency other than the shop's, or when the same product is sent twice
- Fix webhook prices being saved tax included in shops displaying tax-included prices, which added the tax a second time
- Match webhook updates to the right product: an unknown product ID is skipped instead of being looked up by URL, and a URL only matches the product whose name it contains
- Keep receiving webhook price updates while the shop is in maintenance mode
- Fix the API key field being narrower than the other settings fields
- Multistore: a shop switching to its own credentials must enter its own API key instead of silently keeping the shared one
- Show a confirmation when the settings are saved
- Only employees allowed to configure the module can run a sync
- Fix the sync always failing with "Invalid token" on PrestaShop 9
- Fix the back office of PrestaShop 1.7.8 shops failing to load once the module is installed
- During sync, a product whose URL is already tracked for another product is reported in error instead of taking over its tracking
- Keep the price per unit (e.g. per kg) consistent when the webhook changes a product's price
- Skip webhook price updates when visitors see no prices (catalog mode, or prices hidden for the visitor group)
- No longer rewrite a product's price through the webhook when the price visitors see does not change
- Reject replayed webhook calls reliably, even when several calls arrive at the same time
- Fix the module being disabled when upgrading from version 1.0.0
- Support PrestaShop 9.2.1

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
