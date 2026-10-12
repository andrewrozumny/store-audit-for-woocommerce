# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-12

First release.

### Added
- 49 read-only checks in 5 sections: Environment, Plugins and theme, WooCommerce settings, Security, Performance.
- Score from 0 to 100 with a per-section breakdown.
- Plain-language explanation and a one-line "what to do" for every finding.
- Runner that executes one check per request, so it works on low-end hosting.
- Exports: download JSON, print or save as PDF, copy as Markdown.
- Dashboard widget with the score and the top 3 problems.
- WP-CLI command `wp store-audit run --format=table|json|markdown`.
- `store_audit_checks` filter and `StoreAudit\Checks\CheckInterface` for custom checks.
