# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.1] - 2026-10-30

- Fix product URLs sent during sync in multilingual shops (wrong language and shop were used when generating links)
- Fix duplicate products created during sync when the Pricee API lookup fails
- Update a product URL during sync only when it changed, and adopt products synced before external IDs existed instead of duplicating them
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
