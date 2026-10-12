# Compatibility

Last full run: 2026-10-12. Plugin version: 0.1.0 (release zip sha256 5dc6b2f7e023...).

## Support policy

- WordPress: requires 6.6 or newer. Tested up to 7.1.
- PHP: requires 8.1 or newer.
- WooCommerce: requires 9.0 or newer.
- Versions below these are not supported.

## What is tested

Every row is a clean WordPress install (WP_DEBUG, WP_DEBUG_LOG and SCRIPT_DEBUG on) with WooCommerce activated, a few products and orders, and the plugin installed from the release zip. For each row the matrix runs the unit test suite, a full audit through WP-CLI (`wp store-audit run --format=json`, output structure validated), a browserless smoke test (the step-by-step runner requests as the admin screen sends them, the admin page, the Dashboard widget, JSON, print and Markdown exports, and rejection of requests from a subscriber, a logged-out visitor and a request without a nonce), and a deactivate and uninstall cycle that must remove the plugin's own options and transients and leave WooCommerce data in place. The debug.log of each row must contain no notices, warnings, deprecations or fatal errors that point at this plugin. Lines that come from WordPress core, WooCommerce or other code are counted separately.

WooCommerce columns show the minimum supported release (the latest 9.0.x) and the newest release that officially installs on that WordPress and PHP combination (the newest 11.x where WordPress allows it). HPOS (custom order tables) is switched on and off in every row and its real state is checked before the audit runs.

Reproduce with `bash bin/matrix.sh` from the plugin repository (Docker and about 20 GB of disk are needed). Filters for a single row: `bash bin/matrix.sh --wp=7.1 --php=8.4 --woo=current --hpos=on`.

## Summary

86 of 88 rows passed.

## Matrix: WordPress x PHP x WooCommerce x HPOS

