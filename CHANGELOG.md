# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- Return a JSON error response instead of an HTTP 500 HTML page when the Pricee API authentication fails during product sync
- Log the original API error message (status code and response body) in PrestaShop logs
- Show the API error detail in the admin sync error message

## [1.2.0] - 2026-10-19

- Show disabled categories in the sync category list
- Support PrestaShop 9.2.0

## [1.1.0] - 2026-03-31

- Add webhook support
- Update url of products during syncing if needed
- Support PrestaShop 9.1.0

## [1.0.0] - 2026-01-23

- Initial version
