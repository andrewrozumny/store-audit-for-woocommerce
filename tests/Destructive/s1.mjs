// Scenario 1: hostile input into every entry point.
import fs from 'node:fs';
import {
	BASE, SITE, PLUGIN_DIR, MARKERS, ajax, enc, eq, formString, fullAudit, htmlProblems, http, jsonBody, login, logClean, logMark,
	nonceFor, note, ok, oneOf, pageConfig, pluginRows, scenario, sessionNonce, splitHtml, sql, sqlRoot, wp, wpOut, evalPhp, wellFormedReport,
} from './lib.mjs';
import { runAdminJs } from './fakedom.mjs';

export const BIG = 'A'.repeat( 1024 * 1024 );

export const PAYLOADS = {
	xss_script: '<script>alert(1)</script>',
	xss_img: '"><img src=x onerror=alert(1)>',
	js_url: 'javascript:alert(1)',
	sql_or: "' OR 1=1 --",
	sql_union: "1' UNION SELECT user_pass FROM wp_users-- -",
	sql_percent: '%',
	sql_underscore: '_',
	backslash: '\\',
	unicode: 'ключ-проверки-日本語-ñ',
	emoji: '😀🔥👨‍👩‍👧',
	rtl: '‮evil‬‏x',
	null_byte: 'abc\u0000def',
	crlf: 'a\r\nSet-Cookie: pwned=1',
	traversal: '../../../../etc/passwd',
	negative: '-1',
	fraction: '1.5',
	huge_exp: '1e309',
	zero: '0',
	empty: '',
	spaces: '   ',
	long_key: 'k'.repeat( 400 ),
	one_mb: BIG,
};

export const RAW_ARRAYS = {
	check_id_array: 'check_id[]=x',
	run_id_array: 'run_id[]=x',
	check_id_nested: 'check_id[a][b]=c',
	run_id_hash: 'run_id[0]=%3Cscript%3Ealert(1)%3C%2Fscript%3E',
	both_arrays: 'run_id[]=1&check_id[]=2',
};

export async function sessions() {
	const admin = await login( 'sa-destr-admin' );
	const sub = await login( 'sa-destr-sub' );
	const shop = await login( 'sa-destr-shopmgr' );
	return { admin, sub, shop };
}

export function clearPluginRows() {
	sql( "DELETE FROM wp_options WHERE option_name LIKE '%store\\_audit%'" );
}

const AJAX = [ 'store_audit_start', 'store_audit_run_check', 'store_audit_finish', 'store_audit_markdown' ];

function reflected( text, value ) {
	return value.length > 3 && value !== BIG && text.includes( value );
}

