<?php
/**
 * Compatibility matrix helper (development only, not shipped).
 *
 * Run with: MATRIX_ARG=smoke wp --user=admin eval-file bin/matrix-smoke.php
 * Modes (env MATRIX_ARG): smoke (default), leftovers.
 *
 * It drives the admin runner without a browser: the AJAX endpoints are called through do_action()
 * with wp_die() intercepted, the admin screens and exports are rendered into buffers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// Keep our own output buffered: once bytes reach stdout headers_sent() is true and wp_send_json() skips status_header().
ob_start();
register_shutdown_function(
	static function (): void {
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
	}
);

$GLOBALS['sa_steps'] = array();
$GLOBALS['sa_http']  = 200;
$GLOBALS['sa_ajax']  = true;

/**
 * Thrown from the wp_die handlers to stop a request like die() would.
 */
class SA_Matrix_Halt extends Exception {
	/** @var int|string */
	public $http;
}

/**
 * Record one step.
 *
 * @param string $name   Step name.
 * @param bool   $ok     Result.
 * @param string $detail Detail.
 */
function sa_step( string $name, bool $ok, string $detail = '' ): void {
	$GLOBALS['sa_steps'][] = array( $name, $ok );
	echo ( $ok ? 'STEP PASS ' : 'STEP FAIL ' ) . $name . ( '' !== $detail ? ' :: ' . $detail : '' ) . "\n";
}

/**
 * Run a callable as a step; exceptions become failures.
 *
 * @param string   $name Step name.
 * @param callable $fn   Returns true or a string detail on failure/success (false = fail).
 */
