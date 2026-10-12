<?php
/**
 * Performance harness: one plugin step in one PHP process (development only, not shipped).
 *
 *   php -d memory_limit=128M -d max_execution_time=30 bin/perf-run.php <site> <step> [check_id]
 *
 * Steps: boot (WordPress and the admin includes only), start, check <id>, finish, markdown (AJAX), download, print (admin-post).
 * It reproduces what wp-admin/admin-ajax.php and admin-post.php do, signed in as the first administrator, and writes one JSON
 * file (PERF_OUT) with time, peak memory, query counts and, with PERF_SAVEQ=1, the query analysis.
 */

$site  = $argv[1] ?? '';
$step  = $argv[2] ?? '';
$check = $argv[3] ?? '';
$out   = getenv( 'PERF_OUT' ) ?: '/tmp/perf-out.json';
$saveq = '1' === getenv( 'PERF_SAVEQ' );
$explain = '1' === getenv( 'PERF_EXPLAIN' );
$t0    = microtime( true );

$M = array(
	'step'    => $step,
	'check'   => $check,
	'saveq'   => $saveq,
	'started' => false,
);

$is_ajax = in_array( $step, array( 'start', 'check', 'finish', 'markdown' ), true );
$ajax_actions = array(
	'start'    => 'store_audit_start',
	'check'    => 'store_audit_run_check',
	'finish'   => 'store_audit_finish',
	'markdown' => 'store_audit_markdown',
);
$post_actions = array(
	'download' => 'store_audit_download',
	'print'    => 'store_audit_print',
);

