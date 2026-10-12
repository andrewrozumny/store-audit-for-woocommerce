=== Store Audit for WooCommerce ===
Contributors: andrewrozumny
Tags: woocommerce, security, audit, performance, health check
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-button read-only audit of your WooCommerce store: a score, plain-language findings and a one-line fix for each.

== Description ==

Store Audit for WooCommerce checks your store with one button and gives you a report you can read without being a developer. Every finding says what it is, why it matters and what to do about it. You get a score from 0 to 100 with a breakdown per section, so you can see at a glance where the problems are.

The audit is read-only. It never changes your settings, files, products or orders. The only things it saves are its own last report, the results of the run in progress (removed when the next run starts) and a 12-hour cache of WordPress.org lookups. Everything is removed when you delete the plugin.

It is made for two kinds of people: store owners who want to know what is wrong before they pay anyone, and developers or agencies who want a quick first look at a client store.

**How it works**

* Press "Run audit" under WooCommerce > Store Audit. Only administrators can run the audit and see the report.
* The audit runs one check per request, with a progress bar, so it works on cheap shared hosting without timeouts.
* You get a score, a per-section breakdown, and findings sorted by severity.
* Export the report: download JSON, print or save as PDF, or copy it as Markdown to paste into an email or a ticket. Exports leave out administrator logins, API key owners and backup file names, so the report is safe to forward.
* A Dashboard widget shows your score and the top 3 problems.
* WP-CLI: `wp store-audit run --format=table|json|markdown`.

**What it checks (49 checks in 5 sections)**

*Security*

* Number of administrators, inactive administrators and guessable admin usernames
* Exposed .git and .env files, wp-config.php exposure, backups in public folders
* Uploads folder listing, WordPress readme and license files, exposed WordPress version
* User list in the REST API and XML-RPC
* Secure admin (FORCE_SSL_ADMIN)
* Bot and card-testing protection on checkout

*WooCommerce settings*

* Cart, checkout and account pages, terms and conditions page
* Guest checkout and accounts, tax rates, shipping zones
* Payment gateways left in test mode, webhooks, REST API keys
* Negative stock, abandoned orders, order storage (HPOS)

*Environment*

* PHP, WordPress and WooCommerce versions
* HTTPS, memory limit, maximum execution time
* WP_DEBUG, theme and plugin file editor
* Scheduled tasks (cron) and persistent object cache

*Plugins and theme*

* Available plugin and theme updates
* Abandoned or closed plugins (checked against the WordPress.org directory; plugins that update from elsewhere are skipped)
* Inactive plugins, must-use plugins, plugins that do the same job
* Child theme and direct theme edits (the theme is looked up on WordPress.org but not checked for being closed)

*Performance*

* Autoloaded options, expired transients, post revisions
* Database table sizes, catalog size, order storage
* Number of active plugins, WordPress Heartbeat
* Image quality and format

**Who it is for**

If you run a store and suspect something is off, run the audit first. You will know what is wrong, how serious it is and what to ask for, before you hire anyone. If you do hire someone, send them the report.

If you are a developer or agency, the report is a fast first pass over a client store, in a form the client can read too.

**Extending**

Add your own checks with the `store_audit_checks` filter. A check is a class implementing `StoreAudit\Checks\CheckInterface`. See the GitHub repository for an example.

**Roadmap**

Ideas for a future Pro version:

* Scheduled audits
* Audit history and trends
* Multiple sites in one view
* Email reports
* Fix estimates

**External services**

This plugin connects to the WordPress.org plugin and theme directory API (api.wordpress.org) to find out whether the plugins installed on your site have been closed or abandoned, and whether the active theme comes from WordPress.org. What is sent: the slugs of your installed plugins and of the active theme. This happens when you run an audit, and the answers are cached for 12 hours. No other data about you, your visitors or your store is sent. Service provided by WordPress.org: [privacy policy](https://wordpress.org/about/privacy/).

The security checks also make requests from your site to its own public URLs, to test what a visitor could see. These requests go to your own domain only, with the user agent `StoreAudit/<version>`. The addresses requested are: the home page, `/wp-config.php`, `/.env`, `/.git/HEAD`, `/.git/config`, `/xmlrpc.php` (one harmless POST that lists methods), `/readme.html`, `/license.txt`, the REST API user list (`/wp-json/wp/v2/users`) and the uploads folder. If a firewall or security plugin blocks visitors who request files such as `.env`, add this user agent or your server's own IP address to its allowlist before you run the audit, so the firewall does not block your own server.

Source code: https://github.com/andrewrozumny/store-audit-for-woocommerce

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it. WooCommerce must be active.
3. Open WooCommerce > Store Audit and press "Run audit".

== Frequently Asked Questions ==

= Does it change anything on my site? =

No. Every check only reads data. It does not modify settings, files, plugins or the database (apart from saving the report, the run in progress and the plugin directory answers). The security checks request a few addresses on your own site, listed under "External services"; a strict firewall may log or block these requests.

= Does it send data anywhere? =

No data about your store leaves your site. The only outside call is to the public WordPress.org plugin directory API, which receives plugin slugs so the plugin can tell whether a plugin is closed or abandoned. Answers are cached for 12 hours. The security checks also request your own public URLs from your own server. See "External services" above.

= Will it slow down my site? =

No. The audit only runs when you start it, one check per request, so it never holds a request open for long. Visitors are not affected.

= Why is my score low on a fresh store? =

A new store often has settings that are fine for testing but not for selling: no payment method, a payment gateway in test mode, no terms page, an administrator login shown as the author name, WP_DEBUG on, or an old PHP version from the host. Any critical finding caps the score at 49, so fix those first, then work through the rest and run the audit again. If some checks could not run, the score is marked as incomplete.

= Can I add my own checks? =

Yes. Use the `store_audit_checks` filter and add an object of a class that implements `StoreAudit\Checks\CheckInterface`. An example is in the GitHub repository.

= How do I share the report with a developer? =

Use the export buttons on the report: download it as JSON, print or save it as PDF, or copy it as Markdown and paste it into an email or a ticket. Exports leave out administrator logins, API key owners and backup file names; those details stay on the Store Audit screen. From the command line, run `wp store-audit run --format=markdown` (the command line shows full details, because only server administrators can run it).

== Screenshots ==

1. The audit report with the score and section breakdown.
2. A section with a finding expanded: what it is, why it matters, what to do.
3. The audit running, with progress.
4. The Dashboard widget with the score and top 3 problems.
5. The printable report.

== Changelog ==

= 0.1.1 =
* Security: the audit, exports and Dashboard widget are for administrators only; exports leave out logins, API key owners and backup file names.
* Fixed: the score is capped by critical findings and marked incomplete when checks could not run.
* Fixed: memory and time limit checks report the server values, not the values raised for the audit.
* Fixed: network activation on multisite, complete clean-up on uninstall, fewer false alarms in security, tax, backup, plugin and performance checks.

= 0.1.0 =
* First release: 49 read-only checks in 5 sections, score with per-section breakdown, one-check-per-request runner, exports (JSON, print or PDF, Markdown), Dashboard widget, WP-CLI command and the `store_audit_checks` filter.

== Upgrade Notice ==

= 0.1.1 =
Security and accuracy release: admin-only access, safer exports and a more honest score. Recommended for everyone.

= 0.1.0 =
First release.