function sa_try( string $name, callable $fn ): void {
	try {
		$res = $fn();
		if ( true === $res || null === $res ) {
			sa_step( $name, true );
		} elseif ( false === $res ) {
			sa_step( $name, false );
		} else {
			// A string means a failure message.
			sa_step( $name, false, (string) $res );
		}
	} catch ( Throwable $e ) {
		sa_step( $name, false, get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
}

/**
 * Read a raw option value straight from the database (bypasses caches).
 *
 * @param string $name Option name.
 */
function sa_raw_option( string $name ): string {
	global $wpdb;
	$val = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore
	return null === $val ? '(none)' : md5( (string) $val );
}

/**
 * Hash of the option state the endpoints may touch.
 */
function sa_state_hash(): string {
	return sa_raw_option( 'store_audit_current_run' ) . '|' . sa_raw_option( 'store_audit_last_report' );
}

/**
 * Dispatch a wp_ajax_ action as a given user.
 *
 * @param string              $action  Action without prefix.
 * @param array<string,mixed> $post    POST fields.
 * @param int                 $user_id User id (0 = logged out).
 * @param bool                $nonce   Whether to send a valid nonce for that user.
 * @return array{code:int,json:mixed,raw:string}
 */
function sa_ajax( string $action, array $post, int $user_id, bool $nonce = true ): array {
	wp_set_current_user( $user_id );
	$post['action'] = $action;
	if ( $nonce ) {
		$post['nonce'] = wp_create_nonce( 'store_audit' );
	}
	$_POST              = $post;
	$_REQUEST           = $post;
	$_GET               = array();
	$GLOBALS['sa_http'] = 200;
	$GLOBALS['sa_ajax'] = true;
	ob_start();
	try {
		do_action( 'wp_ajax_' . $action );
	} catch ( SA_Matrix_Halt $e ) {
		unset( $e );
	}
	$raw = (string) ob_get_clean();
	return array(
		'code' => (int) $GLOBALS['sa_http'],
		'json' => json_decode( $raw, true ),
		'raw'  => $raw,
	);
}

/**
 * Dispatch an admin-post action; returns the status code passed to wp_die().
 *
 * @param string $action   admin-post action.
 * @param int    $user_id  User id.
 * @param bool   $nonce    Send a valid export nonce.
 * @return array{code:int|string,ended:bool,out:string}
 */
function sa_admin_post( string $action, int $user_id, bool $nonce ): array {
	wp_set_current_user( $user_id );
	$_GET     = array( 'action' => $action );
	$_POST    = array();
	$_REQUEST = array( 'action' => $action );
	if ( $nonce ) {
		$n                    = wp_create_nonce( \StoreAudit\Admin\Exports::NONCE_ACTION );
		$_REQUEST['_wpnonce'] = $n;
		$_GET['_wpnonce']     = $n;
	}
	$GLOBALS['sa_ajax'] = false;
	$code               = 0;
	$ended              = false;
	ob_start();
	try {
		do_action( 'admin_post_' . $action );
	} catch ( SA_Matrix_Halt $e ) {
		$ended = true;
		$code  = $e->http;
	}
	$out = (string) ob_get_clean();
	return array(
		'code'  => $code,
		'ended' => $ended,
		'out'   => $out,
	);
}

add_filter(
	'wp_doing_ajax',
	static function () {
		return ! empty( $GLOBALS['sa_ajax'] );
	}
);
$sa_die = static function ( $message = '', $title = '', $args = array() ) {
	$e       = new SA_Matrix_Halt( is_scalar( $message ) ? (string) $message : 'die' );
	$e->http = is_array( $args ) && isset( $args['response'] ) ? $args['response'] : 0;
	throw $e;
};
add_filter(
	'wp_die_ajax_handler',
	static function () use ( $sa_die ) {
		return $sa_die;
	}
);
add_filter(
	'wp_die_handler',
	static function () use ( $sa_die ) {
		return $sa_die;
	}
);
add_filter(
	'status_header',
	static function ( $header, $code ) {
		$GLOBALS['sa_http'] = (int) $code;
		return $header;
	},
	10,
	2
);

$mode = getenv( 'MATRIX_ARG' );
$mode = false === $mode || '' === $mode ? 'smoke' : $mode;

// ---------------------------------------------------------------------------------------------
if ( 'leftovers' === $mode ) {
	global $wpdb;
	$like = static function ( string $prefix ): string {
		global $wpdb;
		return $wpdb->esc_like( $prefix ) . '%';
	};
	$rows    = array();
	$wc_opts = 0;
	$wc_ver  = '';
	$blogs   = is_multisite() ? get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) : array( get_current_blog_id() );
	foreach ( $blogs as $bid ) {
		if ( is_multisite() ) {
			switch_to_blog( (int) $bid );
		}
		$found = $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$like( 'store_audit' ),
				$like( '_transient_store_audit' ),
				$like( '_transient_timeout_store_audit' ),
				$like( '_site_transient_store_audit' ),
				$like( '_site_transient_timeout_store_audit' )
			)
		);
		foreach ( $found as $name ) {
			$rows[] = 'blog' . $bid . ':' . $name;
		}
		$wc_opts += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like( 'woocommerce_' ) ) ); // phpcs:ignore
		if ( '' === $wc_ver ) {
			$wc_ver = (string) get_option( 'woocommerce_version', '' );
		}
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}
	if ( is_multisite() ) {
		$found = $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s",
				$like( 'store_audit' ),
				$like( '_site_transient_store_audit' ),
				$like( '_site_transient_timeout_store_audit' )
			)
		);
		foreach ( $found as $name ) {
			$rows[] = 'network:' . $name;
		}
	}
	echo 'LEFTOVERS ' . wp_json_encode(
		array(
			'plugin_rows' => $rows,
			'wc_options'  => $wc_opts,
			'wc_version'  => $wc_ver,
		)
	) . "\n";
	return;
}

// ---------------------------------------------------------------------------------------------
// Smoke.
echo 'SMOKE site=' . home_url() . ' wp=' . get_bloginfo( 'version' ) . ' php=' . PHP_VERSION . "\n";

sa_try(
	'plugin classes loaded',
	static function () {
		foreach ( array( '\StoreAudit\Plugin', '\StoreAudit\Ajax', '\StoreAudit\Registry', '\StoreAudit\Report', '\StoreAudit\Admin\Page', '\StoreAudit\Admin\Exports', '\StoreAudit\Admin\DashboardWidget' ) as $c ) {
			if ( ! class_exists( $c ) ) {
				return 'missing ' . $c;
			}
		}
		return true;
	}
);

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
if ( is_multisite() ) {
	$admins = array( get_user_by( 'login', 'admin' ) );
}
$admin_id = $admins && $admins[0] ? (int) $admins[0]->ID : 0;
sa_step( 'administrator exists', $admin_id > 0 );
if ( $admin_id <= 0 ) {
	echo "SMOKE FAIL steps=1 failed=1\n";
	WP_CLI::halt( 1 );
}
wp_set_current_user( $admin_id );
sa_step( 'admin has manage_woocommerce', current_user_can( 'manage_woocommerce' ) );