if ( $saveq ) {
	define( 'SAVEQUERIES', true );
}
define( 'WP_ADMIN', true );
if ( $is_ajax ) {
	define( 'DOING_AJAX', true );
}
if ( ! $is_ajax ) {
	define( 'WP_USE_THEMES', false );
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST']      = '127.0.0.1:8080';
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['SERVER_PORT']    = '8080';
$_SERVER['REQUEST_URI']    = $is_ajax ? '/wp-admin/admin-ajax.php' : '/wp-admin/admin-post.php';
$_SERVER['SCRIPT_NAME']    = $_SERVER['REQUEST_URI'];
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'perf-harness';

ob_start();

register_shutdown_function(
	static function () use ( &$M, $t0, $out, $saveq, $explain ) {
		$err = error_get_last();
		$fatal = null;
		if ( $err && in_array( $err['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ), true ) ) {
			$fatal = $err;
			@ini_set( 'memory_limit', '512M' ); // phpcs:ignore
			@set_time_limit( 120 ); // phpcs:ignore
		}
		if ( isset( $M['h0'] ) ) {
			$M['handler_ms'] = round( ( microtime( true ) - $M['h0'] ) * 1000, 1 );
			unset( $M['h0'] );
		}
		$M['wall_ms']      = round( ( microtime( true ) - $t0 ) * 1000, 1 );
		$M['peak_bytes']   = memory_get_peak_usage( true );
		$M['peak_used']    = memory_get_peak_usage( false );
		$M['memory_limit'] = ini_get( 'memory_limit' );
		$M['max_exec']     = ini_get( 'max_execution_time' );
		if ( $fatal ) {
			$msg = (string) $fatal['message'];
			$M['fatal'] = substr( $msg, 0, 300 );
			$M['fatal_kind'] = false !== stripos( $msg, 'Allowed memory size' ) ? 'memory' : ( false !== stripos( $msg, 'Maximum execution time' ) ? 'timeout' : 'fatal' );
			$M['fatal_where'] = ( $fatal['file'] ?? '' ) . ':' . ( $fatal['line'] ?? '' );
		}
		$body = '';
		while ( ob_get_level() > 0 ) {
			$body = ob_get_clean() . $body;
		}
		$M['body_bytes'] = strlen( $body );
		$trim = ltrim( $body );
		if ( '' !== $trim && ( '{' === $trim[0] ) ) {
			$j = json_decode( $trim, true );
			if ( is_array( $j ) ) {
				$M['http_json_success'] = $j['success'] ?? null;
				$data = $j['data'] ?? null;
				if ( is_array( $data ) ) {
					if ( isset( $data['run_id'] ) ) {
						$M['run_id'] = $data['run_id'];
					}
					if ( isset( $data['checks'] ) ) {
						$M['checks'] = array_map( static fn( $c ) => $c['id'], $data['checks'] );
					}
					if ( isset( $data['severity'] ) ) {
						$M['severity'] = $data['severity'];
						$M['summary']  = substr( (string) ( $data['summary'] ?? '' ), 0, 160 );
						$M['plugin_duration_ms'] = $data['duration_ms'] ?? null;
					}
					if ( isset( $data['message'] ) ) {
						$M['message'] = substr( (string) $data['message'], 0, 200 );
					}
					if ( isset( $data['markdown'] ) ) {
						$M['markdown_bytes'] = strlen( (string) $data['markdown'] );
					}
					if ( isset( $data['score'] ) ) {
						$M['score'] = $data['score']['overall'] ?? null;
					}
				}
			}
		} elseif ( '' !== $trim ) {
			$M['body_head'] = substr( $trim, 0, 200 );
		}
		if ( $fatal && ! isset( $M['fatal_where'] ) ) {
			$M['fatal_where'] = '';
		}

		global $wpdb;
		if ( isset( $wpdb ) && $wpdb instanceof wpdb ) {
			$M['queries_total'] = (int) $wpdb->num_queries;
			if ( isset( $M['q_before'] ) ) {
				$M['queries'] = $M['queries_total'] - $M['q_before'];
			}
			if ( $saveq && ! $fatal && isset( $M['q_before'] ) && is_array( $wpdb->queries ) ) {
				$M['analysis'] = perf_analyse( $wpdb, (int) $M['q_before'], $explain );
			}
		}
		file_put_contents( $out, json_encode( $M, JSON_PARTIAL_OUTPUT_ON_ERROR ) );
	}
);

/** Normalise a query to its shape. */
function perf_shape( string $sql ): string {
	$s = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
	$s = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', (string) $s );
	$s = preg_replace( '/IN\s*\((?:\s*\?\s*,?)+\)/i', 'IN (?)', (string) $s );
	$s = preg_replace( '/\s+/', ' ', (string) $s );
	return trim( (string) $s );
}

/** Query analysis for the handler window: slowest query, repeated shapes, EXPLAIN. */
function perf_analyse( wpdb $wpdb, int $from, bool $explain ): array {
	$rows   = array_slice( $wpdb->queries, $from );
	$slow   = null;
	$total  = 0.0;
	$shapes = array();
	foreach ( $rows as $q ) {
		$ms     = (float) $q[1] * 1000;
		$total += $ms;
		$sh     = perf_shape( (string) $q[0] );
		if ( ! isset( $shapes[ $sh ] ) ) {
			$shapes[ $sh ] = array( 'n' => 0, 'ms' => 0.0, 'sample' => (string) $q[0], 'caller' => (string) $q[2], 'max_ms' => 0.0 );
		}
		++$shapes[ $sh ]['n'];
		$shapes[ $sh ]['ms'] += $ms;
		if ( $ms > $shapes[ $sh ]['max_ms'] ) {
			$shapes[ $sh ]['max_ms'] = $ms;
			$shapes[ $sh ]['sample'] = (string) $q[0];
			$shapes[ $sh ]['caller'] = (string) $q[2];
		}
		if ( null === $slow || $ms > $slow['ms'] ) {
			$slow = array( 'ms' => round( $ms, 2 ), 'sql' => substr( (string) $q[0], 0, 700 ), 'caller' => (string) $q[2] );
		}
	}
	$res = array(
		'count'    => count( $rows ),
		'sql_ms'   => round( $total, 1 ),
		'slowest'  => $slow,
		'shapes'   => count( $shapes ),
		'n_plus_1' => array(),
		'explain'  => array(),
	);
	foreach ( $shapes as $sh => $d ) {
		if ( $d['n'] > 10 ) {
			$res['n_plus_1'][] = array( 'n' => $d['n'], 'ms' => round( $d['ms'], 1 ), 'sql' => substr( $d['sample'], 0, 400 ), 'caller' => $d['caller'] );
		}
	}
	usort( $res['n_plus_1'], static fn( $a, $b ) => $b['n'] <=> $a['n'] );
	if ( ! $explain ) {
		return $res;
	}
	$tbl_rows = array();
	$wpdb->suppress_errors( true );
	foreach ( $shapes as $sh => $d ) {
		$sql = ltrim( $d['sample'] );
		if ( 0 !== stripos( $sql, 'SELECT' ) && 0 !== stripos( $sql, 'WITH' ) ) {
			continue;
		}
		$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore
		if ( ! is_array( $plan ) ) {
			continue;
		}
		foreach ( $plan as $p ) {
			$t = (string) ( $p['table'] ?? '' );
			if ( '' === $t || '<' === $t[0] ) {
				continue;
			}
			if ( ! isset( $tbl_rows[ $t ] ) ) {
				$tbl_rows[ $t ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $t ) );
			}
			$type = (string) ( $p['type'] ?? '' );
			$res['explain'][] = array(
				'table'  => $t,
				'type'   => $type,
				'key'    => $p['key'] ?? null,
				'rows'   => (int) ( $p['rows'] ?? 0 ),
				'tbl_rows' => $tbl_rows[ $t ],
				'extra'  => substr( (string) ( $p['Extra'] ?? '' ), 0, 120 ),
				'flag'   => ( 'ALL' === $type && $tbl_rows[ $t ] > 10000 ) ? 'full_scan' : ( ( 'index' === $type && $tbl_rows[ $t ] > 10000 ) ? 'full_index_scan' : '' ),
				'n'      => $d['n'],
				'ms'     => round( $d['max_ms'], 2 ),
				'sql'    => substr( $sql, 0, 600 ),
				'caller' => $d['caller'],
			);
		}
	}
	$wpdb->suppress_errors( false );
	return $res;
}

if ( ! chdir( $site ) ) {
	fwrite( STDERR, "cannot enter $site\n" );
	exit( 2 );
}
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

global $wpdb;
$M['started']     = true;
$M['boot_ms']     = round( ( microtime( true ) - $t0 ) * 1000, 1 );
$M['boot_peak']   = memory_get_peak_usage( true );
$M['boot_used']   = memory_get_peak_usage( false );
$M['q_before']    = (int) $wpdb->num_queries;
$M['user']        = get_current_user_id();
$run_id           = getenv( 'PERF_RUN_ID' ) ?: '';

if ( 'boot' === $step ) {
	exit( 0 );
}

if ( $is_ajax ) {
	$action = $ajax_actions[ $step ] ?? '';
	@header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
	send_nosniff_header();
	nocache_headers();
	do_action( 'admin_init' );
	$_POST = $_REQUEST = array(
		'action'   => $action,
		'nonce'    => wp_create_nonce( 'store_audit' ),
		'run_id'   => $run_id,
		'check_id' => $check,
	);
	$M['q_before'] = (int) $wpdb->num_queries;
	$M['pre_handler_ms'] = round( ( microtime( true ) - $t0 ) * 1000, 1 );
	$M['h0'] = microtime( true );
	if ( has_action( 'wp_ajax_' . $action ) ) {
		do_action( 'wp_ajax_' . $action );
	} else {
		$M['message'] = 'no handler for ' . $action;
	}
	wp_die( '', '', array( 'response' => null ) );
}

$action = $post_actions[ $step ] ?? '';
$_POST = $_REQUEST = $_GET = array(
	'action'   => $action,
	'_wpnonce' => wp_create_nonce( 'store_audit_export' ),
);
send_nosniff_header();
nocache_headers();
do_action( 'admin_init' );
$M['q_before'] = (int) $wpdb->num_queries;
$M['pre_handler_ms'] = round( ( microtime( true ) - $t0 ) * 1000, 1 );
$M['h0'] = microtime( true );
if ( has_action( 'admin_post_' . $action ) ) {
	do_action( 'admin_post_' . $action );
} else {
	$M['message'] = 'no handler for ' . $action;
}
