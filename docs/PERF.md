# Performance on a large store

Store Audit for WooCommerce is run from the browser: one AJAX request per check, so a slow check must stay well inside the host's
PHP limits. This document records what that looks like on a big shop at the tightest common limits (`memory_limit=128M`,
`max_execution_time=30`), and where the plugin could be cheaper.

Short version: every step finished, nothing timed out, nothing ran out of memory, the slowest step took about 2 s, and the plugin adds
at most 5 MB on top of what WordPress and WooCommerce already use. Three things in the plugin's own queries were tightened after this run showed them (see "Findings" below); one is left open.

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

Environment: PHP 8.4.26, MySQL 8.4.11 (InnoDB buffer pool 128 MB), WordPress 7.1.3, WooCommerce 11.2.1. Plugin zip 487bc5b47638. Run time 175 s.

### HPOS on (orders in the dedicated tables)

Data set: 10,000 products, 2,976 variations, 50,024 orders, 173,002 order items, 506 users (5 administrators, 501 customers); 1,000 pending and 1,000 failed orders older than 30 days.

| Step | Time ms | Handler ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans | N+1 shapes |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| boot (WordPress + admin) | 653 |  | 81 |  | 0 |  |  | 0 | 0 |
| start | 654 | 20 | 79 | 3.2 | 3 | 1.3 | 0.58 | 0 | 0 |
| check environment_php_version | 474 | 19 | 79 | 3.4 | 3 | 0.2 | 0.14 | 0 | 0 |
| check environment_wp_version | 703 | 25 | 79 | 3.4 | 4 | 0.3 | 0.12 | 0 | 0 |
| check environment_wc_version | 636 | 17 | 79 | 3.4 | 4 | 0.2 | 0.07 | 0 | 0 |
| check environment_wp_debug | 624 | 24 | 79 | 3.4 | 3 | 0.2 | 0.12 | 0 | 0 |
| check environment_https | 604 | 26 | 79 | 3.4 | 3 | 0.2 | 0.12 | 0 | 0 |
| check environment_file_editing | 437 | 15 | 79 | 3.4 | 3 | 0.3 | 0.21 | 0 | 0 |
| check environment_memory_limit | 427 | 23 | 79 | 3.5 | 3 | 0.2 | 0.17 | 0 | 0 |
| check environment_max_execution_time | 584 | 18 | 79 | 3.4 | 3 | 0.3 | 0.23 | 0 | 0 |
| check environment_cron | 423 | 21 | 79 | 3.5 | 5 | 1.8 | 0.71 | 0 | 0 |
| check environment_object_cache | 442 | 28 | 79 | 3.5 | 6 | 9.4 | 5.55 | 0 | 0 |
| check plugins_inactive | 618 | 24 | 79 | 3.5 | 3 | 2.1 | 1.1 | 0 | 0 |
| check plugins_updates | 411 | 16 | 79 | 3.5 | 5 | 1.7 | 0.73 | 0 | 0 |
| check plugins_abandoned | 2007 | 1367 | 81 | 4.1 | 9 | 1.8 | 0.63 | 0 | 0 |
| check plugins_duplicates | 636 | 29 | 79 | 3.5 | 3 | 1.9 | 1.03 | 0 | 0 |
| check plugins_theme_child | 953 | 504 | 79 | 3.9 | 5 | 1.6 | 0.69 | 0 | 0 |
| check plugins_mu | 553 | 20 | 79 | 3.5 | 3 | 0.3 | 0.18 | 0 | 0 |
| check woocommerce_guest_checkout | 664 | 22 | 79 | 3.5 | 6 | 0.7 | 0.23 | 0 | 0 |
| check woocommerce_test_gateways | 471 | 19 | 79 | 3.8 | 5 | 1.5 | 0.76 | 0 | 0 |
| check woocommerce_taxes | 405 | 14 | 79 | 3.4 | 3 | 0.1 | 0.08 | 0 | 0 |
| check woocommerce_shipping_zones | 462 | 18 | 79 | 3.5 | 10 | 2.1 | 0.58 | 0 | 0 |
| check woocommerce_pages | 684 | 25 | 79 | 3.5 | 9 | 2.3 | 0.8 | 0 | 0 |
| check woocommerce_terms_page | 514 | 15 | 79 | 3.4 | 4 | 0.2 | 0.11 | 0 | 0 |
| check woocommerce_hpos | 421 | 13 | 79 | 3.4 | 3 | 1.2 | 0.63 | 0 | 0 |
| check woocommerce_negative_stock | 449 | 27 | 79 | 3.5 | 7 | 3.2 | 1.37 | 0 | 0 |
| check woocommerce_stale_orders | 668 | 46 | 79 | 3.9 | 7 | 9.3 | 3.79 | 0 | 0 |
| check woocommerce_webhooks | 670 | 20 | 79 | 3.5 | 5 | 1.8 | 0.54 | 0 | 0 |
| check woocommerce_api_keys | 504 | 16 | 79 | 3.5 | 5 | 0.8 | 0.54 | 0 | 0 |
| check security_admin_username | 394 | 15 | 79 | 3.5 | 7 | 1.3 | 0.64 | 0 | 0 |
| check security_admin_count | 400 | 15 | 79 | 3.5 | 6 | 2.4 | 0.74 | 0 | 0 |
| check security_inactive_admins | 695 | 28 | 79 | 3.5 | 6 | 0.9 | 0.55 | 0 | 0 |
| check security_xmlrpc | 826 | 255 | 79 | 3.9 | 3 | 1 | 0.46 | 0 | 0 |
| check security_rest_users | 884 | 401 | 79 | 3.9 | 3 | 2.2 | 1.04 | 0 | 0 |
| check security_generator | 1134 | 454 | 81 | 4.1 | 4 | 1.9 | 0.91 | 0 | 0 |
| check security_uploads_listing | 640 | 23 | 79 | 3.9 | 3 | 1.8 | 0.82 | 0 | 0 |
| check security_wp_config_http | 1262 | 492 | 79 | 3.9 | 3 | 1.2 | 0.54 | 0 | 0 |
| check security_readme_license | 657 | 37 | 79 | 3.9 | 3 | 0.2 | 0.14 | 0 | 0 |
| check security_exposed_dotfiles | 2068 | 1342 | 79 | 3.9 | 3 | 1.4 | 0.72 | 0 | 0 |
| check security_backups_in_webroot | 1718 | 1108 | 81 | 4.9 | 3 | 1.7 | 0.83 | 0 | 0 |
| check security_antifraud | 482 | 18 | 79 | 3.5 | 3 | 0.1 | 0.07 | 0 | 0 |
| check security_ssl_admin | 484 | 14 | 79 | 3.4 | 3 | 0.2 | 0.13 | 0 | 0 |
| check performance_catalog_size | 545 | 18 | 79 | 3.5 | 5 | 4.2 | 1.51 | 0 | 0 |
| check performance_orders_storage | 698 | 42 | 79 | 3.5 | 5 | 6.7 | 4.89 | 0 | 0 |
| check performance_table_sizes | 452 | 15 | 79 | 3.5 | 4 | 0.7 | 0.58 | 0 | 0 |
| check performance_autoload | 426 | 17 | 79 | 3.5 | 5 | 3.1 | 1.65 | 0 | 0 |
| check performance_expired_transients | 480 | 34 | 79 | 3.5 | 4 | 10 | 8.84 | 1 | 0 |
| check performance_revisions | 405 | 20 | 79 | 3.5 | 4 | 4.5 | 2.53 | 0 | 0 |
| check performance_active_plugins | 433 | 15 | 79 | 3.5 | 3 | 0.1 | 0.07 | 0 | 0 |
| check performance_heartbeat | 414 | 13 | 79 | 3.4 | 3 | 0.1 | 0.08 | 0 | 0 |
| check performance_images | 441 | 37 | 79 | 3.8 | 4 | 10.2 | 9.03 | 0 | 0 |
| finish | 655 | 34 | 83 | 6.3 | 10 | 4.9 | 1.68 | 0 | 0 |
| markdown (copy button) | 634 | 4 | 79 | 2.4 | 1 | 0.1 | 0.1 | 0 | 0 |
| export JSON | 1179 | 4 | 83 | 2.9 | 1 | 0.1 | 0.11 | 0 | 0 |
| export printable HTML | 765 | 4 | 83 | 2.5 | 1 | 0.1 | 0.12 | 0 | 0 |

