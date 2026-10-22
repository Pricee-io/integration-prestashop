# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
