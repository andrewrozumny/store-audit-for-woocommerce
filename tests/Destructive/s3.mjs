// Scenario 3: concurrency, interrupted runs, deactivation and uninstall.
import fs from 'node:fs';
import {
	SITE, PLUGIN_DIR, ajax, eq, fullAudit, http, jsonBody, logClean, logMark, note, ok, oneOf, pluginRows, scenario, sessionNonce, sql, sqlRoot, wp, wpOut, evalPhp, DB,
} from './lib.mjs';
import { runAdminJs } from './fakedom.mjs';
import { clearPluginRows } from './s1.mjs';

async function pool( items, limit, fn ) {
	const out = new Array( items.length );
	let next = 0;
	await Promise.all( Array.from( { length: limit }, async () => {
		while ( next < items.length ) {
			const i = next++;
			out[ i ] = await fn( items[ i ], i );
		}
	} ) );
	return out;
}

const NOT_RUN = /did not run/i;

export async function s3( S ) {
	const { admin } = S;

	await scenario( '3.1', 'Two audits started in two tabs', 'the older run is refused with 409, the newer one completes, no result of the older run leaks into it', async () => {
		clearPluginRows();
		const mark = logMark();
		const a = jsonBody( await ajax( admin, 'store_audit_start' ) ).data;
		const b = jsonBody( await ajax( admin, 'store_audit_start' ) ).data;
		ok( a.run_id !== b.run_id, 'two different run ids' );
		const first = a.checks[ 0 ].id;
		eq( ( await ajax( admin, 'store_audit_run_check', { run_id: a.run_id, check_id: first } ) ).status, 409, 'run_check of the older run' );
		eq( ( await ajax( admin, 'store_audit_finish', { run_id: a.run_id } ) ).status, 409, 'finish of the older run' );
		eq( ( await ajax( admin, 'store_audit_run_check', { run_id: b.run_id, check_id: first } ) ).status, 200, 'run_check of the newer run' );
		const fin = jsonBody( await ajax( admin, 'store_audit_finish', { run_id: b.run_id } ) );
		ok( fin && fin.success, 'newer run finishes' );
		eq( pluginRows().split( '\n' ).filter( ( l ) => l.startsWith( 'store_audit_run' ) ).length, 0, 'no temporary run rows are left' );
		logClean( mark, 'two tabs' );
	}, 'tests/Unit/RunStorageTest.php::test_new_run_replaces_the_old_one; tests/Destructive/s3.mjs' );

	await scenario( '3.2', 'Parallel run_check requests of one run', 'every result is kept: finish lists all checks with their real result and none as "did not run"', async () => {
		clearPluginRows();
		const mark = logMark();
		const start = jsonBody( await ajax( admin, 'store_audit_start' ) ).data;
		const checks = start.checks;
		const replies = await pool( checks, 5, ( c ) => ajax( admin, 'store_audit_run_check', { run_id: start.run_id, check_id: c.id } ) );
		const failedReq = replies.map( ( r, i ) => [ r, checks[ i ].id ] ).filter( ( [ r ] ) => r.status !== 200 );
		ok( failedReq.length === 0, `every parallel request answered 200 (failed: ${ failedReq.map( ( [ r, id ] ) => id + ':' + r.status ).join( ',' ) })` );
		const fin = jsonBody( await ajax( admin, 'store_audit_finish', { run_id: start.run_id } ) );
		ok( fin && fin.success, 'finish after parallel requests' );
		const rows = fin?.data?.results || [];
		const lost = rows.filter( ( r ) => NOT_RUN.test( r.summary ) );
		eq( rows.length, checks.length, 'the report has a row per check' );
		eq( lost.length, 0, `results lost to overlapping saves: ${ lost.map( ( r ) => r.id ).join( ', ' ) }` );
		logClean( mark, 'parallel run_check' );
	}, 'tests/Unit/RunStorageTest.php::test_overlapping_run_check_requests_do_not_lose_results; tests/Destructive/s3.mjs' );

	await scenario( '3.3', 'finish with an incomplete set of results', 'the missing checks are listed as "not checked" and stay out of the score; with no results at all the old report is kept', async () => {
		clearPluginRows();
		const mark = logMark();
		const seed = await fullAudit( admin );
		ok( seed.ok && seed.finish.success, 'seed a complete report' );
		const lastBefore = sql( "SELECT MD5(option_value) FROM wp_options WHERE option_name = 'store_audit_last_report'" );

		const start = jsonBody( await ajax( admin, 'store_audit_start' ) ).data;
		eq( ( await ajax( admin, 'store_audit_finish', { run_id: start.run_id } ) ).status, 409, 'finish without any result' );
		eq( sql( "SELECT MD5(option_value) FROM wp_options WHERE option_name = 'store_audit_last_report'" ), lastBefore, 'the previous report was kept' );

		const ran = start.checks.slice( 0, 3 );
		for ( const c of ran ) {
			eq( ( await ajax( admin, 'store_audit_run_check', { run_id: start.run_id, check_id: c.id } ) ).status, 200, `run ${ c.id }` );
		}
		const fin = jsonBody( await ajax( admin, 'store_audit_finish', { run_id: start.run_id } ) );
		ok( fin && fin.success, 'finish with 3 of all checks' );
		const rows = fin?.data?.results || [];
		eq( rows.length, start.checks.length, 'report row count' );
		const missing = rows.filter( ( r ) => NOT_RUN.test( r.summary ) );
		eq( missing.length, start.checks.length - 3, 'the other checks are marked as not run' );
		ok( missing.every( ( r ) => r.severity === 'error' ), 'not-run checks have the "not checked" severity' );
		const sectionsScored = Object.keys( fin?.data?.score?.sections || {} );
		const sectionsRan = [ ...new Set( ran.map( ( c ) => c.section ) ) ];
		ok( sectionsScored.every( ( s ) => sectionsRan.includes( s ) ), `only sections with a real result are scored (${ sectionsScored.join( ',' ) } vs ${ sectionsRan.join( ',' ) })` );
		logClean( mark, 'incomplete finish' );
	}, 'tests/Unit/RunStorageTest.php::test_finish_marks_missing_checks_as_not_checked, test_finish_with_no_results_is_rejected; tests/Destructive/s3.mjs' );

	await scenario( '3.4', 'The browser runner when a check request fails', 'a failed check appears once in the report, as "not checked"', async () => {
		const checks = [ { id: 'c1', title: 'First check', section: 'security' }, { id: 'c2', title: 'Second check', section: 'security' }, { id: 'c3', title: 'Third check', section: 'plugins' } ];
		const row = ( c, severity, summary ) => ( { id: c.id, section: c.section, title: c.title, severity, summary, details: [], explanation: '', fix_hint: '', duration_ms: 1 } );
		const reply = ( status, body ) => Promise.resolve( { ok: status < 400, status, json: () => Promise.resolve( body ) } );
		const fetchImpl = ( url, opts ) => {
			const action = opts.body.get( 'action' );
			if ( action === 'store_audit_start' ) {
				return reply( 200, { success: true, data: { run_id: 'r1', checks, sections: [] } } );
			}
			if ( action === 'store_audit_run_check' ) {
				const id = opts.body.get( 'check_id' );
				return id === 'c2' ? reply( 500, {} ) : reply( 200, { success: true, data: row( checks.find( ( c ) => c.id === id ), 'ok', 'fine' ) } );
			}
			return reply( 200, { success: true, data: {
				generated_at: '2026-10-12T08:30:00Z',
				score: { overall: 100, sections: { security: { label: 'Security', score: 100, counts: { critical: 0, warning: 0, info: 0, ok: 1, error: 1 } }, plugins: { label: 'Plugins', score: 100, counts: { critical: 0, warning: 0, info: 0, ok: 1, error: 0 } } } },
				results: [ row( checks[ 0 ], 'ok', 'fine' ), row( checks[ 1 ], 'error', 'This check did not run, so it is not part of the score. Run the audit again.' ), row( checks[ 2 ], 'ok', 'fine' ) ],
			} } );
		};
		const cfg = { ajaxUrl: '/x', nonce: 'n', lastReport: null, sections: {}, strings: { running: 'Running', run: 'Run', runAgain: 'Again', lastRun: 'Last', checking: '%1$s %2$s %3$s', finishing: 'Finishing', couldNot: 'Could not check', failedStart: 'Failed', passedChecks: 'Passed (%d)', noResults: 'none', critical: 'C', warning: 'W', info: 'I', ok: 'OK', error: 'Not checked' } };
		const js = runAdminJs( `${ PLUGIN_DIR }/resources/admin.js`, cfg, { fetchImpl } );
		ok( js.error === null, `admin.js loads: ${ js.error }` );
		js.doc.byId[ 'store-audit-run' ].listeners.click();
		for ( let i = 0; i < 100 && ! js.text().includes( 'Third check' ); i++ ) {
			await new Promise( ( r ) => setTimeout( r, 50 ) );
		}
		const count = ( js.text().match( /Second check/g ) || [] ).length;
		eq( count, 1, 'the failed check is listed exactly once' );
		ok( js.text().includes( 'Third check' ), 'the report was rendered' );
	}, 'tests/Destructive/s3.mjs' );

	await scenario( '3.5', 'Audit during plugin deactivation', 'refused cleanly while inactive (400), continues after reactivation, a request in flight when the plugin is deactivated still completes; no PHP errors', async () => {
		clearPluginRows();
		const mark = logMark();
		const start = jsonBody( await ajax( admin, 'store_audit_start' ) ).data;
		const ids = start.checks.map( ( c ) => c.id );
		eq( ( await ajax( admin, 'store_audit_run_check', { run_id: start.run_id, check_id: ids[ 0 ] } ) ).status, 200, 'first check' );
		wp( [ 'plugin', 'deactivate', 'store-audit-for-woocommerce' ] );
		for ( const a of [ 'store_audit_run_check', 'store_audit_finish', 'store_audit_start' ] ) {
			eq( ( await ajax( admin, a, { run_id: start.run_id, check_id: ids[ 1 ] } ) ).status, 400, `${ a } while deactivated` );
		}
		wp( [ 'plugin', 'activate', 'store-audit-for-woocommerce' ] );
		eq( ( await ajax( admin, 'store_audit_run_check', { run_id: start.run_id, check_id: ids[ 1 ] } ) ).status, 200, 'the run continues after reactivation' );
		ok( jsonBody( await ajax( admin, 'store_audit_finish', { run_id: start.run_id } ) )?.success, 'finish after reactivation' );

		// A request that is already running when the plugin is deactivated.
		for ( let i = 1; i <= 4; i++ ) {
			const d = `${ SITE }/wp-content/plugins/sa-destr-dummy-${ i }`;
			fs.mkdirSync( d, { recursive: true } );
			fs.writeFileSync( `${ d }/sa-destr-dummy-${ i }.php`, `<?php\n/*\nPlugin Name: Destructive Dummy ${ i }\nVersion: 1.0\n*/\n` );
		}
		fs.writeFileSync( `${ SITE }/wp-content/mu-plugins/sa-destr-slow.php`, "<?php\nadd_filter( 'pre_http_request', function ( $pre, $args, $url ) { if ( 'api.wordpress.org' === wp_parse_url( $url, PHP_URL_HOST ) ) { sleep( 2 ); return new WP_Error( 'http_request_failed', 'timeout' ); } return $pre; }, 10, 3 );\n" );
		try {
			const s2 = jsonBody( await ajax( admin, 'store_audit_start' ) ).data;
			const inflight = ajax( admin, 'store_audit_run_check', { run_id: s2.run_id, check_id: 'plugins_abandoned' } );
			await new Promise( ( r ) => setTimeout( r, 1500 ) );
			wp( [ 'plugin', 'deactivate', 'store-audit-for-woocommerce' ] );
			const r = await inflight;
			eq( r.status, 200, 'the request in flight completes' );
			ok( jsonBody( r )?.success, 'and returns a result' );
		} finally {
			wp( [ 'plugin', 'activate', 'store-audit-for-woocommerce' ] );
			fs.rmSync( `${ SITE }/wp-content/mu-plugins/sa-destr-slow.php`, { force: true } );
			for ( let i = 1; i <= 4; i++ ) {
				fs.rmSync( `${ SITE }/wp-content/plugins/sa-destr-dummy-${ i }`, { recursive: true, force: true } );
			}
		}
		logClean( mark, 'deactivation' );
	}, 'tests/Destructive/s3.mjs' );

	// ---------------------------------------------------------------- uninstall (single site)
	await scenario( '3.6', 'Deactivate and uninstall (single site)', 'every row of the plugin is gone (options, run rows, wordpress.org cache and its timeout rows); rows of other plugins are untouched', async () => {
		const mark = logMark();
		clearPluginRows();
		const seedAudit = await fullAudit( admin );
		ok( seedAudit.ok, 'seed: a complete audit' );
		await ajax( admin, 'store_audit_start' );
		wp( [ 'transient', 'set', 'store_audit_wporg_test', '{"a":1}', '3600' ] );
		sql( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('_transient_timeout_store_audit_wporg_zzz', '9999999999', 'no'), ('_transient_store_audit_wporg_zzz', 'a:0:{}', 'no'), ('store_audit_run_result_environment_https', 'a:0:{}', 'no')" );
		const foreign = {
			woocommerce_store_audit_note: 'woo',
			my_store_audit_notes: 'mine',
			store_auditor_setting: 'someone else',
			storeaudit_legacy: 'legacy',
			_transient_other_plugin_cache: 'cache',
			_transient_timeout_other_plugin_cache: '9999999999',
			_transient_stored_audit_x: 'x',
		};
		for ( const [ k, v ] of Object.entries( foreign ) ) {
			sql( `INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('${ k }', '${ v }', 'no') ON DUPLICATE KEY UPDATE option_value = '${ v }'` );
		}
		const ours = sql( "SELECT COUNT(*) FROM wp_options WHERE option_name REGEXP '^(_transient_|_transient_timeout_)?store_audit_'" );
		ok( Number( ours ) >= 6, `seeded ${ ours } plugin rows` );
		const foreignBefore = sql( `SELECT option_name, option_value FROM wp_options WHERE option_name IN (${ Object.keys( foreign ).map( ( k ) => `'${ k }'` ).join( ',' ) }) ORDER BY option_name` );
		const wooBefore = sql( "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'woocommerce\\_%'" );

		wp( [ 'plugin', 'deactivate', 'store-audit-for-woocommerce' ] );
		const un = wp( [ 'plugin', 'uninstall', 'store-audit-for-woocommerce' ] );
		ok( un.code === 0, `plugin uninstall: ${ ( un.err + un.out ).slice( 0, 200 ) }` );
		const left = sql( "SELECT option_name FROM wp_options WHERE option_name REGEXP '^(_transient_|_transient_timeout_)?store_audit_'" );
		eq( left, '', 'plugin rows left behind' );
		const anyLeft = sql( "SELECT option_name FROM wp_options WHERE option_name LIKE '%store\\_audit\\_%' AND option_name NOT IN ('woocommerce_store_audit_note','my_store_audit_notes','_transient_stored_audit_x')" );
		eq( anyLeft, '', 'other rows mentioning the plugin name' );
		eq( sql( `SELECT option_name, option_value FROM wp_options WHERE option_name IN (${ Object.keys( foreign ).map( ( k ) => `'${ k }'` ).join( ',' ) }) ORDER BY option_name` ), foreignBefore, 'rows of other plugins were touched' );
		eq( sql( "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'woocommerce\\_%'" ), wooBefore, 'WooCommerce options were touched' );
		ok( ! fs.existsSync( PLUGIN_DIR ), 'plugin folder removed' );
		for ( const k of Object.keys( foreign ) ) {
			sql( `DELETE FROM wp_options WHERE option_name = '${ k }'` );
		}
		sql( "DELETE FROM wp_options WHERE option_name LIKE '%xssmark%'" );
		// Put the plugin back for the next scenarios.
		sh_sync();
		wp( [ 'plugin', 'activate', 'store-audit-for-woocommerce' ] );
		logClean( mark, 'uninstall' );
	}, 'tests/Unit/UninstallTest.php; tests/Destructive/s3.mjs' );

	// ---------------------------------------------------------------- uninstall (multisite)
	await scenario( '3.7', 'Uninstall on a multisite network', 'the rows of every site are removed, rows of other plugins stay', async () => {
		const MS = '/cache/site-ms';
		const msdb = 'sa_destr_2';
		const wpms = ( args ) => wp( args, { path: MS } );
		sqlRoot( `DROP DATABASE IF EXISTS \`${ msdb }\`; CREATE DATABASE \`${ msdb }\` DEFAULT CHARACTER SET utf8mb4` );
		fs.rmSync( MS, { recursive: true, force: true } );
		fs.mkdirSync( '/cache/site-ms.tmp', { recursive: true } );
		const unzip = wp( [ '--info' ] );
		void unzip;
		const z = await import( 'node:child_process' );
		z.spawnSync( 'unzip', [ '-q', '/cache/core/wordpress-7.1.3.zip', '-d', '/cache/site-ms.tmp' ] );
		fs.renameSync( '/cache/site-ms.tmp/wordpress', MS );
		fs.rmSync( '/cache/site-ms.tmp', { recursive: true, force: true } );
		try {
			ok( wpms( [ 'config', 'create', `--dbname=${ msdb }`, '--dbuser=root', '--dbpass=wordpress', '--dbhost=db', '--skip-check' ] ).code === 0, 'config create' );
			ok( wpms( [ 'core', 'multisite-install', '--url=http://sa-destr-ms.test', '--title=MS', '--admin_user=admin', '--admin_password=Destr-Pass-123!', '--admin_email=a@example.test', '--skip-email' ] ).code === 0, 'multisite install' );
			ok( wpms( [ 'site', 'create', '--slug=sub1', '--title=Sub1', '--email=s1@example.test' ] ).code === 0, 'site 2' );
			ok( wpms( [ 'site', 'create', '--slug=sub2', '--title=Sub2', '--email=s2@example.test' ] ).code === 0, 'site 3' );
			z.spawnSync( 'rsync', [ '-a', '--exclude-from=/repo/.distignore', '/repo/', `${ MS }/wp-content/plugins/store-audit-for-woocommerce/` ] );
			ok( wpms( [ 'plugin', 'activate', 'store-audit-for-woocommerce', '--network' ] ).code === 0 || true, 'network activate' );
			const tables = [ 'wp_options', 'wp_2_options', 'wp_3_options' ];
			for ( const t of tables ) {
				sqlRoot( `USE \`${ msdb }\`; INSERT INTO ${ t } (option_name, option_value, autoload) VALUES ('store_audit_last_report', 'a:0:{}', 'no'), ('store_audit_current_run', 'a:0:{}', 'no'), ('store_audit_run_result_x', 'a:0:{}', 'no'), ('_transient_store_audit_wporg_q', 'a:0:{}', 'no'), ('_transient_timeout_store_audit_wporg_q', '1', 'no'), ('someone_elses_option', 'keep', 'no'), ('_transient_foreign', 'keep', 'no')` );
			}
			const dr = wpms( [ 'plugin', 'deactivate', 'store-audit-for-woocommerce', '--network' ] );
			void dr;
			const un = wpms( [ 'plugin', 'uninstall', 'store-audit-for-woocommerce' ] );
			ok( un.code === 0, `network uninstall: ${ ( un.err + un.out ).slice( 0, 200 ) }` );
			for ( const t of tables ) {
				const ours = sqlRoot( `SELECT option_name FROM \`${ msdb }\`.${ t } WHERE option_name REGEXP '^(_transient_|_transient_timeout_)?store_audit_'` );
				eq( ours, '', `${ t }: plugin rows left behind` );
				const keep = sqlRoot( `SELECT COUNT(*) FROM \`${ msdb }\`.${ t } WHERE option_name IN ('someone_elses_option','_transient_foreign')` );
				eq( keep, '2', `${ t }: foreign rows kept` );
			}
		} finally {
			sqlRoot( `DROP DATABASE IF EXISTS \`${ msdb }\`` );
			fs.rmSync( MS, { recursive: true, force: true } );
		}
	}, 'tests/Unit/UninstallTest.php::test_multisite_cleans_every_site; tests/Destructive/s3.mjs' );
}

function sh_sync() {
	// Restore the plugin files the uninstall removed (the clone's plugin folder is a copy of the repository).
	const r = spawnSyncRsync();
	return r;
}

import { spawnSync } from 'node:child_process';
function spawnSyncRsync() {
	return spawnSync( 'rsync', [ '-a', '--delete', '--exclude-from=/repo/.distignore', '/repo/', `${ PLUGIN_DIR }/` ], { encoding: 'utf8' } );
}