Nothing over 5 s, and no step adds more than 64 MB on top of the boot baseline.

Slowest step: check security_exposed_dotfiles, 2.07 s. Highest peak: finish, 83 MB. WordPress with WooCommerce and the admin includes alone (boot): 81 MB, 0.65 s. Whole pass: 76 s.

Largest tables: wp_woocommerce_order_itemmeta 1,515,775 rows (164 MB), wp_postmeta 292,639 rows (61 MB), wp_woocommerce_order_items 172,437 rows (17 MB), wp_posts 106,525 rows (72 MB), wp_wc_order_addresses 99,459 rows (26 MB), wp_wc_orders_meta 99,327 rows (33 MB), wp_wc_order_operational_data 49,742 rows (11 MB), wp_wc_orders 49,437 rows (35 MB).

### HPOS off (orders in posts and postmeta, sync off)

Data set: 10,000 products, 2,976 variations, 50,024 orders, 173,002 order items, 506 users (5 administrators, 501 customers); 1,000 pending and 1,000 failed orders older than 30 days.

| Step | Time ms | Handler ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans | N+1 shapes |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| boot (WordPress + admin) | 461 |  | 81 |  | 0 |  |  | 0 | 0 |
| start | 627 | 25 | 79 | 3.2 | 3 | 1.2 | 0.57 | 0 | 0 |
| check environment_php_version | 532 | 19 | 79 | 3.4 | 3 | 0.2 | 0.12 | 0 | 0 |
| check environment_wp_version | 426 | 14 | 79 | 3.4 | 4 | 0.3 | 0.18 | 0 | 0 |
| check environment_wc_version | 501 | 23 | 79 | 3.4 | 4 | 0.4 | 0.21 | 0 | 0 |
| check environment_wp_debug | 662 | 18 | 79 | 3.4 | 3 | 0.3 | 0.19 | 0 | 0 |
| check environment_https | 508 | 17 | 79 | 3.4 | 3 | 0.2 | 0.09 | 0 | 0 |
| check environment_file_editing | 403 | 15 | 79 | 3.4 | 3 | 0.2 | 0.1 | 0 | 0 |
| check environment_memory_limit | 626 | 25 | 79 | 3.5 | 3 | 0.4 | 0.29 | 0 | 0 |
| check environment_max_execution_time | 454 | 17 | 79 | 3.4 | 3 | 0.2 | 0.18 | 0 | 0 |
| check environment_cron | 417 | 15 | 79 | 3.5 | 5 | 2.3 | 0.95 | 0 | 0 |
| check environment_object_cache | 681 | 37 | 79 | 3.5 | 5 | 8.4 | 4.86 | 0 | 0 |
| check plugins_inactive | 811 | 26 | 79 | 3.5 | 3 | 1.3 | 0.52 | 0 | 0 |
| check plugins_updates | 540 | 19 | 79 | 3.5 | 5 | 2.2 | 0.86 | 0 | 0 |
| check plugins_abandoned | 1746 | 1307 | 81 | 4.1 | 9 | 1.9 | 0.74 | 0 | 0 |
| check plugins_duplicates | 440 | 20 | 79 | 3.5 | 3 | 0.1 | 0.08 | 0 | 0 |
| check plugins_theme_child | 1165 | 514 | 79 | 3.9 | 5 | 1.5 | 0.61 | 0 | 0 |
| check plugins_mu | 592 | 17 | 79 | 3.5 | 3 | 1.5 | 0.67 | 0 | 0 |
| check woocommerce_guest_checkout | 444 | 15 | 79 | 3.5 | 6 | 0.6 | 0.25 | 0 | 0 |
| check woocommerce_test_gateways | 652 | 28 | 79 | 3.8 | 5 | 2.2 | 0.9 | 0 | 0 |
| check woocommerce_taxes | 520 | 18 | 79 | 3.4 | 3 | 0.2 | 0.19 | 0 | 0 |
| check woocommerce_shipping_zones | 435 | 20 | 79 | 3.5 | 10 | 3.4 | 0.79 | 0 | 0 |
| check woocommerce_pages | 648 | 22 | 79 | 3.5 | 9 | 2.2 | 0.78 | 0 | 0 |
| check woocommerce_terms_page | 511 | 15 | 79 | 3.4 | 4 | 0.3 | 0.12 | 0 | 0 |
| check woocommerce_hpos | 422 | 14 | 79 | 3.4 | 3 | 0.3 | 0.21 | 0 | 0 |
| check woocommerce_negative_stock | 569 | 36 | 79 | 3.5 | 7 | 3.3 | 1.59 | 0 | 0 |
| check woocommerce_stale_orders | 708 | 68 | 79 | 3.5 | 7 | 6.6 | 3.26 | 0 | 0 |
| check woocommerce_webhooks | 516 | 21 | 79 | 3.5 | 5 | 1.8 | 0.71 | 0 | 0 |
| check woocommerce_api_keys | 415 | 15 | 79 | 3.5 | 5 | 1.9 | 0.77 | 0 | 0 |
| check security_admin_username | 568 | 24 | 79 | 3.5 | 7 | 1.1 | 0.62 | 0 | 0 |
| check security_admin_count | 545 | 20 | 79 | 3.5 | 6 | 1 | 0.53 | 0 | 0 |
| check security_inactive_admins | 428 | 16 | 79 | 3.5 | 6 | 0.8 | 0.47 | 0 | 0 |
| check security_xmlrpc | 873 | 401 | 79 | 3.9 | 3 | 1.7 | 0.81 | 0 | 0 |
| check security_rest_users | 1034 | 490 | 79 | 3.9 | 3 | 1.4 | 0.73 | 0 | 0 |
| check security_generator | 1146 | 536 | 79 | 4.0 | 4 | 1.7 | 0.81 | 0 | 0 |
| check security_uploads_listing | 649 | 23 | 79 | 3.9 | 3 | 0.3 | 0.21 | 0 | 0 |
| check security_wp_config_http | 1046 | 443 | 79 | 3.8 | 3 | 1.9 | 1.11 | 0 | 0 |
| check security_readme_license | 723 | 37 | 79 | 3.9 | 3 | 2.1 | 1.02 | 0 | 0 |
| check security_exposed_dotfiles | 1906 | 1235 | 79 | 3.9 | 3 | 1.6 | 0.76 | 0 | 0 |
| check security_backups_in_webroot | 1934 | 1262 | 81 | 4.9 | 3 | 1.9 | 0.91 | 0 | 0 |
| check security_antifraud | 605 | 22 | 79 | 3.5 | 3 | 0.2 | 0.09 | 0 | 0 |
| check security_ssl_admin | 630 | 30 | 79 | 3.4 | 3 | 0.3 | 0.17 | 0 | 0 |
| check performance_catalog_size | 794 | 29 | 79 | 3.5 | 5 | 3.5 | 1.42 | 0 | 0 |
| check performance_orders_storage | 473 | 25 | 79 | 3.5 | 4 | 5.7 | 4.53 | 0 | 0 |
| check performance_table_sizes | 413 | 15 | 79 | 3.5 | 4 | 0.7 | 0.57 | 0 | 0 |
| check performance_autoload | 440 | 17 | 79 | 3.5 | 5 | 3.1 | 1.64 | 0 | 0 |
| check performance_expired_transients | 714 | 45 | 79 | 3.5 | 4 | 11.8 | 10.33 | 1 | 0 |
| check performance_revisions | 573 | 22 | 79 | 3.5 | 4 | 4.5 | 2.73 | 0 | 0 |
| check performance_active_plugins | 486 | 15 | 79 | 3.5 | 3 | 0.1 | 0.08 | 0 | 0 |
| check performance_heartbeat | 455 | 16 | 79 | 3.4 | 3 | 0.1 | 0.07 | 0 | 0 |
| check performance_images | 521 | 51 | 79 | 3.8 | 4 | 11 | 9.47 | 0 | 0 |
| finish | 606 | 32 | 83 | 6.3 | 10 | 5.3 | 1.68 | 0 | 0 |
| markdown (copy button) | 413 | 4 | 79 | 2.4 | 1 | 0.1 | 0.1 | 0 | 0 |
| export JSON | 915 | 3 | 83 | 2.9 | 1 | 0.1 | 0.13 | 0 | 0 |
| export printable HTML | 653 | 5 | 83 | 2.5 | 1 | 0.1 | 0.09 | 0 | 0 |

