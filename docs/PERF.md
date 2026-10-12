# Performance on a large store

Store Audit for WooCommerce is run from the browser: one AJAX request per check, so a slow check must stay well inside the host's
PHP limits. This document records what that looks like on a big shop at the tightest common limits (`memory_limit=128M`,
`max_execution_time=30`), and where the plugin could be cheaper.

Short version: every step finished, nothing timed out, nothing ran out of memory, the slowest step took about 2 s, and the plugin adds
at most 5 MB on top of what WordPress and WooCommerce already use. Four things in the plugin's own queries are worth tightening; none is a failure.
See "Findings" below.

## Conditions

| | |
|---|---|
| Software | WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.4, MySQL 8.4 (InnoDB buffer pool 128 MB, smaller than the biggest tables, so reads are partly cold) |
| Limits | `memory_limit=128M`, `max_execution_time=30`; `WP_MEMORY_LIMIT` and `WP_MAX_MEMORY_LIMIT` both 128M, so WordPress does not raise the limit |
| Active plugins | WooCommerce and Store Audit only (installed from the release zip) |
| Object cache | none |
| Signed in as | the first administrator |

How a step is measured. Each step of a browser run is one separate PHP process, started with the limits above: `start`, one `store_audit_run_check`
per check (in the order the runner uses), `finish`, the Markdown copy (`store_audit_markdown`), and the two exports (`store_audit_download`
for JSON and `store_audit_print` for the printable page). The harness does what `admin-ajax.php` and `admin-post.php` do: it loads WordPress,
loads the admin includes, runs `admin_init`, then calls the handler. Time is the whole process (WordPress boot included); "Handler ms" is the
handler alone. Peak memory is `memory_get_peak_usage(true)` in that same process. Query counts come from `$wpdb->num_queries`.
A second process per step runs with `SAVEQUERIES` on: it gives the slowest query, the repeated query shapes, and an `EXPLAIN` of every
distinct `SELECT`. A full table scan means `type=ALL` on a table with more than 10,000 rows (table size from the optimizer's estimate).
A repeated shape means the same query with different values more than 10 times inside one step. The tables show the first, plain process
(the colder one); the query columns come from the second.

Checks that call out over HTTP (the security probes and the wordpress.org lookups) use a PHP built-in web server on the loopback address
of the same container. With `--slow-network` the run is repeated with the site address and wordpress.org unreachable (packets are dropped, so
each request runs into its own timeout).

## Data set

Built once and cached, then restored for later runs. A few template records are created through the WooCommerce API so every row has the
shape WooCommerce writes, then multiplied with `INSERT ... SELECT` straight into the real tables.

- 10,000 products: about 9,800 simple, 200 variable (5 to 20 variations each), and one product with 500 variations. 2,976 variations in total.
  Product lookup table rows, term links and a handful of negative-stock products are included.
- 50,024 orders with 1 to 5 items each (173,002 items), mixed statuses, spread over two years. 2,000 orders are pending or failed and older than 30 days.
- 506 users: 5 administrators and 501 customers.
- To make the housekeeping checks work on real weight: 20,000 media items (100,000 empty files on disk, five sizes each),
  30,000 revisions, 25,000 expired or live transient pairs, 2,000 autoloaded options (about 1.6 MB).
- Orders are stored twice, in two databases: HPOS on (`wc_orders` and related tables, with the placeholder posts WooCommerce keeps) and HPOS off
  (posts and postmeta, data sync off). The two are generated separately rather than migrated, so each is exactly what that storage mode produces.
  The order analytics tables are not filled.

Integrity is checked after generation with `wc_get_order()` and `wc_get_product()` on random samples (orders, simple and variable products, the
500-variation product, administrator count).

## Results

<!-- perf:results:start -->
**Result: 0 timeouts at 30 s, 0 memory errors at 128M, 0 errors in 110 measured steps.**

Environment: PHP 8.4.26, MySQL 8.4.11 (InnoDB buffer pool 128 MB), WordPress 7.1.3, WooCommerce 11.2.1. Plugin zip b291e6ee8cff. Run time 251 s.

### HPOS on (orders in the dedicated tables)

Data set: 10,000 products, 2,976 variations, 50,024 orders, 173,002 order items, 506 users (5 administrators, 501 customers); 1,000 pending and 1,000 failed orders older than 30 days.

| Step | Time ms | Handler ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans | N+1 shapes |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| boot (WordPress + admin) | 503 |  | 81 |  | 0 |  |  | 0 | 0 |
| start | 599 | 38 | 79 | 3.3 | 51 | 4.9 | 0.99 | 0 | 1 |
| check environment_php_version | 459 | 18 | 79 | 3.4 | 3 | 0.1 | 0.08 | 0 | 0 |
| check environment_wp_version | 435 | 16 | 79 | 3.4 | 4 | 0.2 | 0.09 | 0 | 0 |
| check environment_wc_version | 433 | 14 | 79 | 3.4 | 4 | 0.3 | 0.14 | 0 | 0 |
| check environment_wp_debug | 720 | 22 | 79 | 3.4 | 3 | 0.2 | 0.09 | 0 | 0 |
| check environment_https | 591 | 15 | 79 | 3.4 | 3 | 0.3 | 0.21 | 0 | 0 |
| check environment_file_editing | 498 | 20 | 79 | 3.4 | 3 | 0.3 | 0.2 | 0 | 0 |
| check environment_memory_limit | 463 | 16 | 79 | 3.4 | 3 | 0.1 | 0.08 | 0 | 0 |
| check environment_max_execution_time | 609 | 23 | 79 | 3.4 | 3 | 0.2 | 0.1 | 0 | 0 |
| check environment_cron | 589 | 22 | 79 | 3.5 | 5 | 2 | 0.76 | 0 | 0 |
| check environment_object_cache | 492 | 37 | 79 | 3.5 | 6 | 8.3 | 4.98 | 0 | 0 |
| check plugins_inactive | 497 | 23 | 79 | 3.5 | 3 | 1.1 | 0.54 | 0 | 0 |
| check plugins_updates | 607 | 30 | 79 | 3.5 | 5 | 1.6 | 0.64 | 0 | 0 |
| check plugins_abandoned | 2139 | 1349 | 81 | 4.1 | 10 | 1.4 | 0.53 | 0 | 0 |
| check plugins_duplicates | 402 | 16 | 79 | 3.5 | 3 | 0.2 | 0.17 | 0 | 0 |
| check plugins_theme_child | 922 | 503 | 79 | 3.9 | 6 | 2.2 | 1.33 | 0 | 0 |
| check plugins_mu | 566 | 24 | 79 | 3.4 | 3 | 1.3 | 0.7 | 0 | 0 |
| check woocommerce_guest_checkout | 440 | 17 | 79 | 3.4 | 6 | 0.3 | 0.08 | 0 | 0 |
| check woocommerce_test_gateways | 512 | 33 | 79 | 3.8 | 5 | 1.8 | 0.96 | 0 | 0 |
| check woocommerce_taxes | 595 | 24 | 79 | 3.4 | 3 | 0.2 | 0.16 | 0 | 0 |
| check woocommerce_shipping_zones | 829 | 207 | 79 | 3.5 | 11 | 116 | 112.72 | 0 | 0 |
| check woocommerce_pages | 498 | 18 | 79 | 3.5 | 9 | 2.2 | 0.67 | 0 | 0 |
| check woocommerce_terms_page | 691 | 25 | 79 | 3.4 | 4 | 0.2 | 0.08 | 0 | 0 |
| check woocommerce_hpos | 638 | 44 | 79 | 3.4 | 3 | 0.3 | 0.22 | 0 | 0 |
| check woocommerce_negative_stock | 626 | 25 | 79 | 3.5 | 26 | 5 | 1.89 | 0 | 1 |
| check woocommerce_stale_orders | 774 | 68 | 79 | 3.9 | 7 | 9.1 | 3.82 | 0 | 0 |
| check woocommerce_webhooks | 550 | 19 | 79 | 3.4 | 5 | 2.6 | 1.84 | 0 | 0 |
| check woocommerce_api_keys | 418 | 15 | 79 | 3.4 | 5 | 2.6 | 1.13 | 0 | 0 |
| check security_admin_username | 666 | 25 | 79 | 3.5 | 7 | 2.2 | 0.59 | 0 | 0 |
| check security_admin_count | 595 | 18 | 79 | 3.5 | 6 | 3.5 | 1.44 | 0 | 0 |
| check security_inactive_admins | 471 | 15 | 79 | 3.5 | 6 | 2.4 | 0.86 | 0 | 0 |
| check security_xmlrpc | 1026 | 399 | 79 | 3.9 | 3 | 1.2 | 0.5 | 0 | 0 |
| check security_rest_users | 1008 | 454 | 79 | 3.9 | 3 | 2 | 1.28 | 0 | 0 |
| check security_generator | 1056 | 462 | 81 | 4.1 | 4 | 1.5 | 0.75 | 0 | 0 |
| check security_uploads_listing | 506 | 18 | 79 | 3.9 | 3 | 1.6 | 0.81 | 0 | 0 |
| check security_wp_config_http | 1242 | 553 | 79 | 3.9 | 3 | 1.4 | 0.54 | 0 | 0 |
| check security_readme_license | 590 | 21 | 79 | 3.9 | 3 | 1.4 | 0.72 | 0 | 0 |
| check security_exposed_dotfiles | 1986 | 1391 | 79 | 3.9 | 3 | 4 | 2.95 | 0 | 0 |
| check security_backups_in_webroot | 1789 | 1190 | 81 | 4.9 | 3 | 1.9 | 0.86 | 0 | 0 |
| check security_antifraud | 498 | 18 | 79 | 3.5 | 3 | 0.1 | 0.07 | 0 | 0 |
| check security_ssl_admin | 594 | 24 | 79 | 3.4 | 3 | 0.2 | 0.16 | 0 | 0 |
| check performance_catalog_size | 581 | 28 | 79 | 3.5 | 5 | 3.8 | 1.4 | 0 | 0 |
| check performance_orders_storage | 498 | 30 | 79 | 3.5 | 5 | 8 | 5.64 | 0 | 0 |
| check performance_table_sizes | 674 | 30 | 79 | 3.5 | 4 | 0.6 | 0.48 | 0 | 0 |
| check performance_autoload | 574 | 22 | 79 | 3.5 | 5 | 4.2 | 1.57 | 0 | 0 |
| check performance_expired_transients | 461 | 32 | 79 | 3.5 | 4 | 10.3 | 9.14 | 1 | 0 |
| check performance_revisions | 650 | 32 | 79 | 3.5 | 4 | 4.1 | 2.72 | 0 | 0 |
| check performance_active_plugins | 584 | 14 | 79 | 3.5 | 3 | 0.3 | 0.25 | 0 | 0 |
| check performance_heartbeat | 490 | 15 | 79 | 3.4 | 3 | 0.5 | 0.44 | 0 | 0 |
| check performance_images | 613 | 55 | 79 | 3.8 | 4 | 11.5 | 10.21 | 0 | 0 |
| finish | 583 | 48 | 79 | 3.6 | 153 | 17.4 | 1.7 | 0 | 3 |
| markdown (copy button) | 458 | 4 | 79 | 2.4 | 1 | 0.1 | 0.12 | 0 | 0 |
| export JSON | 1002 | 3 | 83 | 2.9 | 1 | 0.1 | 0.11 | 0 | 0 |
| export printable HTML | 532 | 4 | 83 | 2.5 | 1 | 0.2 | 0.2 | 0 | 0 |

Nothing over 5 s, and no step adds more than 64 MB on top of the boot baseline.

Slowest step: check plugins_abandoned, 2.14 s. Highest peak: export JSON, 83 MB. WordPress with WooCommerce and the admin includes alone (boot): 81 MB, 0.50 s. Whole pass: 76 s.

Largest tables: wp_woocommerce_order_itemmeta 1,516,051 rows (164 MB), wp_postmeta 293,714 rows (61 MB), wp_woocommerce_order_items 172,437 rows (17 MB), wp_posts 112,267 rows (72 MB), wp_wc_order_addresses 99,459 rows (26 MB), wp_wc_orders_meta 99,327 rows (33 MB), wp_options 54,216 rows (16 MB), wp_wc_order_operational_data 49,742 rows (11 MB).

### HPOS off (orders in posts and postmeta, sync off)

Data set: 10,000 products, 2,976 variations, 50,024 orders, 173,002 order items, 506 users (5 administrators, 501 customers); 1,000 pending and 1,000 failed orders older than 30 days.

| Step | Time ms | Handler ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans | N+1 shapes |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| boot (WordPress + admin) | 456 |  | 81 |  | 0 |  |  | 0 | 0 |
| start | 676 | 30 | 79 | 3.3 | 51 | 5.7 | 0.91 | 0 | 1 |
| check environment_php_version | 486 | 16 | 79 | 3.4 | 3 | 0.2 | 0.16 | 0 | 0 |
| check environment_wp_version | 421 | 14 | 79 | 3.4 | 4 | 0.3 | 0.14 | 0 | 0 |
| check environment_wc_version | 705 | 22 | 79 | 3.4 | 4 | 0.2 | 0.09 | 0 | 0 |
| check environment_wp_debug | 604 | 16 | 79 | 3.4 | 3 | 0.2 | 0.11 | 0 | 0 |
| check environment_https | 464 | 14 | 79 | 3.4 | 3 | 0.3 | 0.19 | 0 | 0 |
| check environment_file_editing | 678 | 28 | 79 | 3.4 | 3 | 0.2 | 0.11 | 0 | 0 |
| check environment_memory_limit | 576 | 20 | 79 | 3.4 | 3 | 0.1 | 0.07 | 0 | 0 |
| check environment_max_execution_time | 451 | 14 | 79 | 3.4 | 3 | 1.2 | 0.59 | 0 | 0 |
| check environment_cron | 612 | 22 | 79 | 3.5 | 5 | 0.9 | 0.59 | 0 | 0 |
| check environment_object_cache | 646 | 30 | 79 | 3.5 | 5 | 7.3 | 4.65 | 0 | 0 |
| check plugins_inactive | 464 | 21 | 79 | 3.5 | 3 | 1.3 | 0.56 | 0 | 0 |
| check plugins_updates | 604 | 30 | 79 | 3.5 | 5 | 0.4 | 0.12 | 0 | 0 |
| check plugins_abandoned | 1940 | 1355 | 81 | 4.1 | 9 | 1.6 | 0.79 | 0 | 0 |
| check plugins_duplicates | 607 | 27 | 79 | 3.5 | 3 | 1.4 | 0.76 | 0 | 0 |
| check plugins_theme_child | 1046 | 514 | 79 | 3.9 | 5 | 1.7 | 0.79 | 0 | 0 |
| check plugins_mu | 401 | 15 | 79 | 3.4 | 3 | 1.2 | 0.51 | 0 | 0 |
| check woocommerce_guest_checkout | 720 | 26 | 79 | 3.4 | 6 | 0.4 | 0.1 | 0 | 0 |
| check woocommerce_test_gateways | 586 | 21 | 79 | 3.8 | 5 | 0.4 | 0.15 | 0 | 0 |
| check woocommerce_taxes | 421 | 13 | 79 | 3.4 | 3 | 0.2 | 0.13 | 0 | 0 |
| check woocommerce_shipping_zones | 838 | 216 | 79 | 3.5 | 11 | 120.8 | 117.27 | 0 | 0 |
| check woocommerce_pages | 460 | 15 | 79 | 3.5 | 9 | 0.7 | 0.17 | 0 | 0 |
| check woocommerce_terms_page | 394 | 14 | 79 | 3.4 | 4 | 0.3 | 0.13 | 0 | 0 |
| check woocommerce_hpos | 687 | 38 | 79 | 3.4 | 3 | 0.2 | 0.12 | 0 | 0 |
| check woocommerce_negative_stock | 547 | 22 | 79 | 3.5 | 26 | 5.2 | 1.53 | 0 | 1 |
| check woocommerce_stale_orders | 497 | 50 | 79 | 3.5 | 7 | 6.9 | 3.4 | 0 | 0 |
| check woocommerce_webhooks | 652 | 23 | 79 | 3.4 | 5 | 0.8 | 0.48 | 0 | 0 |
| check woocommerce_api_keys | 567 | 20 | 79 | 3.4 | 5 | 3 | 1.12 | 0 | 0 |
| check security_admin_username | 472 | 16 | 79 | 3.5 | 7 | 1.2 | 0.59 | 0 | 0 |
| check security_admin_count | 459 | 28 | 79 | 3.5 | 6 | 2.3 | 0.64 | 0 | 0 |
| check security_inactive_admins | 615 | 22 | 79 | 3.5 | 6 | 2.7 | 0.79 | 0 | 0 |
| check security_xmlrpc | 728 | 322 | 79 | 3.9 | 3 | 1.8 | 0.87 | 0 | 0 |
| check security_rest_users | 1284 | 699 | 79 | 3.9 | 3 | 1.3 | 0.59 | 0 | 0 |
| check security_generator | 920 | 372 | 79 | 4.0 | 4 | 1.7 | 0.8 | 0 | 0 |
| check security_uploads_listing | 700 | 31 | 79 | 3.8 | 3 | 1.7 | 0.78 | 0 | 0 |
| check security_wp_config_http | 1074 | 407 | 79 | 3.8 | 3 | 1.4 | 0.68 | 0 | 0 |
| check security_readme_license | 752 | 35 | 79 | 3.8 | 3 | 1.6 | 0.7 | 0 | 0 |
| check security_exposed_dotfiles | 1424 | 771 | 79 | 3.9 | 3 | 1.7 | 0.82 | 0 | 0 |
| check security_backups_in_webroot | 1579 | 1010 | 81 | 4.9 | 3 | 1.7 | 0.79 | 0 | 0 |
| check security_antifraud | 789 | 30 | 79 | 3.5 | 3 | 0.2 | 0.13 | 0 | 0 |
| check security_ssl_admin | 630 | 28 | 79 | 3.4 | 3 | 0.2 | 0.14 | 0 | 0 |
| check performance_catalog_size | 569 | 31 | 79 | 3.5 | 5 | 3.2 | 1.43 | 0 | 0 |
| check performance_orders_storage | 659 | 32 | 79 | 3.5 | 4 | 9.7 | 5.17 | 0 | 0 |
| check performance_table_sizes | 610 | 25 | 79 | 3.5 | 4 | 0.9 | 0.78 | 0 | 0 |
| check performance_autoload | 454 | 21 | 79 | 3.5 | 5 | 3.4 | 1.78 | 0 | 0 |
| check performance_expired_transients | 679 | 60 | 79 | 3.5 | 4 | 11.6 | 9.94 | 1 | 0 |
| check performance_revisions | 591 | 26 | 79 | 3.5 | 4 | 4.6 | 2.8 | 0 | 0 |
| check performance_active_plugins | 405 | 14 | 79 | 3.5 | 3 | 0.2 | 0.11 | 0 | 0 |
| check performance_heartbeat | 690 | 22 | 79 | 3.4 | 3 | 0.2 | 0.08 | 0 | 0 |
| check performance_images | 656 | 52 | 79 | 3.8 | 4 | 10.8 | 9.56 | 0 | 0 |
| finish | 483 | 33 | 79 | 3.6 | 153 | 20.5 | 1.31 | 0 | 3 |
| markdown (copy button) | 680 | 5 | 79 | 2.4 | 1 | 0.2 | 0.15 | 0 | 0 |
| export JSON | 1105 | 4 | 83 | 2.9 | 1 | 0.1 | 0.09 | 0 | 0 |
| export printable HTML | 491 | 5 | 83 | 2.5 | 1 | 0.1 | 0.1 | 0 | 0 |

Nothing over 5 s, and no step adds more than 64 MB on top of the boot baseline.

Slowest step: check plugins_abandoned, 1.94 s. Highest peak: export JSON, 83 MB. WordPress with WooCommerce and the admin includes alone (boot): 81 MB, 0.46 s. Whole pass: 76 s.

Largest tables: wp_postmeta 2,328,574 rows (308 MB), wp_woocommerce_order_itemmeta 1,515,498 rows (164 MB), wp_woocommerce_order_items 172,437 rows (17 MB), wp_posts 112,705 rows (76 MB), wp_options 54,216 rows (17 MB), wp_term_relationships 19,973 rows (3 MB), wp_wc_product_meta_lookup 13,166 rows (5 MB), wp_usermeta 8,795 rows (2 MB).

### Slow network (site address and wordpress.org unreachable)

Every request that leaves the server is dropped, so it runs into its own timeout (5 s per request at most in the plugin). HPOS on data set. Only steps over 5 s or with a violation are listed.

| Step | Time s | Peak MB | Result |
|---|---:|---:|---|
| check plugins_abandoned | 6.6 | 81 | ok |
| check plugins_theme_child | 5.7 | 79 | ok |
| check security_xmlrpc | 5.4 | 79 | ok |
| check security_rest_users | 5.6 | 79 | ok |
| check security_generator | 5.8 | 79 | ok |
| check security_uploads_listing | 5.8 | 79 | ok |
| check security_wp_config_http | 5.6 | 79 | ok |

Slowest step with the network down: 6.6 s (limit 30 s). Whole pass: 76 s.

### Query findings (SAVEQUERIES, EXPLAIN)

Full table scans (`type=ALL` on a table with more than 10,000 rows):

| HPOS | Step | Table | Type | Table rows | Query ms | Query |
|---|---|---|---|---:|---:|---|
| on | check performance_expired_transients | `wp_options` | ALL | 54,216 | 9.14 | `SELECT COUNT(*) FROM `wp_options` WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND CAST(option_value AS UNSIGNED) < 1791835507` |

Repeated query shapes (more than 10 times in one step):

| HPOS | Step | Times | Total ms | Query |
|---|---|---:|---:|---|
| on | start | 49 | 2.9 | `SELECT autoload FROM wp_options WHERE option_name = 'store_audit_run_result_environment_php_version'` |
| on | check woocommerce_negative_stock | 20 | 1.8 | `SELECT * FROM wp_posts WHERE ID = 1573 LIMIT 1` |
| on | finish | 51 | 3.5 | `SELECT option_value FROM wp_options WHERE option_name = 'store_audit_run_result_security_generator' LIMIT 1` |
| on | finish | 50 | 2.3 | `SELECT autoload FROM wp_options WHERE option_name = 'store_audit_run_result_performance_revisions'` |
| on | finish | 50 | 9.5 | `DELETE FROM `wp_options` WHERE `option_name` = 'store_audit_run_result_environment_php_version'` |
| off | start | 49 | 4.2 | `SELECT autoload FROM wp_options WHERE option_name = 'store_audit_run_result_environment_wc_version'` |
| off | check woocommerce_negative_stock | 20 | 1.4 | `SELECT * FROM wp_posts WHERE ID = 1273 LIMIT 1` |
| off | finish | 51 | 4.1 | `SELECT option_value FROM wp_options WHERE option_name = 'store_audit_last_report' LIMIT 1` |
| off | finish | 50 | 2.9 | `SELECT autoload FROM wp_options WHERE option_name = 'store_audit_run_result_environment_object_cache'` |

<!-- perf:results:end -->


## Findings

Nothing here breaks a limit. These are the places where the plugin does more work than it needs, ordered by cost.
"Performance" means a change that gives the same result faster; no behaviour changes in any of them.

### 1. Shipping zones check: product lookup is a heavy `WP_Query` (performance, the only one that grows with the catalog)

`includes/Checks/WooCommerce/ShippingZones.php`, `read_zones()`, the `wc_get_products()` call that asks "is there at least one published product that
is not virtual and not downloadable".

- Measured: 113 to 121 ms in the runs (HPOS on and off) at 10,000 products; 174 ms when run again by hand. Every other query in the audit is under 11 ms.
- Why: WooCommerce turns the call into a `WP_Query` with a left join on `term_relationships`, two joins on `postmeta` (`_virtual`, `_downloadable`),
  `GROUP BY ID` and `ORDER BY post_date DESC`. `EXPLAIN` shows `Using temporary; Using filesort` with about 23,000 candidate rows. `LIMIT 1` cannot stop
  early because of the grouping and sorting, so the cost grows with the number of products.
- Dropping only the `ORDER BY` did not help (116 ms); the grouping and the joins are the cost.
- Suggested fix, tried on the same data:

  ```sql
  SELECT l.product_id
  FROM wp_wc_product_meta_lookup l
  JOIN wp_posts p ON p.ID = l.product_id
  WHERE p.post_type = 'product' AND p.post_status = 'publish'
    AND l.virtual = 0 AND l.downloadable = 0
  LIMIT 1;
  ```

  2.6 ms, `index_merge` on the `virtual` and `downloadable` indexes. A plain two-join `postmeta` query with `LIMIT 1` (no term join, no grouping, no sort)
  took 0.2 ms. Either returns the same yes or no. The lookup table exists on every supported WooCommerce version; keep a fallback to the current call when it is missing.

### 2. Run state: one option per check read and deleted one by one (performance, small)

`includes/Ajax.php`: `clear_results()` (line 84, called from `start()` and `finish()`) deletes 50 options one at a time; `finish()` reads them one at a time.

- `start`: 51 queries (49 are the same `SELECT autoload ...` shape). `finish`: 153 queries (51 `SELECT option_value`, 50 `SELECT autoload`, 50 `DELETE`).
- Cost today: 3 to 20 ms per step, so it is the N+1 rule that flags it, not a user-visible delay. It grows with the number of checks, and with a persistent object cache
  each `delete_option` also touches the cache.
- Suggested fix: keep one option per check (that is what makes parallel requests safe) but read and delete them with one query each over the common prefix:
  `SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'store\_audit\_run\_result\_%'` and one matching `DELETE`, then clear the option cache for those names.
  `option_name` has a unique index, so the prefix match is a range read of about 50 rows.

### 3. Negative stock check: one `SELECT *` per product name (performance, small)

`includes/Checks/WooCommerce/NegativeStock.php` line 165: `get_the_title( $post_id )` in a loop over up to 20 ids runs `SELECT * FROM wp_posts WHERE ID = N LIMIT 1` 20 times (about 2 ms).
Fix: call `_prime_post_caches( $ids, false, false )` once before the loop, or fetch `ID, post_title` for all ids in one query.

### 4. Order count runs in two checks (performance, grows with orders)

`includes/Support/OrderStats.php`, `count()`: `SELECT COUNT(*) FROM wp_wc_orders WHERE type = 'shop_order'` (5.2 ms, HPOS on; 4.8 ms for the posts version, HPOS off).
It is a covering-index scan, correct and not a full table scan, but it reads every order entry and both `environment_object_cache` and `performance_orders_storage` run it in their own request.
It is linear in orders: at 50,000 orders it is 5 ms; at a million it would be on the order of 100 ms (extrapolation, not measured). If it ever matters, keep the number in a short transient
shared by the two checks.

### Seen, no change suggested

- `performance_expired_transients` is flagged as a full scan: `SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '\_transient\_timeout\_%' AND CAST(option_value AS UNSIGNED) < now`
  uses `type=ALL` on 54,000 to 57,000 rows (9.5 ms). The optimizer prefers a scan here because the prefix matches about 45% of the table in this data set (25,000 pairs).
  On a typical store, where transient rows are a small share of `wp_options`, the prefix index is used. It would only matter on an options table of several hundred thousand rows.
- `performance_images`: `ORDER BY p.ID DESC LIMIT 50` over attachments walks the primary key backwards (`type=index`, 9 to 10 ms). Here the newest posts are attachments, so it stops early. On a legacy-storage
  shop whose newest posts are orders it has to walk past them first; I did not construct that case, so no number.
- Slowest steps are the ones that go outside the database: `plugins_abandoned` (about 2 s, a wordpress.org lookup per plugin, cached for 12 hours), `security_exposed_dotfiles` (HTTP probes of the site, 1.4 to 2 s)
  and `security_backups_in_webroot` (1.6 to 1.8 s; it walks 100,000 files inside its own entry and 3 s budgets and adds 4.9 MB, the largest memory use of any check).
  With the network down the worst step took 6.6 s, so the 5 s per-request timeout keeps every step far from the 30 s limit.
- HPOS on and off give the same picture; the difference between the two storage modes is under a few milliseconds in every check.

### Memory

WordPress with WooCommerce and the admin includes uses about 74 MB (79 to 81 MB allocated) before the plugin does anything. Every step then adds between 0 and 5 MB.
So at a 128M limit about 45 MB of headroom remains for other plugins. A store whose own stack already needs more than about 120 MB to load the admin would fail under any plugin.
That is outside what this plugin controls.

## What this does not cover

- Only WooCommerce and this plugin are active. A real shop with 30 plugins has a bigger boot (more memory, more time) and more plugins to inventory, update-check and probe.
- PHP's opcache is off in this harness (the command-line default), so times are conservative; memory is not affected by it.
- No persistent object cache. With one, the option-heavy parts (finish, start) get cheaper.
- The order analytics tables and the Action Scheduler tables are empty.
- The admin page itself (rendering the Store Audit screen) and the dashboard widget are not measured; this run covers the AJAX and export requests.
- One run per step, with a second run for the query analysis. Differences of a few milliseconds are noise.

## Reproduce

```bash
bash bin/perf.sh                 # build the zip, generate the data set (about 5 minutes, then cached), measure, rewrite the results block above
bash bin/perf.sh --reuse         # never generate: restore the cached data set and measure (about 5 minutes)
bash bin/perf.sh --slow-network  # add the unreachable-network pass (about 1 more minute)
bash bin/perf.sh --products=1000 --orders=5000 --no-explain   # a small, quick data set for development; leaves this file alone
```

Needs Docker, the local lab network (`wplab_default` with the `wplab-db-1` MySQL container), python3 and rsync. Everything lives in `$HOME/.cache/store-audit-perf`
(WordPress, the WooCommerce zip is shared with the compatibility matrix cache, the two databases `sa_perf` and `sa_perf_legacy`, and a dump of each).
The script exits non-zero when any step times out, runs out of memory, fails, takes longer than 30 s or peaks above 128M.
The detector was checked by forcing a memory error and an endless loop: both are reported as `memory limit` and `timeout`.

Files: `bin/perf.sh` (entry point), `bin/perf-cell.sh` (inside the container), `bin/perf-gen.php` (data set), `bin/perf-run.php` (one step in one process),
`bin/perf-drive.py` (the step list, limits and verdicts), `bin/perf-report.py` (this file's results block). None of them ships in the plugin zip.
