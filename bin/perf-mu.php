<?php
/**
 * Plugin Name: SA perf harness (development only)
 * Signs the request in as the first administrator when the perf driver asks for it.
 */
if ( getenv( 'SA_PERF_LOGIN' ) ) {
	add_filter(
		'determine_current_user',
		static function () {
			return (int) getenv( 'SA_PERF_LOGIN' );
		},
		99
	);
}

// Slow-network scenario: wordpress.org is unreachable (packets are dropped, so a request runs into its timeout).
if ( getenv( 'PERF_BLACKHOLE' ) ) {
	add_filter(
		'pre_http_request',
		static function ( $pre, $args, $url ) {
			$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			if ( str_ends_with( $host, 'wordpress.org' ) ) {
				remove_all_filters( 'pre_http_request' );
				return wp_remote_request( 'http://192.0.2.1/', $args );
			}
			return $pre;
		},
		1,
		3
	);
}

// Self-test of the detector (PERF_TEST_MEMORY=60M or PERF_TEST_BURN=1): provoke a memory error or a time-out on purpose.
if ( getenv( 'PERF_TEST_MEMORY' ) ) {
	ini_set( 'memory_limit', (string) getenv( 'PERF_TEST_MEMORY' ) ); // phpcs:ignore
}
if ( getenv( 'PERF_TEST_BURN' ) ) {
	add_action(
		'admin_init',
		static function () {
			if ( 'cpu' === getenv( 'PERF_TEST_BURN' ) ) {
				while ( true ) { // phpcs:ignore
					md5( (string) mt_rand() );
				}
			}
		}
	);
}