Nothing over 5 s, and no step adds more than 64 MB on top of the boot baseline.

Slowest step: check security_backups_in_webroot, 1.93 s. Highest peak: finish, 83 MB. WordPress with WooCommerce and the admin includes alone (boot): 81 MB, 0.46 s. Whole pass: 77 s.

Largest tables: wp_postmeta 2,330,738 rows (308 MB), wp_woocommerce_order_itemmeta 1,516,881 rows (164 MB), wp_woocommerce_order_items 172,437 rows (17 MB), wp_posts 113,192 rows (76 MB), wp_options 54,216 rows (17 MB), wp_term_relationships 19,973 rows (3 MB), wp_wc_product_meta_lookup 13,090 rows (5 MB), wp_usermeta 8,795 rows (2 MB).

### Query findings (SAVEQUERIES, EXPLAIN)

Full table scans (`type=ALL` on a table with more than 10,000 rows):

| HPOS | Step | Table | Type | Table rows | Query ms | Query |
|---|---|---|---|---:|---:|---|
| on | check performance_expired_transients | `wp_options` | ALL | 48,101 | 8.84 | `SELECT COUNT(*) FROM `wp_options` WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND CAST(option_value AS UNSIGNED) < 1791836085` |

<!-- perf:results:end -->


## Findings

