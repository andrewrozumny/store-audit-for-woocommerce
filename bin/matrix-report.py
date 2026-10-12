#!/usr/bin/env python3
"""Compatibility matrix helper (development only, not shipped).

Reads the results the matrix run left in the cache and renders docs/COMPAT.md.

  matrix-report.py <cache> <out.md> <plugin-version> [--stdout]
"""
import datetime
import glob
import os
import re
import sys


def read_kv(path):
    data = {}
    if not os.path.isfile(path):
        return data
    with open(path, encoding="utf-8", errors="replace") as fh:
        for line in fh:
            line = line.rstrip("\n")
            if "=" in line:
                k, v = line.split("=", 1)
                data[k] = v
    return data


def clean(text):
    """Keep the document free of long dashes."""
    return str(text).replace("—", "-").replace("–", "-")


def vkey(v):
    return tuple(int(x) for x in re.findall(r"\d+", v))


def md_cell(text):
    return clean(text).replace("|", "/").replace("\n", " ")


def main():
    cache, out, plugin_version = sys.argv[1], sys.argv[2], sys.argv[3]
    to_stdout = "--stdout" in sys.argv
    results = os.path.join(cache, "results")
    groups = []
    for meta_path in sorted(glob.glob(os.path.join(results, "*.meta"))):
        meta = read_kv(meta_path)
        gid = os.path.basename(meta_path)[: -len(".meta")]
        meta["id"] = gid
        for hpos in ("on", "off"):
            res = read_kv(os.path.join(results, gid, hpos + ".kv"))
            if res:
                unit = read_kv(os.path.join(results, "unit-php%s.kv" % meta["php"]))
                groups.append((meta, hpos, res, unit))

    def result_of(res, unit):
        if res.get("status") == "PASS" and unit.get("status", "PASS") == "PASS":
            return "PASS"
        return "FAIL"

    def row(meta, hpos, res, unit):
        woo = meta["woo"] + (" (min)" if meta["woo_kind"] == "min" else " (current)" if meta["woo_kind"] == "current" else "")
        integ = res.get("integration", "FAIL")
        m = re.search(r"score=(\d+)", res.get("integration_detail", ""))
        if integ == "PASS" and m:
            integ += " (score %s)" % m.group(1)
        wp_label = meta["wp"] + {"1": " (multisite, network activation)", "2": " (multisite, per-site activation)"}.get(meta.get("multisite", "0"), "")
        return [
            wp_label, meta["php_full"] if meta.get("php_full") else meta["php"], woo, hpos,
            unit.get("status", "n/a"), integ, res.get("smoke", "FAIL"), res.get("uninstall", "FAIL"),
            res.get("log_plugin", "?"), res.get("log_third", "?"), result_of(res, unit),
        ]

    def table(rows):
        head = ["WordPress", "PHP", "WooCommerce", "HPOS", "Unit", "Integration", "Smoke", "Uninstall", "debug.log plugin", "debug.log other", "Result"]
        lines = ["| " + " | ".join(head) + " |", "|" + "|".join(["---"] * len(head)) + "|"]
        for r in rows:
            lines.append("| " + " | ".join(md_cell(c) for c in r) + " |")
        return "\n".join(lines)

    def sort_key(g):
        meta, hpos, _, _ = g
        return (vkey(meta["wp"]), vkey(meta["php"]), 0 if meta["woo_kind"] == "min" else 1, 0 if hpos == "on" else 1)

    matrix = sorted([g for g in groups if g[0]["kind"] == "matrix"], key=sort_key)
    php85 = sorted([g for g in groups if g[0]["kind"] == "php85"], key=sort_key)
    multisite = sorted([g for g in groups if g[0]["kind"] == "multisite"], key=lambda g: sort_key(g) + (g[0].get("multisite", "0"),))

    today = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d")
    md = []
    md.append("# Compatibility\n")
    sha = ""
    try:
        sha = open(os.path.join(cache, "zip-sha.txt")).read().strip()
    except OSError:
        pass
    md.append("Last full run: %s. Plugin version: %s%s.\n" % (today, plugin_version, " (release zip sha256 %s...)" % sha if sha else ""))
    md.append("## Support policy\n")
    md.append("- WordPress: requires 6.6 or newer. Tested up to 7.1.")
    md.append("- PHP: requires 8.1 or newer.")
    md.append("- WooCommerce: requires 9.0 or newer.")
    md.append("- Versions below these are not supported.\n")
    md.append("## What is tested\n")
    md.append(
        "Every row is a clean WordPress install (WP_DEBUG, WP_DEBUG_LOG and SCRIPT_DEBUG on) with WooCommerce activated, "
        "a few products and orders, and the plugin installed from the release zip. For each row the matrix runs the unit test suite, "
        "a full audit through WP-CLI (`wp store-audit run --format=json`, output structure validated), a browserless smoke test "
        "(the step-by-step runner requests as the admin screen sends them, the admin page, the Dashboard widget, JSON, print and Markdown "
        "exports, and rejection of requests from a subscriber, a logged-out visitor and a request without a nonce), "
        "and a deactivate and uninstall cycle that must remove the plugin's own options and transients and leave WooCommerce data in place. "
        "The debug.log of each row must contain no notices, warnings, deprecations or fatal errors that point at this plugin. "
        "Lines that come from WordPress core, WooCommerce or other code are counted separately.\n"
    )
    md.append(
        "WooCommerce columns show the minimum supported release (the latest 9.0.x) and the newest release that officially installs on that "
        "WordPress and PHP combination (the newest 11.x where WordPress allows it). HPOS (custom order tables) is switched on and off "
        "in every row and its real state is checked before the audit runs.\n"
    )
    md.append("Reproduce with `bash bin/matrix.sh` from the plugin repository (Docker and about 20 GB of disk are needed). Filters for a single row: `bash bin/matrix.sh --wp=7.1 --php=8.4 --woo=current --hpos=on`.\n")

    fails = [g for g in groups if result_of(g[2], g[3]) != "PASS"]
    total = len(groups)
    md.append("## Summary\n")
    md.append("%d of %d rows passed.\n" % (total - len(fails), total))

    md.append("## Matrix: WordPress x PHP x WooCommerce x HPOS\n")
    md.append(table([row(*g) for g in matrix]) if matrix else "No results yet.")
    md.append("")

    md.append("## PHP 8.5 (smoke)\n")
    md.append(table([row(*g) for g in php85]) if php85 else "Not run.")
    md.append("")

    md.append("## Multisite (network activation)\n")
    if multisite:
        md.append(table([row(*g) for g in multisite]))
        md.append("\nThe audit and the smoke test run on the main site and on a sub-site; the uninstall check covers every site and the network tables.")
    else:
        md.append("Not run.")
    md.append("")

    md.append("## Plugin Check on the release zip\n")
    pc_rows = []
    pc_details = []
    for meta, hpos, res, unit in groups:
        if "plugin_check_status" in res:
            pc_rows.append([meta["wp"], meta["php"], meta["woo"], res.get("plugin_check", ""), res["plugin_check_status"]])
            summ = os.path.join(results, meta["id"], "..", "..", "logs", meta["id"], "plugin-check.csv.summary.txt")
            summ = os.path.join(cache, "logs", meta["id"], "plugin-check.csv.summary.txt")
            if os.path.isfile(summ):
                for line in open(summ, encoding="utf-8", errors="replace"):
                    line = line.strip()
                    if line.startswith("WARNING") or line.startswith("ERROR"):
                        pc_details.append(line)
    if pc_rows:
        md.append("| WordPress | PHP | WooCommerce | Findings | Result |\n|---|---|---|---|---|")
        for r in pc_rows:
            md.append("| " + " | ".join(md_cell(c) for c in r) + " |")
        md.append("\nCommand: `wp plugin check store-audit-for-woocommerce --include-experimental --format=csv`, run against the plugin installed from the zip. Zero errors is required.\n")
        if pc_details:
            md.append("Findings:\n")
            for line in sorted(set(pc_details)):
                md.append("- " + md_cell(line))
            md.append("")
    else:
        md.append("Not run.\n")

    notes = []
    for meta, hpos, res, unit in sorted(groups, key=sort_key):
        label = "WP %s%s / PHP %s / WooCommerce %s / HPOS %s" % (meta["wp"], {"1": " multisite network activation", "2": " multisite per-site activation"}.get(meta.get("multisite", "0"), ""), meta["php"], meta["woo"], hpos)
        if res.get("error_checks"):
            notes.append("- %s: checks that reported severity `error`: %s" % (label, res["error_checks"]))
        if result_of(res, unit) != "PASS":
            reason = res.get("fail_reasons") or ("unit tests: " + unit.get("summary", "failed") if unit.get("status") == "FAIL" else "see logs")
            notes.append("- %s: FAIL, %s" % (label, reason[:700]))
        if res.get("log_plugin", "0") not in ("0", "?"):
            notes.append("- %s: plugin log lines: %s" % (label, res.get("log_plugin_sample", "").replace("~", " / ")))
    woo_notes = sorted({(g[0]["wp"], g[0]["woo"], g[0]["woo_note"]) for g in groups if g[0].get("woo_note")})
    md.append("## Notes\n")
    if woo_notes:
        md.append("WooCommerce selection:\n")
        for wp, woo, note in woo_notes:
            md.append("- WordPress %s: WooCommerce %s (%s)." % (wp, woo, note))
        md.append("")
    third = [int(g[2].get("log_third", "0") or 0) for g in groups if g[2].get("log_third", "?").isdigit()]
    if third:
        md.append("Third-party noise: debug.log lines from WordPress core, WooCommerce and other code ranged from %d to %d per row. They are listed per row above and are not counted against the plugin.\n" % (min(third), max(third)))
    if notes:
        md.extend(notes)
        md.append("")
    else:
        md.append("No failures or error-severity checks were recorded.\n")

    text = clean("\n".join(md)) + "\n"
    if to_stdout:
        sys.stdout.write(text)
    else:
        with open(out, "w", encoding="utf-8") as fh:
            fh.write(text)
    sys.exit(1 if fails else 0)


if __name__ == "__main__":
    main()
