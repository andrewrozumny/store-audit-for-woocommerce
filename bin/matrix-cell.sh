#!/usr/bin/env bash
# Compatibility matrix: one group (WordPress x PHP x WooCommerce) INSIDE a container.
# Started by bin/matrix.sh. Not meant to be run by hand.
#
# Environment: MATRIX_MODE=unit|group, MATRIX_PHP, MATRIX_WP, MATRIX_WOO, MATRIX_WOO_ZIP, MATRIX_GROUP,
# MATRIX_DB, MATRIX_HPOS ("on off"), MATRIX_MULTISITE=0|1, MATRIX_PLUGIN_CHECK=0|1.
# Mounts: /cache (rw), /repo (plugin repo, read-only), /plugin.zip, /wp-cli.phar.
set -uo pipefail

export HOME=/tmp/home
mkdir -p "$HOME"
MODE="${MATRIX_MODE:-group}"
SLUG="store-audit-for-woocommerce"
CACHE=/cache

# --------------------------------------------------------------------------------------------
if [ "$MODE" = "unit" ]; then
	RES="$CACHE/results"
	mkdir -p "$RES"
	KV="$RES/unit-php${MATRIX_PHP}.kv"
	: > "$KV"
	cd /unit-src || exit 1
	out="$(php -d memory_limit=1G vendor/bin/phpunit --testsuite unit --do-not-cache-result --colors=never 2>&1)"
	rc=$?
	echo "$out" | tail -25
	summary="$(echo "$out" | grep -E '^(OK|Tests:|FAILURES|ERRORS)' | tail -2 | tr '\n' ' ')"
	printf 'php=%s\nstatus=%s\nsummary=%s\n' "$(php -r 'echo PHP_VERSION;')" "$([ $rc -eq 0 ] && echo PASS || echo FAIL)" "$summary" > "$KV"
	exit 0
fi

# --------------------------------------------------------------------------------------------
GROUP="${MATRIX_GROUP:?}"
DB="${MATRIX_DB:?}"
WP="${MATRIX_WP:?}"
SITE="$CACHE/sites/$GROUP"
RES="$CACHE/results/$GROUP"
LOGDIR="$CACHE/logs/$GROUP"
ERRLOG="$LOGDIR/wp-cli-stderr.log"
LOG="$SITE/wp-content/debug.log"
HOST="sa-matrix.test"
ADMIN_PASS="matrix-Pass-123!"
read -r -a HPOS_LIST <<< "${MATRIX_HPOS:-on off}"
MS="${MATRIX_MULTISITE:-0}"   # 0 single site, 1 multisite with network activation, 2 multisite with per-site activation
if [ "$MS" != "0" ]; then
	URLS=("$HOST" "$HOST/sub")
else
	URLS=("")
fi

rm -rf "$RES" "$LOGDIR"
mkdir -p "$RES" "$LOGDIR"
: > "$ERRLOG"

wpc() { php -d memory_limit=1G -d display_errors=stderr -d sendmail_path=/bin/true /wp-cli.phar --path="$SITE" "$@" 2>>"$ERRLOG"; }
wps() { if [ -n "${CUR_URL:-}" ]; then wpc --url="$CUR_URL" "$@"; else wpc "$@"; fi; }
say() { echo "[$(date +%H:%M:%S)] $*"; }
KV=""
kv() { printf '%s=%s\n' "$1" "${2//$'\n'/ }" >> "$KV"; }

mysqlc() { mysql -h db -uroot -pwordpress "$@"; }

fail_all() { # $1 reason
	say "SETUP FAILED: $1"
	tail -15 "$ERRLOG" | sed 's/^/  stderr: /'
	for h in "${HPOS_LIST[@]}"; do
		KV="$RES/$h.kv"
		: > "$KV"
		kv hpos_req "$h"
		kv status FAIL
		kv fail_reasons "setup: $1"
		kv php "$(php -r 'echo PHP_VERSION;')"
	done
	exit 0
}

# ---- 1. WordPress ------------------------------------------------------------------------
say "group $GROUP: WP $WP, PHP $(php -r 'echo PHP_VERSION;'), Woo ${MATRIX_WOO:-?}"
rm -rf "$SITE" "$SITE.tmp"
mkdir -p "$SITE.tmp"
unzip -q "$CACHE/core/wordpress-$WP.zip" -d "$SITE.tmp" || fail_all "unzip core"
mv "$SITE.tmp/wordpress" "$SITE" && rmdir "$SITE.tmp"

