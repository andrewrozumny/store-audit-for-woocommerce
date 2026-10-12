# Destructive testing

What was done to the plugin to try to break it, and what came out. Every scenario ran against a throwaway clone of
WordPress 7.1.3 with WooCommerce 11.2.1 (its own database, PHP 8.4, `memory_limit` 128M for the resource run). Nothing
ran against a real store. Version tested: current working tree on top of 0.1.0.

Result column: "Result = Expected" means the plugin behaved as expected the first time. "Defect found and fixed" means
a test that catches the problem was written first, seen failing, and the fix made it pass. Categories: security,
compatibility, performance, behavior.

## Results

| Scenario | Expected | Result | Test |
| --- | --- | --- | --- |
| 1.1 AJAX endpoints `store_audit_start`, `store_audit_run_check`, `store_audit_finish`, `store_audit_markdown`: guest, subscriber, shop manager, administrator; missing, empty, forged, over-long, array, other-action, other-user and expired nonces | Guest 400, subscriber and shop manager 403, every bad nonce 403, administrator works; no change to `store_audit_*` rows; no PHP errors in `debug.log` | Defect found and fixed (behavior): a nonce sent as an array (`nonce[]=x`) made WordPress log "Array to string conversion". It is now refused with 403 before WordPress sees it. Everything else Result = Expected | `AjaxTest::test_array_nonce_is_rejected_before_core_sees_it`; live: `bin/destructive.sh run 1` (1.1) |
| 1.2 AJAX hostile parameters: XSS, SQL characters, `%`, `_`, backslash, unicode, emoji, right-to-left marks, null bytes, CRLF, path traversal, negative, fractional and huge numbers, empty, 1 MB strings, arrays instead of strings, extra and missing parameters, unknown check id, foreign or replaced run id, JSON body, unknown action | 409 for a bad run id, 404 for a bad check id, answers are JSON and never echo the input, no side effects, no PHP errors | Result = Expected (238 checks) | live: `bin/destructive.sh run 1` (1.2) |
| 1.3 `admin-post.php` `store_audit_download` and `store_audit_print`: guest, subscriber, shop manager, administrator, bad and array nonces, hostile query values, no report yet | Guest 400, subscriber, shop manager and bad nonce 403, no report 404, report 200; safe file name, `nosniff`, valid JSON, no query data in the output | Defect found and fixed (behavior): same array-nonce warning as 1.1, fixed the same way. Everything else Result = Expected | `ExportsTest::test_array_nonce_dies_before_core_sees_it`; live (1.3) |
| 1.4 Page `admin.php?page=store-audit` with hostile query strings and odd `page` values | 200 for administrator, 403 for shop manager and subscriber, redirect to login for guests; nothing reflected; no PHP errors | Result = Expected (181 checks) | live (1.4) |
| 1.5 `wp store-audit run --format=<hostile>` (tags, SQL, shell metacharacters, emoji, empty, 5000 characters, flag without value, extra arguments) | A clean error and a non-zero exit for every unknown format, nothing executed, no side effects | Result = Expected | live (1.5); manual: `wp store-audit run --format='<script>'` |
| 1.6 Site data as an XSS source: plugin, theme, mu-plugin, user, API key, webhook, shipping zone, product, page, option, cron hook and backup file names holding `<script>`, `"><img onerror>`, `"onmouseover=`, `</script><script>`, `javascript:`; report page, print view, dashboard widget, JSON download, Markdown, `admin.js` | Hostile text reaches at least 6 results and is shown as inert text everywhere; `admin.js` never writes HTML | Defect found and fixed (security, Markdown only): the Markdown export passed site text through unchanged, so tags, links and line breaks from a plugin or file name reached whatever renders the pasted Markdown. All text from the site is now one line, with `<`, `>`, `[` and `]` escaped. Also hardened (defense in depth, not exploitable before): the JSON printed inside the script element now escapes `<`, `>`, `&` and quotes. HTML outputs and `admin.js` were Result = Expected | `ReportExportTest::test_markdown_neutralises_site_text`, `PageTest::test_inline_config_cannot_close_the_script_element`; live (1.6) |
| 2.1 WooCommerce deactivated (also activating the plugin without it) | No fatal error anywhere; a notice is shown; no endpoints; reactivation restores everything | Result = Expected | live (2.1) |
| 2.2 WooCommerce 8.9.3, below the supported minimum | The audit completes and the WooCommerce version check reports the old version | Result = Expected (the check reports critical: "very old and no longer receives security fixes") | live (2.2) |
| 2.3 WooCommerce tables missing, one by one (orders, order meta, API keys, webhooks, Action Scheduler, product lookup, shipping zones and methods, tax rates, order items, order stats) and all 40 at once | The audit completes; checks that need a table say "not checked"; no database errors in the log | Defect found and fixed (compatibility): order storage, object cache, tax rates and shipping zones queried the missing table, so the log filled with "WordPress database error" lines and the result read as "0 orders / no tax rates". They now stop with "the database table wp_... does not exist" (shown as "not checked"). Everything else Result = Expected | `MissingTablesTest` (4 cases); live (2.3) |
| 2.4 Plugin options missing or damaged: `store_audit_last_report` as string, number, boolean, unknown class, broken serialisation, empty array, no `results`, `results` of the wrong type or empty, bogus or array severity, `details` of the wrong type, broken or missing score, unknown section, hostile strings in every field, 3000 rows; `store_audit_current_run` and result rows of the wrong type | Page, print view, download, Markdown, widget and the next audit work for every value; unusable values read as "no report" | Defect found and fixed (behavior): `Report::get_last()` accepted any array with a `results` key. A report with a wrong type crashed `admin.js` (the audit page stayed blank) and raised "Array to string conversion" in the print view and the Markdown. Stored reports are now repaired (unknown severity becomes "not checked", bad rows dropped, broken score recomputed) or ignored | `StoredReportTest` (16 cases); live (2.4) |
| 2.5 Uploads folder missing, a file in its place, read-only, unreadable | The audit completes; checks that need the folder say what they could not do; no PHP warnings | Defect found and fixed (behavior): with an unreadable folder the backups check raised two `scandir` warnings and, had it not, would have reported "no backups found". It now reports "some folders could not be read" (info). Found live with a mode 000 folder; the mode 000 case is covered by the unit test (changing the mode of a live folder is not reliable on the shared drive used here) | `BackupsInWebrootTest::test_unreadable_folder_is_not_a_clean_bill`; live (2.5, folder missing and read-only) |
| 2.6 `DISABLE_WP_CRON` and `WP_HTTP_BLOCK_EXTERNAL` | The audit completes; the affected checks explain; failed lookups are not cached | Result = Expected (cron check: info "WP-Cron is switched off ... set up a real server cron job"; abandoned plugins: info "could not be checked") | live (2.6) |
| 2.7 No internet; wordpress.org answering with an error, 429, 500, truncated JSON, HTML, empty body, a JSON string, `null`, or a 240 KB "closed" reason with a script tag | The plugin check says it could not check; nothing is cached for a failure; no fatal error | Defect found and fixed (performance): the reason text of a closed plugin was kept whole, so one answer produced a 720 KB report row and a 240 KB cached option. It is now one plain line of at most 300 characters. Everything else Result = Expected | `WporgApiTest::test_closed_reason_is_short_and_plain`; live (2.7) |
| 2.8 wordpress.org hangs (every request waits its full 5 s timeout) with 16 extra plugins | Every request ends inside `max_execution_time` (30 s); the slow check gives up and says so | Result = Expected: the abandoned-plugins check stops at its 15 s budget (15.3 s measured) and reports "not checked (time limit)"; the whole audit took 50 s in 49 requests | live (2.8) |
| 3.1 Two audits started in two tabs | The older run gets 409; the newer one completes; nothing leaks between them | Result = Expected | `RunStorageTest::test_new_run_replaces_the_old_one`; live (3.1) |
| 3.2 Parallel `run_check` requests of one run (5 at a time, all 49 checks) | Every result is kept | Defect found and fixed (behavior): all results were kept in one option that every request read, changed and wrote back, so overlapping requests overwrote each other (14 of 49 results survived on 0.1.0). Each check result now has its own option | `RunStorageTest::test_overlapping_run_check_requests_do_not_lose_results`; live (3.2) |
| 3.3 `finish` with an incomplete set of results, and with none | Missing checks are visible as "not checked" and stay out of the score; with none, the last good report is kept | Defect found and fixed (behavior): `finish` built the report from whatever had been saved, so a half-run looked like a complete audit, and a run with no results replaced the last report with an empty one (score 0). Missing checks are now listed as "not checked"; a run with no results answers 409 | `RunStorageTest::test_finish_marks_missing_checks_as_not_checked`, `test_finish_with_no_results_is_rejected`; live (3.3) |
| 3.4 The browser runner when one check request fails | The failed check appears once, as "not checked" | Defect found and fixed (behavior): the report and the failed row added by `admin.js` both listed the check (twice on screen). The script now adds only checks the server does not know about | live harness (3.4): `bin/destructive.sh run 3` |
| 3.5 Audit during deactivation | Refused cleanly (400) while inactive; the run continues after reactivation; a request already running when the plugin is deactivated still completes | Result = Expected | live (3.5) |
| 3.6 Deactivate and `wp plugin uninstall` (single site) | Every row of the plugin is gone: options, run rows, `_transient_store_audit_*` and `_transient_timeout_store_audit_*`; rows of other plugins, including look-alike names, stay | Defect found and fixed (behavior): `uninstall.php` deleted two options only, so the run rows and every cached wordpress.org answer (with its timeout row) stayed in the database for good. It now removes every row whose name starts with one of the plugin prefixes | `UninstallTest::test_single_site_removes_options_and_transients`; live (3.6) |
| 3.7 Uninstall on a multisite network (3 sites) | Rows of every site removed, foreign rows kept | Defect found and fixed (behavior): same defect as 3.6, and sites other than the main one were never visited. Every site is now cleaned | `UninstallTest::test_multisite_cleans_every_site`; live (3.7) |
| 4.1 Install 0.1.0 with a saved report, upgrade to the current build, run an audit, downgrade to 0.1.0, return | The report and data stay; page, widget, print, download and Markdown render; the downgrade does not fail; a run started before the upgrade continues or is refused cleanly | Result = Expected (73 checks): the stored report is byte-identical after the upgrade, the score is unchanged, 0.1.0 reads data written by the newer build | live (4.1): `bin/destructive.sh run 4` |
| 5.1 Product with 500 variations and an order with 300 lines, `memory_limit` 128M (WordPress not allowed to raise it), 30 s time limit | The audit completes without a timeout or a memory error; the heaviest check is measured | Result = Expected: all 49 checks answered 200, no check ended in "not checked", the CLI audit also passed at 128M. Slowest browser requests: 1.3 to 2.1 s (loopback probes and the stale-orders query); the catalogue and order checks finished in under 1.5 s | live (5.1): `bin/destructive.sh run 5` |

