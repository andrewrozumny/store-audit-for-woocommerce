#!/usr/bin/env bash
# Integration test: runs the audit through WP-CLI in the lab site and validates the JSON.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# The plugin lives in <site>/wp-content/plugins/<slug>. A dev site may ship a ./wp wrapper next to <site>.
SITE_ROOT="${SITE_ROOT:-$(cd "$PLUGIN_DIR/../../.." && pwd)}"
if [ -z "${WP_CLI:-}" ]; then
	if [ -x "$SITE_ROOT/../wp" ]; then WP_CLI="$SITE_ROOT/../wp"; else WP_CLI="wp --path=$SITE_ROOT"; fi
fi
LAB_ROOT="$SITE_ROOT"
DEBUG_LOG="${LAB_ROOT}/wp-content/debug.log"
PLUGIN_PATH_MARK="plugins/store-audit-for-woocommerce/"

before=0
[ -f "$DEBUG_LOG" ] && before=$(wc -c < "$DEBUG_LOG" | tr -d ' ')

out_file="$(mktemp)"
trap 'rm -f "$out_file"' EXIT

if ! $WP_CLI store-audit run --format=json > "$out_file"; then
	echo "FAIL: wp store-audit run exited with a non-zero code" >&2
	cat "$out_file" >&2
	exit 1
fi

php -r '
$raw = file_get_contents( $argv[1] );
$data = json_decode( $raw, true );
if ( ! is_array( $data ) ) { fwrite( STDERR, "FAIL: output is not valid JSON\n" . substr( $raw, 0, 500 ) . "\n" ); exit( 1 ); }
if ( ! isset( $data["score"]["overall"] ) || ! is_int( $data["score"]["overall"] ) ) { fwrite( STDERR, "FAIL: missing score.overall\n" ); exit( 1 ); }
if ( ! isset( $data["results"] ) || ! is_array( $data["results"] ) || array() === $data["results"] ) { fwrite( STDERR, "FAIL: no results\n" ); exit( 1 ); }
$required = array( "id", "section", "title", "severity", "summary", "details", "explanation", "fix_hint", "duration_ms" );
$severities = array( "critical", "warning", "info", "ok", "error" );
$counts = array_fill_keys( $severities, 0 );
foreach ( $data["results"] as $i => $row ) {
	foreach ( $required as $key ) {
		if ( ! is_array( $row ) || ! array_key_exists( $key, $row ) ) { fwrite( STDERR, "FAIL: result #$i missing key $key\n" ); exit( 1 ); }
	}
	if ( ! in_array( $row["severity"], $severities, true ) ) { fwrite( STDERR, "FAIL: result {$row["id"]} has bad severity\n" ); exit( 1 ); }
	$counts[ $row["severity"] ]++;
}
foreach ( array( "generated_at", "site_url", "plugin_version", "wp_version", "wc_version" ) as $key ) {
	if ( ! array_key_exists( $key, $data ) ) { fwrite( STDERR, "FAIL: report missing $key\n" ); exit( 1 ); }
}
$parts = array();
foreach ( $counts as $sev => $n ) { $parts[] = "$sev=$n"; }
printf( "Integration: %d checks, score %d, %s\n", count( $data["results"] ), $data["score"]["overall"], implode( " ", $parts ) );
' "$out_file"

if [ -f "$DEBUG_LOG" ]; then
	new_lines="$(tail -c +"$((before + 1))" "$DEBUG_LOG" | grep -E 'PHP (Fatal|Warning)' | grep -F "$PLUGIN_PATH_MARK" || true)"
	if [ -n "$new_lines" ]; then
		echo "FAIL: PHP Fatal/Warning from this plugin in debug.log:" >&2
		echo "$new_lines" >&2
		exit 1
	fi
fi
echo "Integration: OK"