$sub_login = 'sa_matrix_sub';
$sub       = get_user_by( 'login', $sub_login );
$sub_id    = $sub ? (int) $sub->ID : (int) wp_create_user( $sub_login, wp_generate_password(), 'sa-matrix-sub@example.test' );
if ( $sub_id > 0 && ! is_wp_error( $sub_id ) ) {
	$u = new WP_User( $sub_id );
	$u->set_role( 'subscriber' );
}
if ( is_multisite() && $sub_id > 0 ) {
	add_user_to_blog( get_current_blog_id(), $sub_id, 'subscriber' );
}
sa_step( 'subscriber user ready', $sub_id > 0 && ! user_can( $sub_id, 'manage_woocommerce' ) );

// Admin screens before any report exists.
$render_screens = static function ( string $label ) use ( $admin_id ): void {
	sa_try(
		'admin page render (' . $label . ')',
		static function () use ( $admin_id ) {
			wp_set_current_user( $admin_id );
			$page = new \StoreAudit\Admin\Page();
			$page->add_menu();
			$ref = new ReflectionProperty( $page, 'hook_suffix' );
			$ref->setAccessible( true );
			$hook = (string) $ref->getValue( $page );
			if ( '' === $hook ) {
				return 'add_menu returned no hook suffix';
			}
			$page->enqueue( $hook );
			ob_start();
			$page->render();
			$html = (string) ob_get_clean();
			if ( false === strpos( $html, 'store-audit-run' ) || false === strpos( $html, 'class="wrap store-audit"' ) ) {
				return 'page markup incomplete (' . strlen( $html ) . ' bytes)';
			}
			if ( ! wp_script_is( 'store-audit-admin', 'registered' ) && ! wp_script_is( 'store-audit', 'registered' ) ) {
				global $wp_scripts;
				$handles = $wp_scripts instanceof WP_Scripts ? implode( ',', array_filter( array_keys( $wp_scripts->registered ), static fn( $h ) => false !== strpos( (string) $h, 'store-audit' ) ) ) : '';
				if ( '' === $handles ) {
					return 'admin script not registered';
				}
			}
			return true;
		}
	);
	sa_try(
		'dashboard widget (' . $label . ')',
		static function () use ( $admin_id ) {
			wp_set_current_user( $admin_id );
			require_once ABSPATH . 'wp-admin/includes/template.php';
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
			set_current_screen( 'dashboard' );
			$w = new \StoreAudit\Admin\DashboardWidget();
			$w->add_widget();
			global $wp_meta_boxes;
			$found = null;
			foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $ctx ) {
				foreach ( (array) $ctx as $prio ) {
					if ( isset( $prio[ \StoreAudit\Admin\DashboardWidget::WIDGET_ID ] ) ) {
						$found = $prio[ \StoreAudit\Admin\DashboardWidget::WIDGET_ID ];
					}
				}
			}
			if ( null === $found ) {
				return 'widget not registered in dashboard meta boxes';
			}
			$w->enqueue( 'index.php' );
			ob_start();
			call_user_func( $found['callback'], null, $found );
			$html = (string) ob_get_clean();
			if ( strlen( $html ) < 20 ) {
				return 'widget rendered almost nothing';
			}
			return true;
		}
	);
};
$render_screens( 'no report yet' );

sa_try(
	'markdown with no report is a clean 404',
	static function () use ( $admin_id ) {
		if ( null !== \StoreAudit\Report::get_last() ) {
			return true; // A report already exists from an earlier pass.
		}
		$r = sa_ajax( 'store_audit_markdown', array(), $admin_id );
		if ( 404 !== $r['code'] || ! is_array( $r['json'] ) || true === ( $r['json']['success'] ?? null ) ) {
			return 'expected 404 error, got ' . $r['code'] . ' ' . substr( $r['raw'], 0, 120 );
		}
		return true;
	}
);

