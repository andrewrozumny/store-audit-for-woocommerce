<?php
/**
 * Compatibility matrix helper (development only, not shipped).
 *
 * php bin/matrix-validate.php report <audit.json>
 * php bin/matrix-validate.php plugincheck <plugin-check.csv>
 */

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '';

if ( 'report' === $mode ) {
	$raw  = is_file( $file ) ? (string) file_get_contents( $file ) : '';
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		fwrite( STDERR, "FAIL: output is not valid JSON: " . substr( $raw, 0, 300 ) . "\n" );
		exit( 1 );
	}
	if ( ! isset( $data['score']['overall'] ) || ! is_int( $data['score']['overall'] ) ) {
		fwrite( STDERR, "FAIL: missing score.overall\n" );
		exit( 1 );
	}
	if ( ! isset( $data['results'] ) || ! is_array( $data['results'] ) || array() === $data['results'] ) {
		fwrite( STDERR, "FAIL: no results\n" );
		exit( 1 );
	}
	$required   = array( 'id', 'section', 'title', 'severity', 'summary', 'details', 'explanation', 'fix_hint', 'duration_ms' );
	$severities = array( 'critical', 'warning', 'info', 'ok', 'error' );
	$counts     = array_fill_keys( $severities, 0 );
	$errors     = array();
	foreach ( $data['results'] as $i => $row ) {
		foreach ( $required as $key ) {
			if ( ! is_array( $row ) || ! array_key_exists( $key, $row ) ) {
				fwrite( STDERR, "FAIL: result #$i missing key $key\n" );
				exit( 1 );
			}
		}
		if ( ! in_array( $row['severity'], $severities, true ) ) {
			fwrite( STDERR, "FAIL: result {$row['id']} has bad severity\n" );
			exit( 1 );
		}
		++$counts[ $row['severity'] ];
		if ( 'error' === $row['severity'] ) {
			$errors[] = $row['id'] . ' [' . preg_replace( '/\s+/', ' ', (string) $row['summary'] ) . ']';
		}
	}
	foreach ( array( 'generated_at', 'site_url', 'plugin_version', 'wp_version', 'wc_version' ) as $key ) {
		if ( ! array_key_exists( $key, $data ) ) {
			fwrite( STDERR, "FAIL: report missing $key\n" );
			exit( 1 );
		}
	}
	$parts = array();
	foreach ( $counts as $sev => $n ) {
		$parts[] = "$sev=$n";
	}
	printf( "checks=%d score=%d %s\n", count( $data['results'] ), $data['score']['overall'], implode( ' ', $parts ) );
	if ( $errors ) {
		echo 'ERROR_CHECKS ' . implode( ' | ', $errors ) . "\n";
	}
	exit( 0 );
}

if ( 'plugincheck' === $mode ) {
	$errors   = 0;
	$warnings = 0;
	$agg      = array();
	if ( ! is_file( $file ) ) {
		fwrite( STDERR, "no csv\n" );
		exit( 2 );
	}
	$fh     = fopen( $file, 'r' );
	$header = fgetcsv( $fh, 0, ',', '"', '' );
	while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
		if ( ! is_array( $header ) || count( $row ) !== count( $header ) ) {
			continue;
		}
		$r = array_combine( $header, $row );
		if ( 'ERROR' === ( $r['type'] ?? '' ) ) {
			++$errors;
		} elseif ( 'WARNING' === ( $r['type'] ?? '' ) ) {
			++$warnings;
		}
		$key = ( $r['type'] ?? '' ) . ' ' . ( $r['code'] ?? '' ) . ' | ' . ( $r['file'] ?? '' ) . ':' . ( $r['line'] ?? '' ) . ' | ' . preg_replace( '/\s+/', ' ', (string) ( $r['message'] ?? '' ) );
		$agg[] = $key;
	}
	fclose( $fh );
	file_put_contents( $file . '.summary.txt', implode( "\n", $agg ) . "\n" );
	echo "errors=$errors warnings=$warnings\n";
	exit( 0 );
}

fwrite( STDERR, "usage: matrix-validate.php report|plugincheck <file>\n" );
exit( 64 );
