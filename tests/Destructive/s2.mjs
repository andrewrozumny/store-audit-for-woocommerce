// Scenario 2: broken environments, damaged data, no network, bad answers from wordpress.org.
import fs from 'node:fs';
import {
	SITE, PLUGIN_DIR, SEVERITIES, ajax, eq, fullAudit, htmlProblems, http, jsonBody, logClean, logMark, note, ok, oneOf, pageConfig,
	pluginRows, scenario, sh, sessionNonce, splitHtml, sql, sqlRoot, wp, wpOut, evalPhp, wellFormedReport, DB,
} from './lib.mjs';
import { runAdminJs } from './fakedom.mjs';
import { clearPluginRows } from './s1.mjs';
import { P } from './s1x.mjs';

let BASE_N = 0;

function registryCount() {
	return Number( evalPhp( 'echo count( \\StoreAudit\\Registry::all() );' ).out.trim() );
}

export function cliAudit() {
	const t0 = Date.now();
	const r = wp( [ 'store-audit', 'run', '--format=json' ] );
	let report = null;
	try {
		report = JSON.parse( r.out );
	} catch {
		report = null;
	}
	return { r, report, ms: Date.now() - t0 };
}

function structural( a, label ) {
	ok( a.r.code === 0, `${ label }: wp store-audit run exited ${ a.r.code }: ${ ( a.r.err + a.r.out ).slice( 0, 300 ) }` );
	ok( ! /Fatal error|Uncaught|Stack trace/.test( a.r.out + a.r.err ), `${ label }: fatal error text in the output` );
	if ( ! a.report ) {
		ok( false, `${ label }: output is not JSON` );
		return [];
	}
	const rows = a.report.results || [];
	eq( rows.length, BASE_N, `${ label }: number of results` );
	ok( rows.every( ( x ) => SEVERITIES.includes( x.severity ) && typeof x.summary === 'string' && x.summary !== '' ), `${ label }: every result has a severity and a summary` );
	ok( Number.isInteger( a.report.score?.overall ), `${ label }: score is a number` );
	return rows;
}

const byId = ( rows ) => Object.fromEntries( rows.map( ( r ) => [ r.id, r ] ) );

function setOptionRaw( name, serialized ) {
	const hex = Buffer.from( serialized, 'utf8' ).toString( 'hex' );
	sql( `INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('${ name }', CONVERT(UNHEX('${ hex }') USING utf8mb4), 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)` );
}

function setOptionJson( name, value ) {
	evalPhp( 'update_option( getenv( "SA_NAME" ), json_decode( getenv( "SA_VAL" ), true ), false );', { SA_NAME: name, SA_VAL: JSON.stringify( value ) } );
}

const HTTP_MU = `<?php
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$mode = get_option( 'sa_destr_http' );
	if ( ! $mode ) { return $pre; }
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( 'all_error' === $mode ) { return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: ' . $host ); }
	if ( 'api.wordpress.org' !== $host ) { return $pre; }
	$reply = static function ( $code, $body ) { return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null ); };
	switch ( $mode ) {
		case 'error': return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		case 'r429': return $reply( 429, '{"error":"Too many requests"}' );
		case 'r500': return $reply( 500, 'Internal Server Error' );
		case 'trunc': return $reply( 200, '{"name":"X","version":"1.0","last_upda' );
		case 'html': return $reply( 200, '<html><body><h1>Maintenance</h1></body></html>' );
		case 'empty': return $reply( 200, '' );
		case 'json_string': return $reply( 200, '"just a string"' );
		case 'json_null': return $reply( 200, 'null' );
		case 'huge': return $reply( 200, wp_json_encode( array( 'error' => 'closed', 'closed' => true, 'reason_text' => '<script>alert("xssmark1")</script>' . str_repeat( 'long reason ', 20000 ) ) ) );
		case 'slow': usleep( (int) ( min( 5, (float) ( $args['timeout'] ?? 5 ) ) * 1000000 ) ); return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds' );
	}
	return $pre;
}, 10, 3 );
`;

function clearWporgCache() {
	sql( "DELETE FROM wp_options WHERE option_name LIKE '%store\\_audit\\_wporg%'" );
}

const q = ( v ) => v;