// Runner cycle exactly as admin.js does it: start, run_check for each, finish, markdown.
$run_id    = '';
$check_ids = array();
sa_try(
	'ajax start',
	static function () use ( $admin_id, &$run_id, &$check_ids ) {
		$r = sa_ajax( 'store_audit_start', array(), $admin_id );
		if ( ! is_array( $r['json'] ) || true !== ( $r['json']['success'] ?? false ) ) {
			return 'start failed: ' . $r['code'] . ' ' . substr( $r['raw'], 0, 200 );
		}
		$d = $r['json']['data'];
		if ( empty( $d['run_id'] ) || empty( $d['checks'] ) || empty( $d['sections'] ) ) {
			return 'start payload incomplete';
		}
		$run_id = (string) $d['run_id'];
		foreach ( $d['checks'] as $c ) {
			if ( ! isset( $c['id'], $c['title'], $c['section'] ) ) {
				return 'check descriptor incomplete';
			}
			$check_ids[] = (string) $c['id'];
		}
		return true;
	}
);

// Authorization: subscriber, logged out, and admin without nonce must get 403 and change nothing.
$negatives = static function ( string $phase ) use ( &$run_id, &$check_ids, $sub_id, $admin_id ): void {
	$actions = array(
		'store_audit_start'     => array(),
		'store_audit_run_check' => array(
			'run_id'   => $run_id,
			'check_id' => $check_ids[0] ?? 'x',
		),
		'store_audit_finish'    => array( 'run_id' => $run_id ),
		'store_audit_markdown'  => array(),
	);
	foreach ( $actions as $action => $post ) {
		$scenarios = array(
			'subscriber'      => array( $sub_id, true ),
			'logged out'      => array( 0, true ),
			'admin, no nonce' => array( $admin_id, false ),
		);
		foreach ( $scenarios as $label => $sc ) {
			sa_try(
				'403 ' . $action . ' as ' . $label . ' (' . $phase . ')',
				static function () use ( $action, $post, $sc ) {
					$before = sa_state_hash();
					$r      = sa_ajax( $action, $post, $sc[0], $sc[1] );
					$after  = sa_state_hash();
					wp_set_current_user( 0 );
					if ( 403 !== $r['code'] ) {
						return 'expected HTTP 403, got ' . $r['code'] . ' body ' . substr( $r['raw'], 0, 120 );
					}
					if ( ! is_array( $r['json'] ) || false !== ( $r['json']['success'] ?? null ) ) {
						return 'expected success=false JSON';
					}
					if ( $before !== $after ) {
						return 'options changed by a rejected request';
					}
					return true;
				}
			);
		}
	}
};
if ( '' !== $run_id ) {
	$negatives( 'run active' );

	$ran    = 0;
	$failed = array();
	foreach ( $check_ids as $cid ) {
		$r = sa_ajax(
			'store_audit_run_check',
			array(
				'run_id'   => $run_id,
				'check_id' => $cid,
			),
			$admin_id
		);
		$ok = is_array( $r['json'] ) && true === ( $r['json']['success'] ?? false ) && isset( $r['json']['data']['id'], $r['json']['data']['severity'] ) && $r['json']['data']['id'] === $cid;
		if ( $ok ) {
			++$ran;
		} else {
			$failed[] = $cid . '(' . $r['code'] . ')';
		}
	}
	sa_step( 'ajax run_check for every check (' . $ran . '/' . count( $check_ids ) . ')', array() === $failed && $ran > 0, implode( ',', $failed ) );

	sa_try(
		'run_check with a stale run id is rejected',
		static function () use ( $admin_id, $check_ids ) {
			$r = sa_ajax(
				'store_audit_run_check',
				array(
					'run_id'   => 'does-not-exist',
					'check_id' => $check_ids[0],
				),
				$admin_id
			);
			return 409 === $r['code'] ? true : 'expected 409, got ' . $r['code'];
		}
	);
	sa_try(
		'run_check with an unknown check id is rejected',
		static function () use ( $admin_id, $run_id ) {
			$r = sa_ajax(
				'store_audit_run_check',
				array(
					'run_id'   => $run_id,
					'check_id' => 'no_such_check',
				),
				$admin_id
			);
			return 404 === $r['code'] ? true : 'expected 404, got ' . $r['code'];
		}
	);

	sa_try(
		'ajax finish',
		static function () use ( $admin_id, $run_id, $check_ids ) {
			$r = sa_ajax( 'store_audit_finish', array( 'run_id' => $run_id ), $admin_id );
			if ( ! is_array( $r['json'] ) || true !== ( $r['json']['success'] ?? false ) ) {
				return 'finish failed: ' . $r['code'] . ' ' . substr( $r['raw'], 0, 200 );
			}
			$d = $r['json']['data'];
			if ( ! isset( $d['score']['overall'] ) || ! is_int( $d['score']['overall'] ) || empty( $d['results'] ) ) {
				return 'finish payload incomplete';
			}
			if ( count( $d['results'] ) !== count( $check_ids ) ) {
				return 'result count ' . count( $d['results'] ) . ' != checks ' . count( $check_ids );
			}
			if ( '(none)' !== sa_raw_option( 'store_audit_current_run' ) ) {
				return 'current run option not cleared';
			}
			if ( '(none)' === sa_raw_option( 'store_audit_last_report' ) ) {
				return 'last report not stored';
			}
			return true;
		}
	);
	sa_try(
		'ajax markdown',
		static function () use ( $admin_id ) {
			$r = sa_ajax( 'store_audit_markdown', array(), $admin_id );
			if ( ! is_array( $r['json'] ) || true !== ( $r['json']['success'] ?? false ) ) {
				return 'markdown failed: ' . $r['code'];
			}
			$md = (string) ( $r['json']['data']['markdown'] ?? '' );
			return strlen( $md ) > 200 && false !== strpos( $md, '#' ) ? true : 'markdown too short';
		}
	);
	$negatives( 'report stored' );
}