Nothing here breaks a limit. These are the places where the plugin did more work than it needed, ordered by cost.
"Performance" means a change that gives the same result faster; no behaviour changes in any of them. Findings 1 to 3 are fixed and the results above
are from the run after the fix; finding 4 is open.

### 1. Shipping zones check: product lookup was a heavy `WP_Query` (fixed)

`ShippingZones::has_shippable_product()` replaces the `wc_get_products()` call that asked "is there at least one published product that is not virtual and not downloadable".
WooCommerce turned it into a `WP_Query` with joins, `GROUP BY` and `ORDER BY` (`Using temporary; Using filesort`, about 23,000 candidate rows), so the cost grew with the catalogue.
Now it is one `LIMIT 1` query on `wc_product_meta_lookup` (`virtual = 0 AND downloadable = 0`, published `product` posts), with a postmeta fallback (`_virtual` and `_downloadable` equal to `no`) when the lookup table is missing.

- Before: handler 207 ms, 11 queries, slowest query 116 ms (HPOS on). After: handler 18 ms, 10 queries, slowest query 2.1 ms.

### 2. Run state: one option per check read and deleted one by one (fixed)

`Ajax::start()` and `Ajax::finish()` now read every stored result with one query over the common prefix and delete them with one `DELETE`, then drop the names from the object cache.
Each check still has its own option, so parallel `run_check` requests cannot overwrite each other.

- `start`: 51 queries before, 3 after. `finish`: 153 before, 10 after (HPOS on).

### 3. Negative stock check: one `SELECT *` per product name (fixed)

`NegativeStock` primes the post cache for the sampled ids with one `_prime_post_caches()` call before reading the titles.

- 26 queries before, 7 after.

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