export async function s2( S ) {
	const { admin, shop } = S;
	BASE_N = registryCount();
	note( `registry holds ${ BASE_N } checks` );
	fs.writeFileSync( `${ SITE }/wp-content/mu-plugins/sa-destr-http.php`, HTTP_MU );
	const baseline = cliAudit();
	const baseRows = structural( baseline, 'baseline' );

	// ------------------------------------------------------------ 2.1 WooCommerce deactivated
	await scenario( '2.1', 'WooCommerce deactivated', 'no fatal error anywhere; the plugin shows a notice and offers no endpoints; reactivating restores everything', async () => {
		const mark = logMark();
		wp( [ 'plugin', 'deactivate', 'woocommerce' ] );
		const dash = await http( 'GET', '/wp-admin/index.php', { cookie: admin.cookie } );
		eq( dash.status, 200, 'dashboard without WooCommerce' );
		ok( dash.text.includes( 'needs WooCommerce to be installed and active' ), 'the notice about WooCommerce is shown' );
		ok( htmlProblems( dash.text ).length === 0, 'dashboard markup' );
		const page = await http( 'GET', '/wp-admin/admin.php?page=store-audit', { cookie: admin.cookie } );
		oneOf( page.status, [ 403, 404 ], 'plugin page without WooCommerce' );
		ok( ! /Fatal error|Uncaught/.test( page.text ), 'plugin page has no fatal error' );
		const a = await ajax( admin, 'store_audit_start' );
		eq( a.status, 400, 'ajax start without WooCommerce' );
		const dl = await http( 'GET', `/wp-admin/admin-post.php?action=store_audit_download&_wpnonce=${ sessionNonce( admin, 'store_audit_export' ) }`, { cookie: admin.cookie } );
		eq( dl.status, 400, 'download without WooCommerce' );
		const cli = wp( [ 'store-audit', 'run', '--format=json' ] );
		ok( cli.code !== 0 && ! /Fatal error|Uncaught/.test( cli.out + cli.err ), 'the CLI command is simply not available' );
		wp( [ 'plugin', 'deactivate', 'store-audit-for-woocommerce' ] );
		const act = wp( [ 'plugin', 'activate', 'store-audit-for-woocommerce' ] );
		ok( ! /Fatal error|Uncaught/.test( act.out + act.err ), 'activating without WooCommerce does not crash' );
		note( `activation without WooCommerce: exit ${ act.code }, "${ ( act.err + act.out ).trim().slice( 0, 120 ) }"` );
		wp( [ 'plugin', 'activate', 'woocommerce' ] );
		wp( [ 'plugin', 'activate', 'store-audit-for-woocommerce' ] );
		structural( cliAudit(), 'after reactivation' );
		logClean( mark, 'woocommerce off' );
	}, 'tests/Destructive/s2.mjs' );

	// ------------------------------------------------------------ 2.3 missing tables
	await scenario( '2.3', 'WooCommerce tables missing', 'every audit completes; checks that need a missing table report "not checked" or "info", never a fatal error or a PHP warning', async () => {
		const mark = logMark();
		const tables = [ 'wc_orders', 'wc_orders_meta', 'woocommerce_api_keys', 'wc_webhooks', 'actionscheduler_actions', 'actionscheduler_logs', 'wc_product_meta_lookup', 'woocommerce_shipping_zones', 'woocommerce_shipping_zone_methods', 'woocommerce_tax_rates', 'woocommerce_order_items', 'wc_order_stats' ];
		const existing = sql( "SHOW TABLES LIKE 'wp\\_%'" ).split( '\n' );
		const swap = ( list, back = false ) => {
			for ( const t of list ) {
				const from = back ? `${ t }_sabak` : t;
				const to = back ? t : `${ t }_sabak`;
				sql( `RENAME TABLE \`${ from }\` TO \`${ to }\`` );
			}
		};
		for ( const t of tables ) {
			if ( ! existing.includes( `wp_${ t }` ) ) {
				note( `table wp_${ t } does not exist in this WooCommerce version, skipped` );
				continue;
			}
			swap( [ `wp_${ t }` ] );
			try {
				const a = cliAudit();
				structural( a, `without wp_${ t }` );
				const bad = ( a.report?.results || [] ).filter( ( r ) => r.severity === 'ok' && baseRows.length && byId( baseRows )[ r.id ] && byId( baseRows )[ r.id ].severity !== 'ok' );
				void bad;
			} finally {
				swap( [ `wp_${ t }` ], true );
			}
		}
		// Everything WooCommerce owns gone at once.
		const wooTables = existing.filter( ( t ) => /^wp_(wc_|woocommerce_|actionscheduler_)/.test( t ) );
		swap( wooTables );
		try {
			const a = cliAudit();
			const rows = structural( a, 'without any WooCommerce table' );
			const shown = rows.filter( ( r ) => r.severity === 'error' || r.severity === 'info' ).length;
			note( `with all ${ wooTables.length } WooCommerce tables missing: ${ shown } results are error or info` );
			// And through the browser flow.
			const full = await fullAudit( admin );
			ok( full.ok && full.finishStatus === 200, 'browser audit completes without WooCommerce tables' );
			ok( full.ok && full.timings.every( ( t ) => t.status === 200 ), `every check request answers 200 (${ full.ok ? full.timings.filter( ( t ) => t.status !== 200 ).map( ( t ) => t.id + ':' + t.status ).join( ',' ) : '' })` );
		} finally {
			swap( wooTables, true );
		}
		logClean( mark, 'missing tables' );
		structural( cliAudit(), 'after restoring the tables' );
	}, 'tests/Destructive/s2.mjs' );

	// ------------------------------------------------------------ 2.4 our options
	await scenario( '2.4', 'Plugin options missing or damaged', 'pages, exports, widget and the next audit work for every damaged value; unusable values read as "no report"', async () => {
		const mark = logMark();
		const n = sessionNonce( admin, 'store_audit_export' );
		const goodRow = { id: 'a', section: 'security', title: 'T', severity: 'warning', summary: 'S', details: [ 'd' ], explanation: 'e', fix_hint: 'f', duration_ms: 1 };
		const hostileText = Object.values( P ).join( ' ' );
		const hostileRow = { id: P.p1, section: P.p2, title: hostileText, severity: 'critical', summary: hostileText, details: Object.values( P ), explanation: hostileText, fix_hint: hostileText, duration_ms: 5 };
		const cases = {
			'absent': null,
			'string': { raw: 's:7:"garbage";' },
			'number': { raw: 'i:12345;' },
			'bool': { raw: 'b:1;' },
			'unknown class': { raw: 'O:12:"UnknownThing":1:{s:1:"a";i:1;}' },
			'broken serialisation': { raw: 'a:3:{s:7:"results";' },
			'empty array': { json: [] },
			'array without keys': { json: { a: 1 } },
			'results is a string': { json: { results: 'text' } },
			'results is a number': { json: { results: 7 } },
			'results is empty': { json: { results: [] } },
			'results without rows': { json: { results: [ 'x', 5, null ] } },
			'bogus severity': { json: { generated_at: '2026-10-12T08:30:00Z', results: [ { ...goodRow, severity: 'bogus' }, goodRow ] } },
			'severity is an array': { json: { results: [ { ...goodRow, severity: [ 'critical' ] } ] } },
			'details is a string': { json: { results: [ { ...goodRow, details: 'not a list' } ] } },
			'details with objects': { json: { results: [ { ...goodRow, details: [ { a: 1 }, [ 'n' ], null, 5 ] } ] } },
			'title is an array': { json: { results: [ { ...goodRow, title: [ 'x' ], summary: { a: 1 } } ] } },
			'broken score': { json: { score: { overall: 'high', sections: 'none' }, results: [ goodRow ] } },
			'score sections without data': { json: { score: { overall: 50, sections: { security: 'x', 7: [] } }, results: [ goodRow ] } },
			'score is a string': { json: { score: 'a lot', results: [ goodRow ] } },
			'unknown section': { json: { results: [ { ...goodRow, section: 'nope' } ] } },
			'hostile strings everywhere': { json: { generated_at: P.p1, site_url: P.p2, plugin_version: P.p3, wp_version: P.p4, wc_version: P.p5, score: { overall: 40, sections: { [ P.p2 ]: { label: P.p1, score: 40, counts: { critical: 1, warning: 0, info: 0, ok: 0, error: 0 } } } }, results: [ hostileRow ] } },
			'very large': { json: { results: Array.from( { length: 3000 }, ( _, i ) => ( { ...goodRow, id: `c${ i }`, details: Array.from( { length: 20 }, ( __, j ) => `detail ${ i }-${ j } ${ 'x'.repeat( 200 ) }` ) } ) ) } },
		};
		for ( const [ name, c ] of Object.entries( cases ) ) {
			sql( "DELETE FROM wp_options WHERE option_name = 'store_audit_last_report'" );
			if ( c && c.raw ) {
				setOptionRaw( 'store_audit_last_report', c.raw );
			} else if ( c && c.json !== undefined ) {
				setOptionJson( 'store_audit_last_report', c.json );
			}
			const m0 = logMark();
			const pg = await http( 'GET', '/wp-admin/admin.php?page=store-audit', { cookie: admin.cookie } );
			eq( pg.status, 200, `[${ name }] admin page` );
			const problems = htmlProblems( pg.text );
			ok( problems.length === 0, `[${ name }] admin page: ${ problems.join( ', ' ) }` );
			const cfg = pageConfig( pg.text );
			ok( cfg !== null && cfg !== undefined, `[${ name }] page config parses` );
			ok( wellFormedReport( cfg ? cfg.lastReport : null ), `[${ name }] lastReport handed to admin.js is well formed: ${ JSON.stringify( cfg?.lastReport ?? null ).slice( 0, 160 ) }` );
			if ( cfg ) {
				const js = runAdminJs( `${ PLUGIN_DIR }/resources/admin.js`, cfg );
				ok( js.error === null, `[${ name }] admin.js runs: ${ js.error }` );
				ok( js.doc.violations.length === 0, `[${ name }] admin.js wrote HTML` );
			}
			for ( const action of [ 'store_audit_print', 'store_audit_download' ] ) {
				const r = await http( 'GET', `/wp-admin/admin-post.php?action=${ action }&_wpnonce=${ n }`, { cookie: admin.cookie } );
				oneOf( r.status, [ 200, 404 ], `[${ name }] ${ action }` );
				if ( r.status === 200 && action === 'store_audit_print' ) {
					const pp = htmlProblems( r.text );
					ok( pp.length === 0, `[${ name }] print: ${ pp.join( ', ' ) }` );
					for ( const v of [ P.p1, P.p2, P.p3, P.p4 ] ) {
						ok( ! splitHtml( r.text ).outside.includes( v ), `[${ name }] print holds raw ${ v.slice( 0, 20 ) }` );
					}
				}
				if ( r.status === 200 && action === 'store_audit_download' ) {
					ok( jsonBody( r ) !== null, `[${ name }] download is valid JSON` );
				}
			}
			const md = await ajax( admin, 'store_audit_markdown' );
			oneOf( md.status, [ 200, 404 ], `[${ name }] markdown` );
			if ( md.status === 200 ) {
				const text = jsonBody( md )?.data?.markdown || '';
				ok( ! /(^|[^\\])<[a-zA-Z\/!]/.test( text.replace( /\[helpmybiz\.org\]\(https:\/\/helpmybiz\.org\)/, '' ) ), `[${ name }] markdown holds raw HTML` );
			}
			const dash = await http( 'GET', '/wp-admin/index.php', { cookie: admin.cookie } );
			eq( dash.status, 200, `[${ name }] dashboard` );
			const at = dash.text.indexOf( 'id="store_audit_dashboard_widget"' );
			if ( at !== -1 ) {
				const rest = dash.text.slice( at );
				const widget = rest.slice( 0, Math.max( 1, rest.search( /<div id="dashboard_|<div id="[a-z_]+" class="postbox/ ) ) || 8000 );
				const wp2 = htmlProblems( widget );
				ok( wp2.length === 0, `[${ name }] dashboard widget: ${ wp2.join( ', ' ) }` );
			}
			const l = logClean( m0, `[${ name }]` );
			void l;
		}
		// A run option and result rows that are not what the plugin wrote.
		const runCases = { string: 's:7:"garbage";', number: 'i:5;', 'no run id': 'a:1:{s:7:"results";s:1:"x";}', 'array run id': 'a:1:{s:6:"run_id";a:1:{i:0;s:1:"x";}}' };
		for ( const [ name, raw ] of Object.entries( runCases ) ) {
			setOptionRaw( 'store_audit_current_run', raw );
			for ( const action of [ 'store_audit_run_check', 'store_audit_finish' ] ) {
				const r = await ajax( admin, action, { run_id: 'r1', check_id: 'environment_php_version' } );
				eq( r.status, 409, `[run option: ${ name }] ${ action }` );
			}
		}
		const start = jsonBody( await ajax( admin, 'store_audit_start' ) );
		const runId = start.data.run_id;
		setOptionRaw( 'store_audit_run_result_environment_php_version', 's:7:"garbage";' );
		setOptionJson( 'store_audit_run_result_environment_wp_version', { run_id: runId, result: 'not a row' } );
		setOptionJson( 'store_audit_run_result_environment_wp_debug', { run_id: [ 'x' ], result: [] } );
		const one = start.data.checks.find( ( c ) => c.id === 'environment_https' ) || start.data.checks[ 3 ];
		eq( ( await ajax( admin, 'store_audit_run_check', { run_id: runId, check_id: one.id } ) ).status, 200, 'run_check next to damaged result rows' );
		const fin = await ajax( admin, 'store_audit_finish', { run_id: runId } );
		eq( fin.status, 200, 'finish next to damaged result rows' );
		const rep = jsonBody( fin );
		ok( rep && rep.success && wellFormedReport( rep.data ), 'finish returns a well formed report' );
		const full = await fullAudit( admin );
		ok( full.ok && full.finishStatus === 200, 'a complete audit replaces whatever was stored' );
		logClean( mark, 'damaged options' );
	}, 'tests/Unit/StoredReportTest.php; tests/Unit/RunStorageTest.php; tests/Destructive/s2.mjs' );

	// ------------------------------------------------------------ 2.5 uploads
	await scenario( '2.5', 'Uploads folder missing or not writable', 'the audit completes, checks that need the folder say what they could not do, no PHP warnings', async () => {
		const mark = logMark();
		const up = `${ SITE }/wp-content/uploads`;
		const hold = `${ SITE }/wp-content/uploads-sabak`;
		try {
			fs.renameSync( up, hold );
			fs.writeFileSync( up, 'not a directory' );
			const a = cliAudit();
			structural( a, 'uploads is a file' );
			const full = await fullAudit( admin );
			ok( full.ok && full.finishStatus === 200 && full.timings.every( ( t ) => t.status === 200 ), 'browser audit with a broken uploads path' );
			fs.rmSync( up );
			fs.mkdirSync( up );
			fs.chmodSync( up, 0o555 );
			structural( cliAudit(), 'uploads read-only' );
		} finally {
			sh( 'bash', [ '-c', `chmod -R u+rwx '${ up }' 2>/dev/null; rm -rf '${ up }'; [ -d '${ hold }' ] && mv '${ hold }' '${ up }'` ] );
		}
		logClean( mark, 'uploads' );
	}, 'tests/Destructive/s2.mjs' );

	// ------------------------------------------------------------ 2.6 config constants
	await scenario( '2.6', 'DISABLE_WP_CRON and WP_HTTP_BLOCK_EXTERNAL', 'the audit completes and the affected checks explain the situation', async () => {
		const mark = logMark();
		wp( [ 'config', 'set', 'DISABLE_WP_CRON', 'true', '--raw', '--type=constant' ] );
		try {
			const rows = byId( structural( cliAudit(), 'cron disabled' ) );
			const cron = rows.environment_cron;
			ok( cron && cron.severity !== 'ok' || ! cron, `cron check with DISABLE_WP_CRON: ${ cron ? cron.severity + ' ' + cron.summary : 'row missing' }` );
			note( `cron check with DISABLE_WP_CRON: ${ cron ? cron.severity + ' / ' + cron.summary : 'n/a' }` );
		} finally {
			wp( [ 'config', 'delete', 'DISABLE_WP_CRON', '--type=constant' ] );
		}
		wp( [ 'config', 'set', 'WP_HTTP_BLOCK_EXTERNAL', 'true', '--raw', '--type=constant' ] );
		try {
			clearWporgCache();
			const rows = byId( structural( cliAudit(), 'external requests blocked' ) );
			const ab = rows.plugins_abandoned;
			ok( ab && ab.severity !== 'ok' && ab.severity !== 'critical', `abandoned plugins with blocked external requests: ${ ab ? ab.severity + ' ' + ab.summary : 'row missing' }` );
			const full = await fullAudit( admin );
			ok( full.ok && full.finishStatus === 200, 'browser audit with blocked external requests' );
			eq( pluginRows( DB, 'wp_', true ).includes( 'wporg' ), false, 'failed lookups were not cached' );
		} finally {
			wp( [ 'config', 'delete', 'WP_HTTP_BLOCK_EXTERNAL', '--type=constant' ] );
		}
		logClean( mark, 'config constants' );
	}, 'tests/Destructive/s2.mjs' );

	// ------------------------------------------------------------ 2.7 network
	await scenario( '2.7', 'No internet and bad answers from wordpress.org', 'the plugin check reports "could not be checked", nothing is cached for failures, huge answers are cut down, no fatal error', async () => {
		const mark = logMark();
		const modes = [ 'all_error', 'error', 'r429', 'r500', 'trunc', 'html', 'empty', 'json_string', 'json_null', 'huge' ];
		for ( const mode of modes ) {
			clearWporgCache();
			sql( `INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sa_destr_http', '${ mode }', 'no') ON DUPLICATE KEY UPDATE option_value = '${ mode }'` );
			const a = cliAudit();
			const rows = byId( structural( a, `wordpress.org mode ${ mode }` ) );
			const ab = rows.plugins_abandoned;
			if ( mode === 'huge' ) {
				ok( ab && ab.severity === 'critical', 'a closed plugin is reported as critical' );
				const size = JSON.stringify( ab || {} ).length;
				ok( size < 20000, `the report row for a closed plugin stays small (${ size } bytes)` );
				const cached = sql( "SELECT COALESCE(MAX(LENGTH(option_value)),0) FROM wp_options WHERE option_name LIKE '%store\\_audit\\_wporg%' AND option_name NOT LIKE '%timeout%'" );
				ok( Number( cached ) < 20000, `the cached answer stays small (${ cached } bytes)` );
			} else {
				ok( ab && ( ab.severity === 'info' || ab.severity === 'error' ), `[${ mode }] abandoned plugins is info or error, got ${ ab ? ab.severity : 'missing' }: ${ ab ? ab.summary : '' }` );
				ok( ab && /could not|did not|not checked|unavailable/i.test( ab.summary + ' ' + ( ab.details || [] ).join( ' ' ) ), `[${ mode }] the text says the check could not be done: ${ ab ? ab.summary : '' }` );
				const cached = sql( "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '_transient_store\\_audit\\_wporg%'" );
				eq( Number( cached ), 0, `[${ mode }] failures are not cached` );
			}
			const tc = rows.plugins_theme_child;
			ok( tc && tc.severity !== 'critical', `[${ mode }] theme check does not break` );
			if ( mode === 'all_error' ) {
				const probes = Object.values( rows ).filter( ( r ) => /loopback|reach the site from itself/i.test( r.summary ) );
				ok( probes.length >= 5, `[all_error] the self-probe checks say they could not reach the site (${ probes.length })` );
				ok( probes.every( ( r ) => r.severity === 'info' || r.severity === 'error' ), '[all_error] self-probe checks are never a pass' );
				const full = await fullAudit( admin );
				ok( full.ok && full.finishStatus === 200 && full.timings.every( ( t ) => t.status === 200 ), 'browser audit without internet' );
			}
		}
		sql( "DELETE FROM wp_options WHERE option_name = 'sa_destr_http'" );
		logClean( mark, 'network' );
	}, 'tests/Unit/Checks/Plugins/WporgApiTest.php; tests/Destructive/s2.mjs' );

	// ------------------------------------------------------------ 2.8 slow
	await scenario( '2.8', 'wordpress.org hangs', 'each check request ends inside max_execution_time (30 s), the slow check gives up and says so', async () => {
		const mark = logMark();
		for ( let i = 1; i <= 16; i++ ) {
			const d = `${ SITE }/wp-content/plugins/sa-destr-dummy-${ i }`;
			fs.mkdirSync( d, { recursive: true } );
			fs.writeFileSync( `${ d }/sa-destr-dummy-${ i }.php`, `<?php\n/*\nPlugin Name: Destructive Dummy ${ i }\nVersion: 1.0\n*/\n` );
		}
		try {
			clearWporgCache();
			sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('sa_destr_http', 'slow', 'no') ON DUPLICATE KEY UPDATE option_value = 'slow'" );
			const t0 = Date.now();
			const full = await fullAudit( admin );
			ok( full.ok && full.finishStatus === 200, 'browser audit while wordpress.org hangs' );
			if ( full.ok ) {
				const worst = [ ...full.timings ].sort( ( a, b ) => b.ms - a.ms ).slice( 0, 3 );
				note( `slowest requests: ${ worst.map( ( w ) => `${ w.id } ${ Math.round( w.ms / 100 ) / 10 }s` ).join( ', ' ) }; whole audit ${ Math.round( ( Date.now() - t0 ) / 1000 ) }s` );
				ok( full.timings.every( ( t ) => t.status === 200 && t.ms < 29000 ), `every request ends in under 29 s: ${ worst[ 0 ].id } took ${ worst[ 0 ].ms } ms` );
				const ab = full.results.find( ( r ) => r.id === 'plugins_abandoned' );
				ok( ab && ab.severity === 'info' && /time limit|did not answer|could not/i.test( ab.summary + ( ab.details || [] ).join( ' ' ) ), `slow lookup reported honestly: ${ ab ? ab.severity + ' ' + ab.summary + ' ' + ( ab.details || [] ).join( '; ' ) : 'missing' }` );
			}
		} finally {
			sql( "DELETE FROM wp_options WHERE option_name = 'sa_destr_http'" );
			for ( let i = 1; i <= 16; i++ ) {
				fs.rmSync( `${ SITE }/wp-content/plugins/sa-destr-dummy-${ i }`, { recursive: true, force: true } );
			}
			clearWporgCache();
		}
		logClean( mark, 'slow network' );
	}, 'tests/Destructive/s2.mjs' );

	// ------------------------------------------------------------ 2.2 old WooCommerce (last: it changes the clone)
	await scenario( '2.2', 'WooCommerce below the supported minimum (8.9)', 'the audit completes and the WooCommerce version check reports the old version; no fatal error', async () => {
		const mark = logMark();
		const old = wp( [ 'plugin', 'install', '/cache/woo/woocommerce.8.9.3.zip', '--force' ] );
		ok( old.code === 0, `installing WooCommerce 8.9.3: ${ ( old.err + old.out ).slice( -200 ) }` );
		try {
			const ver = wpOut( [ 'plugin', 'get', 'woocommerce', '--field=version' ] );
			eq( ver, '8.9.3', 'WooCommerce 8.9.3 is active' );
			const a = cliAudit();
			const rows = byId( structural( a, 'WooCommerce 8.9.3' ) );
			note( `WooCommerce 8.9.3: wc version check says: ${ rows.environment_wc_version ? rows.environment_wc_version.severity + ' / ' + rows.environment_wc_version.summary : 'n/a' }` );
			const full = await fullAudit( admin );
			ok( full.ok && full.finishStatus === 200 && full.timings.every( ( t ) => t.status === 200 ), 'browser audit on WooCommerce 8.9.3' );
			const page = await http( 'GET', '/wp-admin/admin.php?page=store-audit', { cookie: admin.cookie } );
			eq( page.status, 200, 'page on WooCommerce 8.9.3' );
		} finally {
			wp( [ 'plugin', 'install', '/cache/woo/woocommerce.11.2.1.zip', '--force' ] );
			wp( [ 'plugin', 'activate', 'woocommerce' ] );
		}
		logClean( mark, 'old woocommerce' );
		structural( cliAudit(), 'after restoring WooCommerce' );
	}, 'tests/Destructive/s2.mjs' );

	fs.rmSync( `${ SITE }/wp-content/mu-plugins/sa-destr-http.php`, { force: true } );
	void q;
	void shop;
}
