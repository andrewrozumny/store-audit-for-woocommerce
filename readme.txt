=== Store Audit for WooCommerce ===
Contributors: andrewrozumny
Tags: woocommerce, audit, security, performance, health check
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A read-only audit of your WooCommerce store: one button, a clear report with plain-language explanations and a one-line fix for each finding.

== Description ==

Store Audit runs a set of checks on your WooCommerce store and shows a report with a health score. Every finding explains what it is, why it matters and what to do about it, in store-owner language.

The plugin only reads data. It never changes anything on your site.

* One button, step-by-step progress.
* Findings grouped by Security, WooCommerce settings, Environment, Plugins and theme, Performance.
* WP-CLI: `wp store-audit run --format=json`.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it. WooCommerce must be active.
3. Open WooCommerce > Store Audit and press "Run audit".

== Changelog ==

= 0.1.0 =
* Initial skeleton: runner, report, two environment checks.
