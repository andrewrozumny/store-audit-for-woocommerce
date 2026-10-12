// Scenario 4: upgrade from 0.1.0 to the current build and back.
import fs from 'node:fs';
import { PLUGIN_DIR, ajax, eq, fullAudit, htmlProblems, http, jsonBody, logClean, logMark, note, ok, oneOf, pageConfig, scenario, sessionNonce, sql, wp, wpOut } from './lib.mjs';
import { runAdminJs } from './fakedom.mjs';
import { clearPluginRows } from './s1.mjs';
import { wellFormedReport } from './lib.mjs';

const OLD = '/cache/old/store-audit-for-woocommerce-0.1.0.zip';
const NEW = '/cache/new/store-audit-for-woocommerce-current.zip';

function reportHash() {
	return sql( "SELECT COALESCE(MD5(option_value),'none') FROM wp_options WHERE option_name = 'store_audit_last_report'" );
}

async function surfaces( admin, label, expectReport ) {
	const page = await http( 'GET', '/wp-admin/admin.php?page=store-audit', { cookie: admin.cookie } );
	eq( page.status, 200, `${ label }: admin page` );
	ok( htmlProblems( page.text ).length === 0, `${ label }: admin page markup` );
	const cfg = pageConfig( page.text );
	ok( cfg && wellFormedReport( cfg.lastReport ), `${ label }: the report on the page is well formed` );
	if ( expectReport ) {
		ok( cfg && cfg.lastReport && cfg.lastReport.results.length > 10, `${ label }: the saved report is shown` );
		const js = runAdminJs( `${ PLUGIN_DIR }/resources/admin.js`, cfg );
		ok( js.error === null && js.text().length > 200, `${ label }: admin.js renders the saved report` );
	}
	const dash = await http( 'GET', '/wp-admin/index.php', { cookie: admin.cookie } );
	eq( dash.status, 200, `${ label }: dashboard` );
	ok( dash.text.includes( 'store_audit_dashboard_widget' ), `${ label }: dashboard widget` );
	if ( expectReport ) {
		ok( /sa-widget-number/.test( dash.text ), `${ label }: widget shows the score` );
	}
	const n = sessionNonce( admin, 'store_audit_export' );
	for ( const action of [ 'store_audit_download', 'store_audit_print' ] ) {
		const r = await http( 'GET', `/wp-admin/admin-post.php?action=${ action }&_wpnonce=${ n }`, { cookie: admin.cookie } );
		eq( r.status, expectReport ? 200 : 404, `${ label }: ${ action }` );
	}
	const md = await ajax( admin, 'store_audit_markdown' );
	eq( md.status, expectReport ? 200 : 404, `${ label }: markdown` );
	return cfg;
}

export async function s4( S ) {
	const { admin } = S;
	await scenario( '4.1', 'Upgrade 0.1.0 to the current build, and back', 'the saved report and every surface survive the upgrade; the downgrade does not fail', async () => {
		ok( fs.existsSync( OLD ) && fs.existsSync( NEW ), 'the old and the new zip exist (bin/destructive.sh builds them)' );
		const mark = logMark();
		clearPluginRows();

		const inst = wp( [ 'plugin', 'install', OLD, '--force' ] );
		ok( inst.code === 0, `install 0.1.0: ${ ( inst.err + inst.out ).slice( -200 ) }` );
		eq( wpOut( [ 'plugin', 'get', 'store-audit-for-woocommerce', '--field=status' ] ), 'active', 'plugin active after installing 0.1.0' );
		const saved = wp( [ 'store-audit', 'run', '--save', '--format=json' ] );
		ok( saved.code === 0, `0.1.0 saves a report: ${ saved.err.slice( 0, 200 ) }` );
		const hashOld = reportHash();
		ok( hashOld !== 'none', 'a report is stored by 0.1.0' );
		const oldCfg = await surfaces( admin, '0.1.0', true );
		const scoreOld = oldCfg?.lastReport?.score?.overall;

		// A run that was in progress when the site was upgraded.
		const pending = jsonBody( await ajax( admin, 'store_audit_start' ) ).data;
		await ajax( admin, 'store_audit_run_check', { run_id: pending.run_id, check_id: pending.checks[ 0 ].id } );

		const up = wp( [ 'plugin', 'install', NEW, '--force' ] );
		ok( up.code === 0, `upgrade: ${ ( up.err + up.out ).slice( -200 ) }` );
		eq( wpOut( [ 'plugin', 'get', 'store-audit-for-woocommerce', '--field=status' ] ), 'active', 'plugin still active after the upgrade' );
		eq( reportHash(), hashOld, 'the stored report was not modified by the upgrade' );
		const newCfg = await surfaces( admin, 'after upgrade', true );
		eq( newCfg?.lastReport?.score?.overall, scoreOld, 'the score of the saved report is unchanged' );
		eq( newCfg?.lastReport?.results?.length, oldCfg?.lastReport?.results?.length, 'the saved report keeps all its rows' );

		const cont = await ajax( admin, 'store_audit_run_check', { run_id: pending.run_id, check_id: pending.checks[ 1 ].id } );
		oneOf( cont.status, [ 200, 409 ], 'a run started before the upgrade can continue or is refused cleanly' );
		if ( cont.status === 200 ) {
			const fin = await ajax( admin, 'store_audit_finish', { run_id: pending.run_id } );
			oneOf( fin.status, [ 200, 409 ], 'and finishes or is refused cleanly' );
		}
		const fresh = await fullAudit( admin );
		ok( fresh.ok && fresh.finishStatus === 200, 'a new audit works after the upgrade' );
		await surfaces( admin, 'after a new audit', true );

		const down = wp( [ 'plugin', 'install', OLD, '--force' ] );
		ok( down.code === 0, `downgrade to 0.1.0: ${ ( down.err + down.out ).slice( -200 ) }` );
		eq( wpOut( [ 'plugin', 'get', 'store-audit-for-woocommerce', '--field=status' ] ), 'active', 'plugin still active after the downgrade' );
		await surfaces( admin, 'after downgrade', true );
		const oldRun = await fullAudit( admin );
		ok( oldRun.ok && oldRun.finishStatus === 200, '0.1.0 can run an audit on data written by the newer build' );

		const back = wp( [ 'plugin', 'install', NEW, '--force' ] );
		ok( back.code === 0, 'and the current build can be installed again' );
		await surfaces( admin, 'after returning', true );
		logClean( mark, 'upgrade' );
	}, 'manual: bash bin/destructive.sh run 4' );
}