| WordPress | PHP | WooCommerce | HPOS | Unit | Integration | Smoke | Uninstall | debug.log plugin | debug.log other | Result |
|---|---|---|---|---|---|---|---|---|---|---|
| 6.6.10 | 8.1 | 9.0.4 (min) | on | PASS | PASS (score 51) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.1 | 9.0.4 (min) | off | PASS | PASS (score 50) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.1 | 9.8.7 (current) | on | PASS | PASS (score 51) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.1 | 9.8.7 (current) | off | PASS | PASS (score 50) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.2 | 9.0.4 (min) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.2 | 9.0.4 (min) | off | PASS | PASS (score 53) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.2 | 9.8.7 (current) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.2 | 9.8.7 (current) | off | PASS | PASS (score 53) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.3 | 9.0.4 (min) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.3 | 9.0.4 (min) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.3 | 9.8.7 (current) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.3 | 9.8.7 (current) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 0 | PASS |
| 6.6.10 | 8.4 | 9.0.4 (min) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 908 | PASS |
| 6.6.10 | 8.4 | 9.0.4 (min) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 538 | PASS |
| 6.6.10 | 8.4 | 9.8.7 (current) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 3 | PASS |
| 6.6.10 | 8.4 | 9.8.7 (current) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 3 | PASS |
| 6.8.11 | 8.1 | 9.0.4 (min) | on | PASS | PASS (score 51) | PASS | PASS | 0 | 16 | PASS |
| 6.8.11 | 8.1 | 9.0.4 (min) | off | PASS | PASS (score 50) | PASS | PASS | 0 | 10 | PASS |
| 6.8.11 | 8.1 | 10.7.0 (current) | on | PASS | PASS (score 51) | PASS | PASS | 0 | 1 | PASS |
| 6.8.11 | 8.1 | 10.7.0 (current) | off | PASS | PASS (score 50) | PASS | PASS | 0 | 0 | PASS |
| 6.8.11 | 8.2 | 9.0.4 (min) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 16 | PASS |
| 6.8.11 | 8.2 | 9.0.4 (min) | off | PASS | PASS (score 53) | PASS | PASS | 0 | 10 | PASS |
| 6.8.11 | 8.2 | 10.7.0 (current) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 1 | PASS |
| 6.8.11 | 8.2 | 10.7.0 (current) | off | PASS | PASS (score 53) | PASS | PASS | 0 | 0 | PASS |
| 6.8.11 | 8.3 | 9.0.4 (min) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 16 | PASS |
| 6.8.11 | 8.3 | 9.0.4 (min) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 10 | PASS |
| 6.8.11 | 8.3 | 10.7.0 (current) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 1 | PASS |
| 6.8.11 | 8.3 | 10.7.0 (current) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 0 | PASS |
| 6.8.11 | 8.4 | 9.0.4 (min) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 924 | PASS |
| 6.8.11 | 8.4 | 9.0.4 (min) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 548 | PASS |
| 6.8.11 | 8.4 | 10.7.0 (current) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 1 | PASS |
| 6.8.11 | 8.4 | 10.7.0 (current) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 0 | PASS |
| 6.9.10 | 8.1 | 9.0.4 (min) | on | PASS | PASS (score 51) | PASS | PASS | 0 | 16 | PASS |
| 6.9.10 | 8.1 | 9.0.4 (min) | off | PASS | PASS (score 50) | PASS | PASS | 0 | 10 | PASS |
| 6.9.10 | 8.1 | 11.0.1 (current) | on | PASS | PASS (score 51) | PASS | PASS | 0 | 1 | PASS |
| 6.9.10 | 8.1 | 11.0.1 (current) | off | PASS | PASS (score 50) | PASS | PASS | 0 | 0 | PASS |
| 6.9.10 | 8.2 | 9.0.4 (min) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 16 | PASS |
| 6.9.10 | 8.2 | 9.0.4 (min) | off | PASS | PASS (score 53) | PASS | PASS | 0 | 10 | PASS |
| 6.9.10 | 8.2 | 11.0.1 (current) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 1 | PASS |
| 6.9.10 | 8.2 | 11.0.1 (current) | off | PASS | PASS (score 53) | PASS | PASS | 0 | 0 | PASS |
| 6.9.10 | 8.3 | 9.0.4 (min) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 16 | PASS |
| 6.9.10 | 8.3 | 9.0.4 (min) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 10 | PASS |
| 6.9.10 | 8.3 | 11.0.1 (current) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 1 | PASS |
| 6.9.10 | 8.3 | 11.0.1 (current) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 0 | PASS |
| 6.9.10 | 8.4 | 9.0.4 (min) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 924 | PASS |
| 6.9.10 | 8.4 | 9.0.4 (min) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 548 | PASS |
| 6.9.10 | 8.4 | 11.0.1 (current) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 1 | PASS |
| 6.9.10 | 8.4 | 11.0.1 (current) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 0 | PASS |
| 7.0.7 | 8.1 | 9.0.4 (min) | on | PASS | PASS (score 51) | PASS | PASS | 0 | 16 | PASS |
| 7.0.7 | 8.1 | 9.0.4 (min) | off | PASS | PASS (score 50) | PASS | PASS | 0 | 10 | PASS |
| 7.0.7 | 8.1 | 11.2.1 (current) | on | PASS | PASS (score 51) | PASS | PASS | 0 | 1 | PASS |
| 7.0.7 | 8.1 | 11.2.1 (current) | off | PASS | PASS (score 50) | PASS | PASS | 0 | 0 | PASS |
| 7.0.7 | 8.2 | 9.0.4 (min) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 16 | PASS |
| 7.0.7 | 8.2 | 9.0.4 (min) | off | PASS | PASS (score 53) | PASS | PASS | 0 | 10 | PASS |
| 7.0.7 | 8.2 | 11.2.1 (current) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 1 | PASS |
| 7.0.7 | 8.2 | 11.2.1 (current) | off | PASS | PASS (score 53) | PASS | PASS | 0 | 0 | PASS |
| 7.0.7 | 8.3 | 9.0.4 (min) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 16 | PASS |
| 7.0.7 | 8.3 | 9.0.4 (min) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 10 | PASS |
| 7.0.7 | 8.3 | 11.2.1 (current) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 1 | PASS |
| 7.0.7 | 8.3 | 11.2.1 (current) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 0 | PASS |
| 7.0.7 | 8.4 | 9.0.4 (min) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 924 | PASS |
| 7.0.7 | 8.4 | 9.0.4 (min) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 548 | PASS |
| 7.0.7 | 8.4 | 11.2.1 (current) | on | PASS | PASS (score 56) | PASS | PASS | 0 | 1 | PASS |
| 7.0.7 | 8.4 | 11.2.1 (current) | off | PASS | PASS (score 55) | PASS | PASS | 0 | 0 | PASS |
| 7.1.3 | 8.1 | 9.0.4 (min) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 16 | PASS |
| 7.1.3 | 8.1 | 9.0.4 (min) | off | PASS | PASS (score 54) | PASS | PASS | 0 | 10 | PASS |
| 7.1.3 | 8.1 | 11.2.1 (current) | on | PASS | PASS (score 54) | PASS | PASS | 0 | 1 | PASS |
| 7.1.3 | 8.1 | 11.2.1 (current) | off | PASS | PASS (score 54) | PASS | PASS | 0 | 0 | PASS |
| 7.1.3 | 8.2 | 9.0.4 (min) | on | PASS | PASS (score 57) | PASS | PASS | 0 | 16 | PASS |
| 7.1.3 | 8.2 | 9.0.4 (min) | off | PASS | PASS (score 57) | PASS | PASS | 0 | 10 | PASS |
| 7.1.3 | 8.2 | 11.2.1 (current) | on | PASS | PASS (score 57) | PASS | PASS | 0 | 1 | PASS |
| 7.1.3 | 8.2 | 11.2.1 (current) | off | PASS | PASS (score 57) | PASS | PASS | 0 | 0 | PASS |
| 7.1.3 | 8.3 | 9.0.4 (min) | on | PASS | PASS (score 59) | PASS | PASS | 0 | 16 | PASS |
| 7.1.3 | 8.3 | 9.0.4 (min) | off | PASS | PASS (score 59) | PASS | PASS | 0 | 10 | PASS |
| 7.1.3 | 8.3 | 11.2.1 (current) | on | PASS | PASS (score 59) | PASS | PASS | 0 | 1 | PASS |
| 7.1.3 | 8.3 | 11.2.1 (current) | off | PASS | PASS (score 59) | PASS | PASS | 0 | 0 | PASS |
| 7.1.3 | 8.4 | 9.0.4 (min) | on | PASS | PASS (score 59) | PASS | PASS | 0 | 924 | PASS |
| 7.1.3 | 8.4 | 9.0.4 (min) | off | PASS | PASS (score 59) | PASS | PASS | 0 | 548 | PASS |
| 7.1.3 | 8.4 | 11.2.1 (current) | on | PASS | PASS (score 59) | PASS | PASS | 0 | 1 | PASS |
| 7.1.3 | 8.4 | 11.2.1 (current) | off | PASS | PASS (score 59) | PASS | PASS | 0 | 0 | PASS |

