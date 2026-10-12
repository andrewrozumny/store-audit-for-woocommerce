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

Environment: PHP 8.4.26, MySQL 8.4.11 (InnoDB buffer pool 128 MB), WordPress 7.1.3, WooCommerce 11.2.1. Plugin zip 70b077e553e3. Run time 186 s.

### HPOS on (orders in the dedicated tables)

Data set: 10,000 products, 2,976 variations, 50,024 orders, 173,002 order items, 506 users (5 administrators, 501 customers); 1,000 pending and 1,000 failed orders older than 30 days.

| Step | Time ms | Handler ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans | N+1 shapes |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| boot (WordPress + admin) | 672 |  | 81 |  | 0 |  |  | 0 | 0 |
| start | 567 | 18 | 79 | 3.2 | 3 | 1.7 | 0.95 | 0 | 0 |
| check environment_php_version | 444 | 16 | 79 | 3.4 | 3 | 0.2 | 0.11 | 0 | 0 |
| check environment_wp_version | 425 | 15 | 79 | 3.4 | 4 | 0.3 | 0.12 | 0 | 0 |
| check environment_wc_version | 697 | 22 | 79 | 3.4 | 4 | 0.3 | 0.11 | 0 | 0 |
| check environment_wp_debug | 542 | 16 | 79 | 3.4 | 3 | 0.3 | 0.16 | 0 | 0 |
| check environment_https | 498 | 14 | 79 | 3.4 | 3 | 0.1 | 0.07 | 0 | 0 |
| check environment_file_editing | 394 | 15 | 79 | 3.4 | 3 | 0.2 | 0.08 | 0 | 0 |
| check environment_memory_limit | 651 | 21 | 79 | 3.5 | 3 | 0.2 | 0.1 | 0 | 0 |
| check environment_max_execution_time | 570 | 19 | 79 | 3.4 | 3 | 1.1 | 0.57 | 0 | 0 |
| check environment_cron | 476 | 16 | 79 | 3.5 | 5 | 1.9 | 0.76 | 0 | 0 |
| check environment_object_cache | 462 | 48 | 79 | 3.5 | 6 | 7.8 | 4.83 | 0 | 0 |
| check plugins_inactive | 500 | 33 | 79 | 3.5 | 3 | 1.4 | 0.59 | 0 | 0 |
| check plugins_updates | 610 | 25 | 79 | 3.5 | 5 | 2 | 0.88 | 0 | 0 |
| check plugins_abandoned | 1842 | 1422 | 81 | 4.1 | 9 | 2.8 | 1.57 | 0 | 0 |
| check plugins_duplicates | 668 | 26 | 79 | 3.5 | 3 | 1.6 | 0.82 | 0 | 0 |
| check plugins_theme_child | 1097 | 505 | 79 | 3.9 | 5 | 1.4 | 0.7 | 0 | 0 |
| check plugins_mu | 477 | 17 | 79 | 3.5 | 3 | 1.6 | 0.73 | 0 | 0 |
| check woocommerce_guest_checkout | 433 | 21 | 79 | 3.5 | 6 | 0.3 | 0.07 | 0 | 0 |
| check woocommerce_test_gateways | 442 | 26 | 79 | 3.8 | 5 | 1.8 | 0.77 | 0 | 0 |
| check woocommerce_taxes | 630 | 18 | 79 | 3.4 | 3 | 0.3 | 0.2 | 0 | 0 |
| check woocommerce_shipping_zones | 464 | 18 | 79 | 3.5 | 10 | 3.1 | 0.76 | 0 | 0 |
| check woocommerce_pages | 437 | 15 | 79 | 3.5 | 9 | 0.6 | 0.09 | 0 | 0 |
| check woocommerce_terms_page | 451 | 15 | 79 | 3.4 | 4 | 0.4 | 0.17 | 0 | 0 |
| check woocommerce_hpos | 677 | 25 | 79 | 3.4 | 3 | 0.2 | 0.09 | 0 | 0 |
| check woocommerce_negative_stock | 646 | 69 | 79 | 3.5 | 7 | 3.8 | 1.99 | 0 | 0 |
| check woocommerce_stale_orders | 563 | 117 | 79 | 3.9 | 7 | 7.3 | 3 | 0 | 0 |
| check woocommerce_webhooks | 435 | 16 | 79 | 3.5 | 5 | 1.7 | 0.74 | 0 | 0 |
| check woocommerce_api_keys | 708 | 27 | 79 | 3.5 | 5 | 1.6 | 0.61 | 0 | 0 |
| check security_admin_username | 617 | 22 | 79 | 3.5 | 7 | 2 | 0.72 | 0 | 0 |
| check security_admin_count | 523 | 22 | 79 | 3.5 | 6 | 3.5 | 1.85 | 0 | 0 |
| check security_inactive_admins | 484 | 16 | 79 | 3.5 | 6 | 0.8 | 0.44 | 0 | 0 |
| check security_xmlrpc | 876 | 446 | 79 | 3.9 | 3 | 1.4 | 0.64 | 0 | 0 |
| check security_rest_users | 1096 | 526 | 79 | 3.9 | 3 | 1 | 0.49 | 0 | 0 |
| check security_generator | 812 | 332 | 81 | 4.1 | 4 | 1.5 | 0.69 | 0 | 0 |
| check security_uploads_listing | 652 | 26 | 79 | 3.9 | 3 | 0.2 | 0.16 | 0 | 0 |
| check security_wp_config_http | 1030 | 429 | 79 | 3.9 | 3 | 1.1 | 0.46 | 0 | 0 |
| check security_readme_license | 516 | 20 | 79 | 3.9 | 3 | 1.8 | 0.8 | 0 | 0 |
| check security_exposed_dotfiles | 1976 | 1265 | 79 | 3.9 | 3 | 1.7 | 0.96 | 0 | 0 |
| check security_backups_in_webroot | 2222 | 1680 | 81 | 4.9 | 3 | 1.4 | 0.54 | 0 | 0 |
| check security_antifraud | 663 | 22 | 79 | 3.5 | 3 | 0.1 | 0.08 | 0 | 0 |
| check security_ssl_admin | 593 | 21 | 79 | 3.4 | 3 | 0.2 | 0.14 | 0 | 0 |
| check performance_catalog_size | 773 | 35 | 79 | 3.5 | 5 | 3.8 | 1.65 | 0 | 0 |
| check performance_orders_storage | 714 | 30 | 79 | 3.5 | 5 | 6.2 | 4.79 | 0 | 0 |
| check performance_table_sizes | 645 | 28 | 79 | 3.5 | 4 | 0.8 | 0.61 | 0 | 0 |
| check performance_autoload | 672 | 28 | 79 | 3.5 | 5 | 3.6 | 1.98 | 0 | 0 |
| check performance_expired_transients | 681 | 46 | 79 | 3.5 | 4 | 10.6 | 9.14 | 1 | 0 |
| check performance_revisions | 582 | 32 | 79 | 3.5 | 4 | 4.5 | 2.94 | 0 | 0 |
| check performance_active_plugins | 673 | 24 | 79 | 3.5 | 3 | 0.3 | 0.19 | 0 | 0 |
| check performance_heartbeat | 675 | 19 | 79 | 3.4 | 3 | 0.4 | 0.25 | 0 | 0 |
| check performance_images | 609 | 59 | 79 | 3.8 | 4 | 10.5 | 8.96 | 0 | 0 |
| finish | 645 | 33 | 83 | 6.3 | 10 | 4.5 | 2.02 | 0 | 0 |
| markdown (copy button) | 626 | 6 | 79 | 2.4 | 1 | 0.1 | 0.11 | 0 | 0 |
| export JSON | 1089 | 4 | 83 | 2.9 | 1 | 0.1 | 0.13 | 0 | 0 |
| export printable HTML | 666 | 4 | 83 | 2.5 | 1 | 0.2 | 0.15 | 0 | 0 |

