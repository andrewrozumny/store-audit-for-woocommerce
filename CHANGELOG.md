# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- Uninstall now removes every row the plugin created: the run rows, the cached wordpress.org answers with their timeout rows, and the same on every site of a multisite network.
- Results of an audit run are stored one option per check, so checks saved by overlapping requests no longer overwrite each other.
- Finishing a run in which some checks did not run lists them as "not checked" and keeps them out of the score; a run with no results at all no longer replaces the last report.
- A stored report that is damaged (wrong types, unknown severity, no rows) is repaired or ignored instead of reaching the page, the exports and the dashboard widget.
- Text from the site (plugin, theme and file names) is neutralised in the Markdown export; the settings printed for the admin script can no longer hold raw tag characters.
- A nonce sent as an array is refused before WordPress turns it into a string and logs a PHP warning.
- Checks that read WooCommerce tables (order storage, object cache, tax rates, shipping zones) report "not checked" when the table is missing instead of filling the log with database errors.
- The backups check says so when a folder cannot be read, instead of reporting all clear and a PHP warning.
- The reason shown for a plugin closed on wordpress.org is cut to 300 plain characters.
- The audit screen no longer lists a check twice when its request failed in the browser.

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