## PHP 8.5 (smoke)

| WordPress | PHP | WooCommerce | HPOS | Unit | Integration | Smoke | Uninstall | debug.log plugin | debug.log other | Result |
|---|---|---|---|---|---|---|---|---|---|---|
| 7.1.3 | 8.5 | 9.0.4 (min) | on | PASS | PASS (score 59) | PASS | PASS | 0 | 1668 | PASS |
| 7.1.3 | 8.5 | 9.0.4 (min) | off | PASS | PASS (score 59) | PASS | PASS | 0 | 762 | PASS |
| 7.1.3 | 8.5 | 11.2.1 (current) | on | PASS | PASS (score 59) | PASS | PASS | 0 | 745 | PASS |
| 7.1.3 | 8.5 | 11.2.1 (current) | off | PASS | PASS (score 59) | PASS | PASS | 0 | 214 | PASS |

## Multisite (network activation)

| WordPress | PHP | WooCommerce | HPOS | Unit | Integration | Smoke | Uninstall | debug.log plugin | debug.log other | Result |
|---|---|---|---|---|---|---|---|---|---|---|
| 7.1.3 (multisite, network activation) | 8.4 | 11.2.1 (current) | on | PASS | FAIL | FAIL | PASS | 0 | 2 | FAIL |
| 7.1.3 (multisite, per-site activation) | 8.4 | 11.2.1 (current) | on | PASS | PASS | PASS | PASS | 0 | 2 | PASS |
| 7.1.3 (multisite, network activation) | 8.4 | 11.2.1 (current) | off | PASS | FAIL | FAIL | PASS | 0 | 0 | FAIL |
| 7.1.3 (multisite, per-site activation) | 8.4 | 11.2.1 (current) | off | PASS | PASS | PASS | PASS | 0 | 0 | PASS |