## How to reproduce

Needs Docker, the lab containers (`wplab-db-1` and the `wplab_default` network) and the lab's `site/.bin/wp-cli.phar`.
The clone lives in `$HOME/.cache/store-audit-destructive/`, uses the database `sa_destr_1` and one container,
`sa-destr-1`. The lab site itself is never touched.

```
bash bin/destructive.sh up        # build the clone: WordPress, WooCommerce, the plugin, three test users
bash bin/destructive.sh run       # every scenario (about 25 minutes)
bash bin/destructive.sh run 1 3   # only scenario groups 1 and 3
ONLY=3.2,3.3 ... node run.mjs 3  # a single scenario, from inside the container
bash bin/destructive.sh down      # remove the container, the database and the files
```

The scenarios are in `tests/Destructive/` (Node, run inside the container). They log in as three users
(administrator, shop manager, subscriber), send the requests with `fetch`, read `wp-content/debug.log` before and
after each step, compare the `store_audit_*` rows in the database and, for the admin script, run
`resources/admin.js` in a small stand-in for the browser DOM that fails on any write to `innerHTML`.

The defect tests that need no WordPress run in the normal unit suite (`composer test`). To see a defect fail, check
out 0.1.0 (`git archive v0.1.0`), put the new tests next to it, and run `composer test`, or install the 0.1.0 zip in
the clone and run the same scenario.

Manual checks used along the way:

```
wp store-audit run --format=json
wp store-audit run --format='<script>alert(1)</script>'
wp plugin deactivate store-audit-for-woocommerce && wp plugin uninstall store-audit-for-woocommerce
curl -s -d 'action=store_audit_start&nonce[]=x' http://localhost:8080/wp-admin/admin-ajax.php
```