wpc config create --dbname="$DB" --dbuser=root --dbpass=wordpress --dbhost=db --skip-check >/dev/null || fail_all "config create"
wpc config set WP_DEBUG true --raw --type=constant >/dev/null
wpc config set WP_DEBUG_LOG true --raw --type=constant >/dev/null
wpc config set WP_DEBUG_DISPLAY false --raw --type=constant >/dev/null
wpc config set SCRIPT_DEBUG true --raw --type=constant >/dev/null
wpc config set FS_METHOD direct --type=constant >/dev/null

wpc core install --url="http://$HOST" --title="SA Matrix" --admin_user=admin --admin_password="$ADMIN_PASS" --admin_email=admin@example.test --skip-email >/dev/null || fail_all "core install"
# WP-CLI appends the --path to the guessed URL; pin the addresses to the lab host name.
wpc option update siteurl "http://$HOST" >/dev/null 2>&1
wpc option update home "http://$HOST" >/dev/null 2>&1
if [ "$MS" != "0" ]; then
	wpc core multisite-convert --title="SA Matrix" --base=/ >/dev/null || fail_all "multisite-convert"
	wpc site create --slug=sub --title="SA Matrix Sub" --email=sub@example.test >/dev/null || fail_all "site create"
fi
ACTUAL_WP="$(wpc core version)"
say "WordPress $ACTUAL_WP installed"

# ---- 2. WooCommerce ----------------------------------------------------------------------
NET=""
[ "$MS" = "1" ] && NET="--network"
# activate_everywhere <slug>: network-wide, or on every site one by one.
activate_everywhere() {
	if [ "$MS" = "2" ]; then
		for u in "${URLS[@]}"; do wpc --url="$u" plugin activate "$1" >/dev/null || return 1; done
	else
		wpc plugin activate "$1" $NET >/dev/null
	fi
}
deactivate_everywhere() {
	if [ "$MS" = "2" ]; then
		for u in "${URLS[@]}"; do wpc --url="$u" plugin deactivate "$1" >/dev/null; done
	else
		wpc plugin deactivate "$1" $NET >/dev/null
	fi
}
wpc plugin install /woo.zip >/dev/null || fail_all "woocommerce install"
activate_everywhere woocommerce || fail_all "woocommerce activate"
for u in "${URLS[@]}"; do
	CUR_URL="$u"
	wps option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null 2>&1 || true
	wps option update woocommerce_task_list_hidden yes >/dev/null 2>&1 || true
	wps option update woocommerce_default_country "US:CA" >/dev/null 2>&1 || true
	wps option update woocommerce_currency USD >/dev/null 2>&1 || true
	wps transient delete _wc_activation_redirect >/dev/null
done
CUR_URL=""
ACTUAL_WOO="$(wpc plugin get woocommerce --field=version)"
say "WooCommerce $ACTUAL_WOO active"

# ---- helpers -----------------------------------------------------------------------------
logsize() { [ -f "$LOG" ] && wc -c < "$LOG" | tr -d ' ' || echo 0; }
hpos_state() { wps eval 'echo \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "on" : "off";' | tail -1; }

set_hpos() { # $1 on|off ; echoes actual state
	local want="$1" val=yes
	[ "$want" = off ] && val=no
	wps option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null
	wps option update woocommerce_custom_orders_table_enabled "$val" >/dev/null
	local got
	got="$(hpos_state)"
	if [ "$got" != "$want" ]; then
		if [ "$want" = on ]; then wps wc hpos enable >/dev/null; else wps wc hpos disable >/dev/null; fi
		got="$(hpos_state)"
	fi
	echo "$got"
}