export async function s1( S ) {
	const { admin, sub, shop } = S;

	// ---------------------------------------------------------------- 1.1 roles and nonces
	await scenario( '1.1', 'AJAX: roles and nonces', '403 for subscriber, 400 for guest, 403 for every bad nonce, 200 for shop manager and administrator, no side effects, no PHP errors', async () => {
		clearPluginRows();
		const mark = logMark();
		const before = pluginRows();

		// Guests: wp_ajax_nopriv_* is not registered.
		for ( const a of AJAX ) {
			const r = await ajax( null, a, { run_id: 'x', check_id: 'php_version' }, { nonce: 'abcdef1234' } );
			eq( r.status, 400, `guest ${ a }` );
		}
		eq( pluginRows(), before, 'guest requests changed plugin rows' );

		// Subscriber with his own valid nonce.
		for ( const a of AJAX ) {
			const r = await ajax( sub, a, { run_id: 'x', check_id: 'php_version' } );
			eq( r.status, 403, `subscriber ${ a }` );
			ok( ( r.headers.get( 'content-type' ) || '' ).includes( 'json' ), `subscriber ${ a } answers JSON` );
		}
		eq( pluginRows(), before, 'subscriber requests changed plugin rows' );

		// Nonce variants as administrator (capable user, bad nonce).
		const start = jsonBody( await ajax( admin, 'store_audit_start' ) );
		ok( start && start.success, 'admin start works' );
		const runId = start.data.run_id;
		const withRun = pluginRows();
		const otherAction = nonceFor( admin, 'store_audit_export' );
		const expired3 = nonceFor( admin, 'store_audit', 3 );
		const expired9 = nonceFor( admin, 'store_audit', 9 );
		const subNonce = sessionNonce( sub );
		const variants = {
			missing: { nonce: null },
			empty: { nonce: '' },
			forged: { nonce: '0123456789' },
			too_long: { nonce: 'a'.repeat( 5000 ) },
			other_action: { nonce: otherAction },
			expired_3_ticks: { nonce: expired3 },
			expired_9_ticks: { nonce: expired9 },
			other_user: { nonce: subNonce },
		};
		for ( const a of AJAX ) {
			for ( const [ name, v ] of Object.entries( variants ) ) {
				const r = await ajax( admin, a, { run_id: runId, check_id: 'php_version' }, v );
				eq( r.status, 403, `${ a } with ${ name } nonce` );
			}
			const arr = await ajax( admin, a, {}, { raw: `action=${ a }&nonce[]=x&run_id=${ runId }&check_id=php_version` } );
			eq( arr.status, 403, `${ a } with array nonce` );
		}
		eq( pluginRows(), withRun, 'bad nonces changed plugin rows' );

		// Shop manager (manage_woocommerce) must work end to end.
		const full = await fullAudit( shop );
		ok( full.ok && full.finishStatus === 200 && full.finish.success, 'shop manager completes an audit' );
		ok( full.ok && full.results.length === full.start.checks.length, `shop manager got every check result (${ full.ok ? full.results.length : 0 } of ${ full.ok ? full.start.checks.length : 0 })` );
		const md = await ajax( shop, 'store_audit_markdown' );
		eq( md.status, 200, 'shop manager markdown' );
		ok( ( jsonBody( md )?.data?.markdown || '' ).startsWith( '# Store Audit report' ), 'markdown starts with the title' );

		// Administrator, nothing to continue with.
		for ( const a of [ 'store_audit_run_check', 'store_audit_finish' ] ) {
			const r = await ajax( admin, a, { run_id: 'no-such-run', check_id: 'php_version' } );
			eq( r.status, 409, `admin ${ a } without an active run` );
		}
		logClean( mark, 'roles and nonces' );
	}, 'tests/Destructive/s1.mjs; AjaxTest' );

	// ---------------------------------------------------------------- 1.2 hostile parameters
	await scenario( '1.2', 'AJAX: hostile parameters', '409 for a bad run id, 404 for a bad check id, JSON answers that never echo the payload, plugin rows unchanged, no PHP errors', async () => {
		clearPluginRows();
		const mark = logMark();
		const start = jsonBody( await ajax( admin, 'store_audit_start' ) );
		const runId = start.data.run_id;
		const rows = pluginRows();
		const checkId = start.data.checks[ 0 ].id;

		const bad = [];
		for ( const [ name, value ] of Object.entries( PAYLOADS ) ) {
			bad.push( [ name, value, null ] );
		}
		for ( const [ name, raw ] of Object.entries( RAW_ARRAYS ) ) {
			bad.push( [ name, null, raw ] );
		}

		for ( const [ name, value, rawField ] of bad ) {
			// hostile run_id, valid check_id
			for ( const [ action, expected ] of [ [ 'store_audit_run_check', 409 ], [ 'store_audit_finish', 409 ] ] ) {
				let r;
				if ( rawField ) {
					const nonce = sessionNonce( admin );
					const badRun = rawField.includes( 'run_id' );
					if ( ! badRun && action === 'store_audit_finish' ) {
						// finish ignores check_id and would finish the live run: covered by the extra-parameter test.
						continue;
					}
					const other = badRun ? ( rawField.includes( 'check_id' ) ? '' : '&check_id=' + checkId ) : '&run_id=' + runId;
					r = await ajax( admin, action, {}, { raw: `action=${ action }&nonce=${ nonce }&${ rawField }${ other }` } );
					eq( r.status, badRun ? expected : 404, `${ action } ${ name }` );
				} else {
					r = await ajax( admin, action, { run_id: value, check_id: checkId } );
					eq( r.status, expected, `${ action } run_id=${ name }` );
				}
				ok( ( r.headers.get( 'content-type' ) || '' ).includes( 'json' ), `${ action } ${ name }: JSON content type` );
				if ( value !== null ) {
					ok( ! reflected( r.text, value ), `${ action } ${ name }: the payload is echoed back` );
				}
			}
			// hostile check_id, valid run_id
			if ( ! rawField ) {
				const r = await ajax( admin, 'store_audit_run_check', { run_id: runId, check_id: value } );
				eq( r.status, 404, `run_check check_id=${ name }` );
				ok( ! reflected( r.text, value ), `run_check check_id=${ name }: payload echoed back` );
				// both hostile
				const r2 = await ajax( admin, 'store_audit_run_check', { run_id: value, check_id: value } );
				eq( r2.status, 409, `run_check both hostile ${ name }` );
			}
		}
		eq( pluginRows(), rows, 'hostile run_id / check_id changed plugin rows' );

		// Extra and missing parameters on the endpoints that take none.
		const extra = [ 'a=1', 'run_id=' + enc( PAYLOADS.xss_script ), 'check_id[]=1', 'format=%00', 'page=' + enc( PAYLOADS.sql_or ), 'user_id=1&role=administrator' ];
		for ( const e of extra ) {
			const nonce = sessionNonce( admin );
			const m = await ajax( admin, 'store_audit_markdown', {}, { raw: `action=store_audit_markdown&nonce=${ nonce }&${ e }` } );
			oneOf( m.status, [ 200, 404 ], `markdown with extra ${ e.slice( 0, 30 ) }` );
			ok( ! m.text.includes( PAYLOADS.xss_script ) || m.status === 404, 'markdown request echoes a payload' );
		}
		eq( pluginRows(), rows, 'extra parameters changed plugin rows' );

		// start with hostile extras: allowed to create a run, must still answer 200 and a UUID.
		const s2 = await ajax( admin, 'store_audit_start', {}, { raw: `action=store_audit_start&nonce=${ sessionNonce( admin ) }&run_id=${ enc( PAYLOADS.xss_script ) }&checks[]=1&${ enc( PAYLOADS.sql_or ) }=1` } );
		eq( s2.status, 200, 'start with extra parameters' );
		const j2 = jsonBody( s2 );
		ok( j2 && /^[0-9a-f-]{36}$/.test( j2.data?.run_id || '' ), 'start returns a UUID run id' );

		// Stale run id after a second start: the first run is gone, the second still works.
		const stale = runId;
		const afterStale = await ajax( admin, 'store_audit_run_check', { run_id: stale, check_id: checkId } );
		eq( afterStale.status, 409, 'run_check on a replaced run' );
		const fresh = await ajax( admin, 'store_audit_run_check', { run_id: j2.data.run_id, check_id: checkId } );
		eq( fresh.status, 200, 'run_check on the current run' );

		// Wrong content types and verbs.
		const jsonReq = await http( 'POST', '/wp-admin/admin-ajax.php', { cookie: admin.cookie, headers: { 'Content-Type': 'application/json' }, form: JSON.stringify( { action: 'store_audit_start', nonce: sessionNonce( admin ) } ) } );
		oneOf( jsonReq.status, [ 400, 403 ], 'JSON body instead of a form' );
		const noAction = await http( 'POST', '/wp-admin/admin-ajax.php', { cookie: admin.cookie, form: `nonce=${ sessionNonce( admin ) }` } );
		eq( noAction.status, 400, 'request without action' );
		const unknown = await ajax( admin, 'store_audit_does_not_exist' );
		eq( unknown.status, 400, 'unknown action' );
		const dupe = await ajax( admin, 'store_audit_run_check', {}, { raw: `action=store_audit_run_check&nonce=${ sessionNonce( admin ) }&run_id=${ j2.data.run_id }&run_id=bogus&check_id=${ checkId }` } );
		oneOf( dupe.status, [ 200, 409 ], 'duplicate run_id parameter' );

		// A 1 MB value on the form fields of every endpoint was covered above; also a 1 MB nonce.
		const bigNonce = await ajax( admin, 'store_audit_start', {}, { nonce: PAYLOADS.one_mb } );
		eq( bigNonce.status, 403, '1 MB nonce' );
		logClean( mark, 'hostile parameters' );
	}, 'tests/Destructive/s1.mjs; AjaxTest' );

	// ---------------------------------------------------------------- 1.3 exports
	await scenario( '1.3', 'admin-post: download and print', '400 for guests, 403 for subscribers and bad nonces, 404 without a report, 200 with one; hostile query values ignored; no PHP errors', async () => {
		clearPluginRows();
		const mark = logMark();
		const url = ( action, nonce, extra = '' ) => `/wp-admin/admin-post.php?action=${ action }${ nonce === null ? '' : '&_wpnonce=' + nonce }${ extra }`;
		const nA = sessionNonce( admin, 'store_audit_export' );
		const nS = sessionNonce( sub, 'store_audit_export' );
		for ( const action of [ 'store_audit_download', 'store_audit_print' ] ) {
			eq( ( await http( 'GET', url( action, nA ) ) ).status, 400, `${ action } guest` );
			eq( ( await http( 'GET', url( action, nS ), { cookie: sub.cookie } ) ).status, 403, `${ action } subscriber` );
			eq( ( await http( 'GET', url( action, nA ), { cookie: admin.cookie } ) ).status, 404, `${ action } without a report` );
		}
		const rows0 = pluginRows();
		eq( rows0, '', 'exports without a report left plugin rows' );

		const full = await fullAudit( admin );
		ok( full.ok && full.finish.success, 'seed a report' );
		const rows = pluginRows();

		const badNonces = { missing: null, empty: '', forged: 'deadbeef00', array: '[]=x', other_action: nonceFor( admin, 'store_audit' ), expired: nonceFor( admin, 'store_audit_export', 3 ), other_user: nS };
		for ( const action of [ 'store_audit_download', 'store_audit_print' ] ) {
			for ( const [ name, n ] of Object.entries( badNonces ) ) {
				const u = name === 'array' ? url( action, null, '&_wpnonce[]=x' ) : url( action, n );
				const r = await http( 'GET', u, { cookie: admin.cookie } );
				eq( r.status, 403, `${ action } with ${ name } nonce` );
			}
		}
		eq( pluginRows(), rows, 'bad export nonces changed plugin rows' );

		const hostileQuery = `&filename=${ enc( PAYLOADS.xss_script ) }&format=%00&id[]=1&callback=${ enc( PAYLOADS.js_url ) }&${ enc( PAYLOADS.sql_or ) }=1&redirect_to=${ enc( 'https://evil.example/' ) }&s=${ enc( PAYLOADS.one_mb.slice( 0, 6000 ) ) }`;
		const dl = await http( 'GET', url( 'store_audit_download', nA, hostileQuery ), { cookie: admin.cookie } );
		eq( dl.status, 200, 'download with hostile query values' );
		const disp = dl.headers.get( 'content-disposition' ) || '';
		ok( /^attachment; filename="store-audit-[a-z0-9.\-]+-\d{4}-\d{2}-\d{2}\.json"$/.test( disp ), `download file name is safe: ${ disp }` );
		eq( dl.headers.get( 'x-content-type-options' ), 'nosniff', 'download nosniff' );
		ok( ( dl.headers.get( 'content-type' ) || '' ).startsWith( 'application/json' ), 'download content type' );
		const parsed = jsonBody( dl );
		ok( parsed && Array.isArray( parsed.results ) && typeof parsed.generated_by === 'string', 'download is valid JSON with results' );
		eq( String( dl.headers.get( 'content-length' ) ), String( Buffer.byteLength( dl.text ) ), 'download content-length matches the body' );
		ok( ! dl.text.includes( 'evil.example' ), 'download contains no query data' );

		const pr = await http( 'GET', url( 'store_audit_print', nA, hostileQuery ), { cookie: admin.cookie } );
		eq( pr.status, 200, 'print with hostile query values' );
		ok( ( pr.headers.get( 'content-type' ) || '' ).startsWith( 'text/html' ), 'print content type' );
		ok( ! pr.text.includes( 'evil.example' ) && ! pr.text.includes( PAYLOADS.xss_script ), 'print contains no query data' );
		const problems = htmlProblems( pr.text );
		ok( problems.length === 0, `print page problems: ${ problems.join( ', ' ) }` );

		// shop manager is allowed
		const nShop = sessionNonce( shop, 'store_audit_export' );
		eq( ( await http( 'GET', url( 'store_audit_download', nShop ), { cookie: shop.cookie } ) ).status, 200, 'shop manager download' );
		eq( ( await http( 'GET', url( 'store_audit_print', nShop ), { cookie: shop.cookie } ) ).status, 200, 'shop manager print' );
		eq( pluginRows(), rows, 'exports changed plugin rows' );
		logClean( mark, 'exports' );
	}, 'tests/Destructive/s1.mjs; ExportsTest' );

	// ---------------------------------------------------------------- 1.4 admin page
	await scenario( '1.4', 'Admin page with hostile query strings', '200 for administrator and shop manager, 403 for subscriber, redirect to login for guests; nothing reflected; no PHP errors', async () => {
		const mark = logMark();
		const rows = pluginRows();
		const queries = [ '', ...Object.entries( PAYLOADS ).filter( ( [ k ] ) => k !== 'one_mb' ).map( ( [ k, v ] ) => `&q=${ enc( v ) }&${ enc( v ) }=1` ), '&s[]=x&paged=-1&orderby[]=a', `&q=${ enc( PAYLOADS.one_mb.slice( 0, 7000 ) ) }` ];
		for ( const q of queries ) {
			const path = `/wp-admin/admin.php?page=store-audit${ q }`;
			const a = await http( 'GET', path, { cookie: admin.cookie } );
			eq( a.status, 200, `admin page ${ q.slice( 0, 40 ) }` );
			const problems = htmlProblems( a.text );
			ok( problems.length === 0, `admin page ${ q.slice( 0, 40 ) }: ${ problems.join( ', ' ) }` );
			ok( ! a.text.includes( PAYLOADS.xss_script ) && ! a.text.includes( PAYLOADS.xss_img ), `admin page reflects the query: ${ q.slice( 0, 40 ) }` );
			const cfg = pageConfig( a.text );
			ok( cfg && typeof cfg.nonce === 'string', 'page config parses' );
			eq( ( await http( 'GET', path, { cookie: sub.cookie } ) ).status, 403, 'subscriber page' );
			const g = await http( 'GET', path );
			eq( g.status, 302, 'guest page redirect' );
			ok( g.location.includes( 'wp-login.php' ), 'guest goes to login' );
		}
		eq( ( await http( 'GET', '/wp-admin/admin.php?page=store-audit', { cookie: shop.cookie } ) ).status, 200, 'shop manager page' );
		for ( const bad of [ 'page=store-audit%00', 'page[]=store-audit', 'page=Store-Audit', 'page=store-audit%27', 'page=store-audit/../x' ] ) {
			const r = await http( 'GET', `/wp-admin/admin.php?${ bad }`, { cookie: admin.cookie } );
			oneOf( r.status, [ 200, 403, 404 ], `odd page value ${ bad }` );
			ok( htmlProblems( r.text ).length === 0, `odd page value ${ bad }: page problems` );
		}
		eq( pluginRows(), rows, 'page views changed plugin rows' );
		logClean( mark, 'admin page' );
	}, 'tests/Destructive/s1.mjs; PageTest' );

	// ---------------------------------------------------------------- 1.5 CLI
	await scenario( '1.5', 'WP-CLI: hostile --format values', 'a clean error and a non-zero exit code for every unknown format, no side effects, no PHP errors', async () => {
		const mark = logMark();
		const rows = pluginRows();
		const formats = [ '<script>alert(1)</script>', "' OR 1=1 --", 'JSON', '', 'table;touch /tmp/pwned', '%s%s%s', '😀', '../../x', 'json ', '0', '-1', 'k'.repeat( 5000 ) ];
		for ( const f of formats ) {
			const r = wp( [ 'store-audit', 'run', `--format=${ f }` ] );
			ok( r.code !== 0, `--format=${ JSON.stringify( f.slice( 0, 30 ) ) } should fail, exit ${ r.code }` );
			ok( ! /\| *(ok|critical|warning) *\|/.test( r.out ), `--format=${ JSON.stringify( f.slice( 0, 30 ) ) } printed a result table` );
			ok( ! /Fatal error|Stack trace|Uncaught/.test( r.out + r.err ), `--format=${ JSON.stringify( f.slice( 0, 30 ) ) } crashed` );
		}
		ok( ! fs.existsSync( '/tmp/pwned' ), 'shell metacharacters were not executed' );
		const flagOnly = wp( [ 'store-audit', 'run', '--format' ] );
		ok( flagOnly.code !== 0 || /Overall score/.test( flagOnly.out ), '--format without a value ends cleanly' );
		ok( ! /Fatal error|Uncaught/.test( flagOnly.out + flagOnly.err ), '--format without a value does not crash' );
		const extra = wp( [ 'store-audit', 'run', '--format=json', '--unknown=<script>', 'positional' ] );
		ok( ! /Fatal error|Uncaught/.test( extra.out + extra.err ), 'unknown flags and positional arguments do not crash' );
		ok( extra.code === 0 ? ( () => { try { return Array.isArray( JSON.parse( extra.out ).results ); } catch { return false; } } )() : true, 'json with extra arguments is valid when it succeeds' );
		eq( pluginRows(), rows, 'CLI runs without --save changed plugin rows' );
		for ( const f of [ 'json', 'markdown', 'table' ] ) {
			const r = wp( [ 'store-audit', 'run', `--format=${ f }` ] );
			eq( r.code, 0, `--format=${ f } works` );
		}
		logClean( mark, 'cli' );
	}, 'tests/Destructive/s1.mjs; manual: wp store-audit run --format=<value>' );
}