// Screens and exports with a stored report.
$render_screens( 'report stored' );

sa_try(
	'export JSON is valid',
	static function () use ( $admin_id ) {
		wp_set_current_user( $admin_id );
		$report = \StoreAudit\Report::get_last();
		if ( null === $report ) {
			return 'no stored report';
		}
		$json = \StoreAudit\Report::to_json( $report );
		$back = json_decode( $json, true );
		if ( ! is_array( $back ) || ! isset( $back['score']['overall'], $back['results'] ) ) {
			return 'invalid JSON export';
		}
		$name = \StoreAudit\Report::filename( $report, 'json' );
		return '' !== $name ? true : 'empty filename';
	}
);
sa_try(
	'print HTML renders',
	static function () use ( $admin_id ) {
		wp_set_current_user( $admin_id );
		$report = \StoreAudit\Report::get_last();
		$html   = \StoreAudit\Admin\Exports::render_document( (array) $report );
		if ( strlen( $html ) < 500 || false === stripos( $html, '<html' ) || false === stripos( $html, '</html>' ) ) {
			return 'document incomplete (' . strlen( $html ) . ' bytes)';
		}
		return true;
	}
);
sa_try(
	'markdown export renders',
	static function () {
		$md = \StoreAudit\Report::to_markdown( (array) \StoreAudit\Report::get_last() );
		return strlen( $md ) > 200 ? true : 'too short';
	}
);
foreach ( array( 'store_audit_download', 'store_audit_print' ) as $action ) {
	foreach ( array(
		'subscriber'      => array( $sub_id, true ),
		'logged out'      => array( 0, true ),
		'admin, no nonce' => array( $admin_id, false ),
	) as $label => $sc ) {
		sa_try(
			'403 ' . $action . ' as ' . $label,
			static function () use ( $action, $sc ) {
				$r = sa_admin_post( $action, $sc[0], $sc[1] );
				wp_set_current_user( 0 );
				if ( ! $r['ended'] || 403 !== (int) $r['code'] ) {
					return 'expected wp_die 403, got ended=' . ( $r['ended'] ? '1' : '0' ) . ' code=' . $r['code'];
				}
				if ( false !== strpos( $r['out'], 'results' ) && false !== strpos( $r['out'], 'score' ) ) {
					return 'report leaked in rejected response';
				}
				return true;
			}
		);
	}
}

// Leave the run state clean so a next pass starts from the same place.
if ( $sub_id > 0 ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $sub_id );
	} else {
		wp_delete_user( $sub_id );
	}
}

$total  = count( $GLOBALS['sa_steps'] );
$failed = count( array_filter( $GLOBALS['sa_steps'], static fn( $s ) => ! $s[1] ) );
echo ( 0 === $failed ? 'SMOKE PASS' : 'SMOKE FAIL' ) . " steps=$total failed=$failed\n";
if ( $failed > 0 ) {
	WP_CLI::halt( 1 );
}