The audit and the smoke test run on the main site and on a sub-site; the uninstall check covers every site and the network tables.

## Plugin Check on the release zip

| WordPress | PHP | WooCommerce | Findings | Result |
|---|---|---|---|---|
| 7.1.3 | 8.4 | 11.2.1 | errors=0 warnings=0 | PASS |

Command: `wp plugin check store-audit-for-woocommerce --include-experimental --format=csv`, run against the plugin installed from the zip. Zero errors is required.

## Notes

WooCommerce selection:

- WordPress 6.6.10: WooCommerce 9.8.7 (newest 11.x needs WP 7.0 or newer; this is the newest release that installs on WP 6.6.10).
- WordPress 6.8.11: WooCommerce 10.7.0 (newest 11.x needs WP 7.0 or newer; this is the newest release that installs on WP 6.8.11).
- WordPress 6.9.10: WooCommerce 11.0.1 (newest 11.x needs WP 7.0 or newer; this is the newest release that installs on WP 6.9.10).

Third-party noise: debug.log lines from WordPress core, WooCommerce and other code ranged from 0 to 1668 per row. They are listed per row above and are not counted against the plugin.

- WP 7.1.3 multisite network activation / PHP 8.4 / WooCommerce 11.2.1 / HPOS on: FAIL, integration failed on sa-matrix.test (cli rc=1, validator rc=1);smoke failed on sa-matrix.test: STEP FAIL markdown with no report is a clean 404 :: expected 404 error, got 200 ;STEP FAIL ajax start :: start failed: 200 ;STEP FAIL export JSON is valid :: no stored report;;integration failed on sa-matrix.test/sub (cli rc=1, validator rc=1);smoke failed on sa-matrix.test/sub: STEP FAIL markdown with no report is a clean 404 :: expected 404 error, got 200 ;STEP FAIL ajax start :: start failed: 200 ;STEP FAIL export JSON is valid :: no stored report;
- WP 7.1.3 multisite network activation / PHP 8.4 / WooCommerce 11.2.1 / HPOS off: FAIL, integration failed on sa-matrix.test (cli rc=1, validator rc=1);smoke failed on sa-matrix.test: STEP FAIL markdown with no report is a clean 404 :: expected 404 error, got 200 ;STEP FAIL ajax start :: start failed: 200 ;STEP FAIL export JSON is valid :: no stored report;;integration failed on sa-matrix.test/sub (cli rc=1, validator rc=1);smoke failed on sa-matrix.test/sub: STEP FAIL markdown with no report is a clean 404 :: expected 404 error, got 200 ;STEP FAIL ajax start :: start failed: 200 ;STEP FAIL export JSON is valid :: no stored report;