# ---- 3. one pass per HPOS state ----------------------------------------------------------
run_pass() {
	local hpos="$1"
	KV="$RES/$hpos.kv"
	: > "$KV"
	local reasons=()
	local logoff
	logoff="$(logsize)"
	# The first pass also owns the install-time noise (core and WooCommerce setup).
	[ "$hpos" = "${HPOS_LIST[0]}" ] && logoff=0
	say "---- pass HPOS=$hpos"
	kv hpos_req "$hpos"
	kv wp "$ACTUAL_WP"
	kv woo "$ACTUAL_WOO"
	kv php "$(php -r 'echo PHP_VERSION;')"

	# HPOS state and data on every site.
	local hpos_ok=1 hp
	for u in "${URLS[@]}"; do
		CUR_URL="$u"
		MATRIX_ARG=clean wps eval-file /repo/bin/matrix-seed.php >> "$LOGDIR/seed.log" 2>&1
		hp="$(set_hpos "$hpos")"
		[ "$hp" = "$hpos" ] || hpos_ok=0
		kv "hpos_actual${u:+_$(echo "$u" | tr '/.' '__')}" "$hp"
		MATRIX_ARG=create wps eval-file /repo/bin/matrix-seed.php >> "$LOGDIR/seed.log" 2>&1 || { reasons+=("seed failed on ${u:-main}"); }
	done
	CUR_URL=""
	[ "$hpos_ok" = 1 ] || reasons+=("HPOS state did not switch to $hpos")
	kv hpos_actual "$([ "$hpos_ok" = 1 ] && echo "$hpos" || echo mismatch)"

	# Plugin from the zip.
	if ! wpc plugin install /plugin.zip --force >/dev/null || ! activate_everywhere "$SLUG"; then
		reasons+=("plugin install or activation failed")
		kv integration FAIL; kv smoke FAIL; kv uninstall FAIL
		kv status FAIL; kv fail_reasons "${reasons[*]}"
		tail -8 "$ERRLOG" | sed 's/^/  stderr: /'
		return
	fi
	kv plugin_version "$(wpc plugin get "$SLUG" --field=version)"

	# Integration and smoke on every site.
	local int_ok=1 smoke_ok=1 errlist="" tag out
	for u in "${URLS[@]}"; do
		CUR_URL="$u"
		tag="$hpos${u:+-$(echo "$u" | tr '/.' '__')}"
		out="$LOGDIR/audit-$tag.json"
		local t0=$SECONDS
		wps store-audit run --format=json > "$out" 2>>"$ERRLOG"
		local rc=$?
		local sum
		sum="$(php /repo/bin/matrix-validate.php report "$out" 2>&1)"
		local vrc=$?
		if [ $rc -ne 0 ] || [ $vrc -ne 0 ]; then
			int_ok=0
			reasons+=("integration failed on ${u:-main} (cli rc=$rc, validator rc=$vrc)")
			echo "$sum" | head -3 | sed 's/^/  validator: /'
		fi
		say "integration ${u:-main}: $(echo "$sum" | head -1) in $((SECONDS - t0))s"
		kv "integration_detail${u:+_$(echo "$u" | tr '/.' '__')}" "$(echo "$sum" | head -1)"
		el="$(echo "$sum" | grep '^ERROR_CHECKS' || true)"
		[ -n "$el" ] && errlist="$errlist ${u:+[$u] }${el#ERROR_CHECKS }"

		MATRIX_ARG=smoke wps --user=admin eval-file /repo/bin/matrix-smoke.php > "$LOGDIR/smoke-$tag.log" 2>>"$ERRLOG"
		local src=$?
		if ! grep -q '^SMOKE PASS' "$LOGDIR/smoke-$tag.log"; then
			smoke_ok=0
			local failed
			failed="$(grep '^STEP FAIL' "$LOGDIR/smoke-$tag.log" | head -3 | tr '\n' ';')"
			[ -z "$failed" ] && failed="no result line (rc=$src): $(tail -3 "$ERRLOG" | tr '\n' ' ')"
			reasons+=("smoke failed on ${u:-main}: $failed")
		fi
		say "smoke ${u:-main}: $(grep -E '^SMOKE (PASS|FAIL)' "$LOGDIR/smoke-$tag.log" | tail -1)"
	done
	CUR_URL=""
	kv integration "$([ "$int_ok" = 1 ] && echo PASS || echo FAIL)"
	kv smoke "$([ "$smoke_ok" = 1 ] && echo PASS || echo FAIL)"
	kv error_checks "${errlist# }"

	# debug.log slice for this pass.
	local slice="$LOGDIR/debug-$hpos.log"
	if [ -f "$LOG" ]; then tail -c +"$((logoff + 1))" "$LOG" > "$slice"; else : > "$slice"; fi
	cat "$slice" "$ERRLOG" > "$LOGDIR/combined-$hpos.log"
	local pat='PHP (Fatal error|Parse error|Warning|Notice|Deprecated)|WordPress database error|_doing_it_wrong'
	local mark='store-audit-for-woocommerce|StoreAudit|store_audit'
	local plugin_n third_n
	plugin_n="$(grep -E "$pat" "$slice" | grep -cE "$mark" || true)"
	third_n="$(grep -E "$pat" "$slice" | grep -vcE "$mark" || true)"
	# Plugin lines can also appear on the CLI stderr stream.
	local err_plugin
	err_plugin="$(grep -E "$pat" "$ERRLOG" | grep -cE "$mark" || true)"
	plugin_n=$((plugin_n + err_plugin))
	kv log_plugin "$plugin_n"
	kv log_third "$third_n"
	if [ "$plugin_n" -gt 0 ]; then
		reasons+=("$plugin_n debug.log line(s) from the plugin")
		grep -hE "$pat" "$slice" "$ERRLOG" | grep -E "$mark" | head -5 | sed 's/^/  PLUGIN LOG: /'
		kv log_plugin_sample "$(grep -hE "$pat" "$slice" "$ERRLOG" | grep -E "$mark" | head -3 | tr '\n' '~')"
	fi

	# Plugin Check on the plugin installed from the zip (first pass only).
	if [ "${MATRIX_PLUGIN_CHECK:-0}" = "1" ] && [ "$hpos" = "${HPOS_LIST[0]}" ]; then
		say "plugin check"
		local pc="$LOGDIR/plugin-check.csv"
		if wpc plugin install plugin-check >/dev/null && activate_everywhere plugin-check; then
			wpc plugin check "$SLUG" --include-experimental --format=csv --fields=line,column,type,code,message,file > "$pc" 2>"$LOGDIR/plugin-check.stderr"
			cat "$LOGDIR/plugin-check.stderr" >> "$ERRLOG"
			local pcsum
			pcsum="$(php /repo/bin/matrix-validate.php plugincheck "$pc" 2>&1)"
			say "plugin check: $pcsum"
			kv plugin_check "$pcsum"
			case "$pcsum" in
				errors=0\ *) kv plugin_check_status PASS ;;
				errors=*) kv plugin_check_status FAIL; reasons+=("plugin check reported errors") ;;
				*) kv plugin_check_status FAIL; reasons+=("plugin check produced no result: $(head -c 200 "$LOGDIR/plugin-check.stderr")") ;;
			esac
			deactivate_everywhere plugin-check >/dev/null 2>&1
			wpc plugin delete plugin-check >/dev/null 2>&1
		else
			kv plugin_check_status BLOCKED
			kv plugin_check "could not install plugin-check from wordpress.org"
		fi
	fi

	# Deactivate and uninstall; plugin data must go, WooCommerce data must stay.
	CUR_URL=""
	local before after
	before="$(MATRIX_ARG=leftovers wpc eval-file /repo/bin/matrix-smoke.php | grep '^LEFTOVERS ' | head -1)"
	deactivate_everywhere "$SLUG"
	wpc plugin uninstall "$SLUG" >/dev/null 2>&1 || wpc plugin delete "$SLUG" >/dev/null 2>&1
	after="$(MATRIX_ARG=leftovers wpc eval-file /repo/bin/matrix-smoke.php | grep '^LEFTOVERS ' | head -1)"
	local un
	un="$(php -r '
		$b = json_decode( substr( $argv[1], 10 ), true ); $a = json_decode( substr( $argv[2], 10 ), true );
		if ( ! is_array( $b ) || ! is_array( $a ) ) { echo "FAIL could not read leftovers"; exit; }
		$msg = array();
		if ( $a["plugin_rows"] ) { $msg[] = "left behind: " . implode( ", ", array_slice( $a["plugin_rows"], 0, 6 ) ); }
		if ( $a["wc_options"] !== $b["wc_options"] ) { $msg[] = "WooCommerce options " . $b["wc_options"] . " -> " . $a["wc_options"]; }
		if ( "" === $a["wc_version"] ) { $msg[] = "woocommerce_version gone"; }
		echo $msg ? "FAIL " . implode( "; ", $msg ) : "PASS before=" . count( $b["plugin_rows"] ) . " rows";
	' "$before" "$after")"
	[ -d "$SITE/wp-content/plugins/$SLUG" ] && un="FAIL plugin directory still present"
	say "uninstall: $un"
	kv uninstall "${un%% *}"
	kv uninstall_detail "$un"
	[ "${un%% *}" = PASS ] || reasons+=("uninstall: ${un#FAIL }")

	if [ ${#reasons[@]} -eq 0 ]; then
		kv status PASS
		kv fail_reasons ""
	else
		kv status FAIL
		kv fail_reasons "$(IFS='; '; echo "${reasons[*]}")"
	fi
}

for h in "${HPOS_LIST[@]}"; do
	run_pass "$h"
done

cp -f "$LOG" "$LOGDIR/debug-full.log" 2>/dev/null || true
say "group done"
exit 0