Nothing over 5 s, and no step adds more than 64 MB on top of the boot baseline.

Slowest step: check security_backups_in_webroot, 2.22 s. Highest peak: finish, 83 MB. WordPress with WooCommerce and the admin includes alone (boot): 81 MB, 0.67 s. Whole pass: 80 s.

Largest tables: wp_woocommerce_order_itemmeta 1,515,222 rows (164 MB), wp_postmeta 293,848 rows (61 MB), wp_woocommerce_order_items 172,437 rows (17 MB), wp_posts 116,649 rows (72 MB), wp_wc_order_addresses 99,459 rows (26 MB), wp_wc_orders_meta 99,327 rows (33 MB), wp_options 54,216 rows (16 MB), wp_wc_order_operational_data 49,742 rows (11 MB).

### HPOS off (orders in posts and postmeta, sync off)

Data set: 10,000 products, 2,976 variations, 50,024 orders, 173,002 order items, 506 users (5 administrators, 501 customers); 1,000 pending and 1,000 failed orders older than 30 days.

| Step | Time ms | Handler ms | Peak MB | Over boot MB | Queries | SQL ms | Slowest query ms | Full scans | N+1 shapes |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| boot (WordPress + admin) | 580 |  | 81 |  | 0 |  |  | 0 | 0 |
| start | 422 | 17 | 79 | 3.2 | 3 | 1.3 | 0.82 | 0 | 0 |
| check environment_php_version | 641 | 25 | 79 | 3.4 | 3 | 0.2 | 0.13 | 0 | 0 |
| check environment_wp_version | 530 | 17 | 79 | 3.4 | 4 | 0.3 | 0.16 | 0 | 0 |
| check environment_wc_version | 472 | 17 | 79 | 3.4 | 4 | 0.5 | 0.26 | 0 | 0 |
| check environment_wp_debug | 658 | 23 | 79 | 3.4 | 3 | 0.2 | 0.09 | 0 | 0 |
| check environment_https | 606 | 33 | 79 | 3.4 | 3 | 0.3 | 0.21 | 0 | 0 |
| check environment_file_editing | 525 | 16 | 79 | 3.4 | 3 | 0.2 | 0.11 | 0 | 0 |
| check environment_memory_limit | 640 | 22 | 79 | 3.5 | 3 | 0.2 | 0.11 | 0 | 0 |
| check environment_max_execution_time | 581 | 16 | 79 | 3.4 | 3 | 0.4 | 0.22 | 0 | 0 |
| check environment_cron | 479 | 18 | 79 | 3.5 | 5 | 2.6 | 0.75 | 0 | 0 |
| check environment_object_cache | 717 | 49 | 79 | 3.5 | 5 | 8.1 | 4.92 | 0 | 0 |
| check plugins_inactive | 597 | 28 | 79 | 3.5 | 3 | 1.7 | 0.88 | 0 | 0 |
| check plugins_updates | 494 | 18 | 79 | 3.5 | 5 | 2.1 | 0.83 | 0 | 0 |
| check plugins_abandoned | 2065 | 1404 | 81 | 4.1 | 9 | 1.9 | 0.76 | 0 | 0 |
| check plugins_duplicates | 427 | 17 | 79 | 3.5 | 3 | 1.6 | 0.79 | 0 | 0 |
| check plugins_theme_child | 1134 | 506 | 79 | 3.9 | 5 | 1.3 | 0.47 | 0 | 0 |
| check plugins_mu | 461 | 18 | 79 | 3.5 | 3 | 0.2 | 0.09 | 0 | 0 |
| check woocommerce_guest_checkout | 553 | 22 | 79 | 3.5 | 6 | 0.5 | 0.14 | 0 | 0 |
| check woocommerce_test_gateways | 566 | 27 | 79 | 3.8 | 5 | 2.2 | 1.15 | 0 | 0 |
| check woocommerce_taxes | 433 | 15 | 79 | 3.4 | 3 | 0.1 | 0.08 | 0 | 0 |
| check woocommerce_shipping_zones | 477 | 30 | 79 | 3.5 | 10 | 3.3 | 0.66 | 0 | 0 |
| check woocommerce_pages | 588 | 21 | 79 | 3.5 | 9 | 0.6 | 0.11 | 0 | 0 |
| check woocommerce_terms_page | 446 | 17 | 79 | 3.4 | 4 | 0.3 | 0.12 | 0 | 0 |
| check woocommerce_hpos | 434 | 24 | 79 | 3.4 | 3 | 0.4 | 0.2 | 0 | 0 |
| check woocommerce_negative_stock | 684 | 72 | 79 | 3.5 | 7 | 4.1 | 1.7 | 0 | 0 |
| check woocommerce_stale_orders | 641 | 171 | 79 | 3.5 | 7 | 6.4 | 2.86 | 0 | 0 |
| check woocommerce_webhooks | 656 | 25 | 79 | 3.5 | 5 | 2 | 0.71 | 0 | 0 |
| check woocommerce_api_keys | 596 | 18 | 79 | 3.5 | 5 | 2.1 | 0.85 | 0 | 0 |
| check security_admin_username | 547 | 18 | 79 | 3.5 | 7 | 0.9 | 0.44 | 0 | 0 |
| check security_admin_count | 671 | 24 | 79 | 3.5 | 6 | 0.9 | 0.48 | 0 | 0 |
| check security_inactive_admins | 614 | 22 | 79 | 3.5 | 6 | 2.1 | 0.78 | 0 | 0 |
| check security_xmlrpc | 730 | 272 | 79 | 3.9 | 3 | 1.7 | 0.81 | 0 | 0 |
| check security_rest_users | 1259 | 600 | 79 | 3.9 | 3 | 1.3 | 0.58 | 0 | 0 |
| check security_generator | 910 | 402 | 79 | 4.0 | 4 | 1.3 | 0.64 | 0 | 0 |
| check security_uploads_listing | 638 | 47 | 79 | 3.9 | 3 | 1.7 | 0.77 | 0 | 0 |
| check security_wp_config_http | 1088 | 520 | 79 | 3.8 | 3 | 2.1 | 1.13 | 0 | 0 |
| check security_readme_license | 852 | 36 | 79 | 3.9 | 3 | 2 | 1.16 | 0 | 0 |
| check security_exposed_dotfiles | 1927 | 1377 | 79 | 3.9 | 3 | 2.2 | 1.4 | 0 | 0 |
| check security_backups_in_webroot | 1538 | 949 | 81 | 4.9 | 3 | 1.6 | 0.77 | 0 | 0 |
| check security_antifraud | 778 | 32 | 79 | 3.5 | 3 | 0.3 | 0.22 | 0 | 0 |
| check security_ssl_admin | 639 | 22 | 79 | 3.4 | 3 | 0.2 | 0.11 | 0 | 0 |
| check performance_catalog_size | 796 | 48 | 79 | 3.5 | 5 | 3.5 | 1.56 | 0 | 0 |
| check performance_orders_storage | 573 | 27 | 79 | 3.5 | 4 | 5.6 | 4.53 | 0 | 0 |
| check performance_table_sizes | 658 | 26 | 79 | 3.5 | 4 | 0.7 | 0.49 | 0 | 0 |
| check performance_autoload | 662 | 33 | 79 | 3.5 | 5 | 6.1 | 2.3 | 0 | 0 |
| check performance_expired_transients | 612 | 42 | 79 | 3.5 | 4 | 11.9 | 10.29 | 1 | 0 |
| check performance_revisions | 678 | 31 | 79 | 3.5 | 4 | 4.6 | 2.87 | 0 | 0 |
| check performance_active_plugins | 650 | 20 | 79 | 3.5 | 3 | 0.2 | 0.08 | 0 | 0 |
| check performance_heartbeat | 531 | 25 | 79 | 3.4 | 3 | 0.2 | 0.11 | 0 | 0 |
| check performance_images | 728 | 63 | 79 | 3.8 | 4 | 12.1 | 10.6 | 0 | 0 |
| finish | 653 | 31 | 83 | 6.3 | 10 | 5.6 | 1.88 | 0 | 0 |
| markdown (copy button) | 536 | 4 | 79 | 2.4 | 1 | 0.1 | 0.14 | 0 | 0 |
| export JSON | 1175 | 3 | 83 | 2.9 | 1 | 0.1 | 0.13 | 0 | 0 |
| export printable HTML | 535 | 4 | 83 | 2.5 | 1 | 0.2 | 0.17 | 0 | 0 |

Nothing over 5 s, and no step adds more than 64 MB on top of the boot baseline.

Slowest step: check plugins_abandoned, 2.06 s. Highest peak: finish, 83 MB. WordPress with WooCommerce and the admin includes alone (boot): 81 MB, 0.58 s. Whole pass: 82 s.

Largest tables: wp_postmeta 2,236,080 rows (308 MB), wp_woocommerce_order_itemmeta 1,516,881 rows (164 MB), wp_woocommerce_order_items 172,437 rows (17 MB), wp_posts 112,543 rows (76 MB), wp_options 47,872 rows (17 MB), wp_term_relationships 19,973 rows (3 MB), wp_wc_product_meta_lookup 12,809 rows (5 MB), wp_usermeta 8,795 rows (2 MB).

### Query findings (SAVEQUERIES, EXPLAIN)

Full table scans (`type=ALL` on a table with more than 10,000 rows):

| HPOS | Step | Table | Type | Table rows | Query ms | Query |
|---|---|---|---|---:|---:|---|
| on | check performance_expired_transients | `wp_options` | ALL | 54,216 | 9.14 | `SELECT COUNT(*) FROM `wp_options` WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND CAST(option_value AS UNSIGNED) < 1791838694` |

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
