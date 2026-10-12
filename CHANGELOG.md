# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Security
- The audit, the exports (JSON, print, Markdown), the menu page and the Dashboard widget need the `manage_options` capability. A shop manager no longer sees them.
- Exports that leave the site (printed report, Markdown, JSON) no longer carry the details of checks that name accounts, API keys or files: those show a count and a pointer to the Store Audit screen in wp-admin. The screen and WP-CLI keep the full data.
- The self probe requests an address only when scheme, host and port all match the home or site address.
- An internal error in a check is reported in general terms; paths and raw messages stay out of the report and go to the PHP error log only when `WP_DEBUG` is on.

### Changed
- A critical finding caps its section at 40 and the whole report at 49.
- Checks that did not run no longer count as passed. When more than half of a section did not run, the section shows "incomplete" instead of a score, and the overall score is marked "Incomplete: N of M checks ran" on the screen, the dashboard widget, the printed report, the Markdown, the JSON and in WP-CLI. The dashboard widget says "Good work" only without critical, warning and not-checked results.
- A site that cannot reach itself reports "not checked" instead of an informational note. An address on another host (uploads on a CDN) is reported as not applicable.
- The environment checks report the memory and time limits the host set, not the values the audit raises for its own work. The audit raises the time limit only when it is set and below 30 seconds, and never lowers it. The memory check states that WordPress raises the PHP limit only where the host allows it.
- REST API keys: read keys that are unused or idle for over a year are reported too, with a note on what a read key gives away.
- Guessable admin username: only a display name equal to the login (any case) is a warning. A login in the author archive address is a separate note with advice that actually removes it.
- The plugin starts on `plugins_loaded`, so WooCommerce is detected on single sites and on networks; it declares compatibility with the Cart and Checkout blocks.
- Attribution and the remaining report texts are translatable. The filter `store_audit_show_attribution` (default true) turns the attribution off in exports; the audit screen always shows it.

### Performance
- Shipping zones: the "sells a shippable product" probe is one indexed LIMIT 1 query instead of a catalogue-wide product query. Starting and finishing a run read and clear the stored results with a few queries instead of one per check. The negative stock check loads the product titles with one cache priming call.

### Fixed
- Uninstall removes only the rows the plugin created, by exact name (the two options, the run rows and the cached wordpress.org answers with their timeout rows), and walks a network in batches of 100 sites.
- The theme check skips a folder it cannot read instead of failing.
- The audit screen shows the "report could not be built" text when the last step fails, not the "could not be started" text.
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
- Card testing check: separates login protection from checkout protection (captcha plugins and payment gateways with fraud screening) and lists both. Scanners such as Sucuri Scanner no longer count as protection. The false "Only Stripe is active" text is gone.
- XML-RPC: when Jetpack, WooPayments or a Jetpack connection needs it, an open endpoint is information with the advice to keep it open and block `system.multicall` and pingbacks.
- Tax rates: an empty rate table is fine when an automatic tax service (WooCommerce Tax, TaxJar, Avalara, Stripe Tax) calculates the tax; otherwise it is information, not a warning.
- Inactive plugins on a multisite: a plugin used by any site of the network is not inactive (sites are read in batches of 100, networks above 500 sites are not walked), and the advice is to ask the network admin before deleting.
- Abandoned plugins: plugins whose Update URI points away from wordpress.org are not looked up, a different plugin that holds the slug on wordpress.org is not mistaken for the installed one, and a plugin closed for a reason other than security is a warning, not critical.
- Backups in public folders: also looks in wp-content and in the folders backup plugins use, covers more archive types (.7z, .rar, .wpress, .tgz, .daf and others), skips WooCommerce protected uploads and zip files that belong to the media library, and says when a large uploads folder was only partly scanned.
- Exposed .git and .env files: probes the home address, checks the disk in the folder the home page is served from and in ABSPATH, and counts a file as exposed only when its content looks like a real `.git/HEAD`, `.git/config` or dotenv file, so a catch-all 200 page is not reported.
- Facts that are not findings (guest checkout allowed, normal table sizes, order storage, catalog size) are reported as OK, not as information. The missing object cache is penalised once, in Environment; the catalog check only points to it.
- Negative stock ignores products and variations that take backorders. Must-use plugins installed by the host are not warned about. Sucuri Scanner is not a duplicate of a firewall. Image formats: a WebP optimizer plugin (ShortPixel, Imagify, EWWW, Optimole, Converter for Media, WebP Express) replaces the missing-WebP note with "served as WebP by ..., not checked".
- Registry: a check whose id does not match `^[a-z0-9_]+$`, repeats an id, or uses an unknown section is left out and reported with `_doing_it_wrong()`. The README extension example loads its class only when Store Audit runs.

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
