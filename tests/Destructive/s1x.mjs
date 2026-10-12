// Scenario 1.6: data of the site itself (plugin, theme, user, product, file names) as a source of XSS.
import fs from 'node:fs';
import { SITE, PLUGIN_DIR, ajax, eq, fullAudit, htmlProblems, http, jsonBody, logClean, logMark, ok, note, pageConfig, scenario, sessionNonce, splitHtml, sql, wp, wpOut, evalPhp } from './lib.mjs';
import { runAdminJs } from './fakedom.mjs';
import { clearPluginRows } from './s1.mjs';

export const P = {
	p1: '<script>alert("xssmark1")</script>',
	p2: '"><img src=x onerror=alert(xssmark2)>',
	p3: '"onmouseover="alert(xssmark3)',
	p4: '</script><script>alert(xssmark4)</script>',
	p5: 'javascript:alert(xssmark5)',
};

const POISON = `
global $wpdb;
$P = json_decode( getenv( 'SA_P' ), true );
$root = ABSPATH;
// plugins
$plugins = array(
	'sa-destr-xss-1' => array( $P['p1'], true ),
	'sa-destr-xss-2' => array( $P['p2'], false ),
	'sa-destr-xss-3' => array( $P['p3'], false ),
	'wordpress-seo' => array( $P['p4'], true ),
	'seo-by-rank-math' => array( $P['p5'], true ),
);
foreach ( $plugins as $dir => $def ) {
	wp_mkdir_p( WP_PLUGIN_DIR . '/' . $dir );
	file_put_contents( WP_PLUGIN_DIR . '/' . $dir . '/' . $dir . '.php', "<?php\\n/*\\nPlugin Name: " . $def[0] . "\\nVersion: 1.0\\nAuthor: " . $P['p3'] . "\\n*/\\n" );
}
// themes
wp_mkdir_p( get_theme_root() . '/sa-destr-parent' );
file_put_contents( get_theme_root() . '/sa-destr-parent/style.css', "/*\\nTheme Name: " . $P['p2'] . "\\nVersion: 1.0\\n*/\\n" );
file_put_contents( get_theme_root() . '/sa-destr-parent/index.php', "<?php\\n" );
wp_mkdir_p( get_theme_root() . '/sa-destr-child' );
file_put_contents( get_theme_root() . '/sa-destr-child/style.css', "/*\\nTheme Name: " . $P['p3'] . "\\nTemplate: sa-destr-parent\\nVersion: 1.0\\n*/\\n" );
file_put_contents( get_theme_root() . '/sa-destr-child/index.php', "<?php\\n" );
// mu-plugin
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/sa-destr-xss-mu.php', "<?php\\n/*\\nPlugin Name: " . $P['p4'] . "\\n*/\\n" );
// backup-like files (file names may hold HTML characters on Linux)
$up = wp_upload_dir();
file_put_contents( $up['basedir'] . '/' . 'x"onmouseover="alert(xssmark3)".sql', 'x' );
file_put_contents( $up['basedir'] . '/' . '<img src=x onerror=alert(xssmark2)>.sql', 'x' );
file_put_contents( $root . '/' . 'db"onmouseover="alert(xssmark3)".sql', 'x' );
// WooCommerce data
$admin = get_user_by( 'login', 'sa-destr-admin' );
$wpdb->insert( $wpdb->prefix . 'woocommerce_api_keys', array( 'user_id' => $admin->ID, 'description' => $P['p1'], 'permissions' => 'write', 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x', 'truncated_key' => 'abcd', 'last_access' => null ) );
$wpdb->insert( $wpdb->prefix . 'woocommerce_api_keys', array( 'user_id' => $admin->ID, 'description' => $P['p2'], 'permissions' => 'read_write', 'consumer_key' => 'ck_y', 'consumer_secret' => 'cs_y', 'truncated_key' => 'wxyz', 'last_access' => '2020-01-01 00:00:00' ) );
$hook = new WC_Webhook();
$hook->set_name( $P['p3'] );
$hook->set_topic( 'order.created' );
$hook->set_delivery_url( 'http://localhost:9/none' );
$hook->set_status( 'disabled' );
$hook->save();
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( $P['p1'] );
$zone->add_location( 'US', 'country' );
$zone->save();
$prod = new WC_Product_Simple();
$prod->set_name( $P['p2'] );
$prod->set_manage_stock( true );
$prod->set_stock_quantity( -5 );
$prod->set_regular_price( '5' );
$prod->save();
$prod2 = new WC_Product_Simple();
$prod2->set_name( $P['p1'] );
$prod2->set_manage_stock( true );
$prod2->set_stock_quantity( -2 );
$prod2->set_regular_price( '5' );
$prod2->save();
$terms = wp_insert_post( array( 'post_title' => $P['p3'], 'post_status' => 'publish', 'post_type' => 'page' ) );
update_option( 'woocommerce_terms_page_id', $terms );
$cart = (int) get_option( 'woocommerce_cart_page_id' );
wp_update_post( array( 'ID' => $cart, 'post_title' => $P['p1'], 'post_status' => 'draft' ) );
// users: an "admin" login with hostile display data, and hostile logins
$u = wp_insert_user( array( 'user_login' => 'admin', 'user_pass' => 'x-Pass-123!', 'role' => 'administrator', 'display_name' => $P['p1'], 'user_email' => 'xss-admin@example.test' ) );
$wpdb->update( $wpdb->users, array( 'user_nicename' => 'admin' ), array( 'ID' => $u ) );
$u2 = wp_insert_user( array( 'user_login' => 'sa-destr-xss-user', 'user_pass' => 'x-Pass-123!', 'role' => 'administrator', 'user_email' => 'xss-u2@example.test' ) );
$wpdb->update( $wpdb->users, array( 'user_login' => 'adm"onmouseover="alert(xssmark3)' ), array( 'ID' => $u2 ) );
// options, cron, tables
add_option( $P['p1'], str_repeat( 'a', 700000 ), '', 'yes' );
add_option( $P['p2'], str_repeat( 'b', 650000 ), '', 'yes' );
wp_schedule_single_event( time() - 3 * DAY_IN_SECONDS, 'hook' . $P['p2'] );
update_option( 'blogdescription', $P['p4'] );
echo 'poisoned';
`;

export function poison() {
	const r = evalPhp( POISON, { SA_P: JSON.stringify( P ) } );
	return r.out.includes( 'poisoned' ) ? '' : r.err + r.out;
}

export function unpoison() {
	for ( const d of [ 'sa-destr-xss-1', 'sa-destr-xss-2', 'sa-destr-xss-3', 'wordpress-seo', 'seo-by-rank-math' ] ) {
		wp( [ 'plugin', 'deactivate', d ] );
		fs.rmSync( `${ SITE }/wp-content/plugins/${ d }`, { recursive: true, force: true } );
	}
	wp( [ 'theme', 'activate', 'twentytwentyfive' ] );
	for ( const d of [ 'sa-destr-parent', 'sa-destr-child' ] ) {
		fs.rmSync( `${ SITE }/wp-content/themes/${ d }`, { recursive: true, force: true } );
	}
	fs.rmSync( `${ SITE }/wp-content/mu-plugins/sa-destr-xss-mu.php`, { force: true } );
	for ( const f of fs.readdirSync( `${ SITE }/wp-content/uploads` ) ) {
		if ( f.endsWith( '.sql' ) ) {
			fs.rmSync( `${ SITE }/wp-content/uploads/${ f }`, { force: true } );
		}
	}
	for ( const f of fs.readdirSync( SITE ) ) {
		if ( f.endsWith( '.sql' ) ) {
			fs.rmSync( `${ SITE }/${ f }`, { force: true } );
		}
	}
	evalPhp( `
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_api_keys" );
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'adm%' OR user_login='sa-destr-xss-user'" ) as $id ) { wp_delete_user( (int) $id ); }
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%xssmark%'" );
		foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE '%xssmark%'" ) as $id ) { wp_delete_post( (int) $id, true ); }
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_webhooks" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_shipping_zones" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_shipping_zone_locations" );
		delete_option( 'woocommerce_terms_page_id' );
		update_option( 'blogdescription', 'Just another WordPress site' );
		wp_update_post( array( 'ID' => (int) get_option( 'woocommerce_cart_page_id' ), 'post_title' => 'Cart', 'post_status' => 'publish' ) );
		_set_cron_array( array() );
	` );
}

function neutral( text, label ) {
	// javascript: as plain text is harmless; it is checked as an href by htmlProblems.
	for ( const v of Object.values( P ).filter( ( x ) => ! x.startsWith( 'javascript:' ) ) ) {
		ok( ! text.includes( v ) || label.startsWith( 'json' ), `${ label }: raw payload ${ v.slice( 0, 30 ) } present` );
	}
}

export async function s1x( S ) {
	const { admin } = S;
	await scenario( '1.6', 'Site data as a source of XSS', 'every HTML output, the Markdown and admin.js keep hostile plugin, theme, user, product, file and option names as inert text', async () => {
		clearPluginRows();
		const err = poison();
		ok( err === '', `poison setup failed: ${ err }` );
		for ( const d of [ 'sa-destr-xss-1', 'wordpress-seo', 'seo-by-rank-math' ] ) {
			wp( [ 'plugin', 'activate', d ] );
		}
		wp( [ 'theme', 'activate', 'sa-destr-child' ] );
		const mark = logMark();

		// Audit through the browser flow, then read it back through every output.
		const full = await fullAudit( admin );
		ok( full.ok && full.finish && full.finish.success, 'audit with hostile site data completes' );
		const results = full.ok ? full.results : [];
		const hits = results.filter( ( r ) => JSON.stringify( r ).includes( 'xssmark' ) );
		note( `hostile text reached ${ hits.length } result rows: ${ hits.map( ( h ) => h.id ).join( ', ' ) }` );
		ok( hits.length >= 6, `the hostile data should reach several results, reached ${ hits.length }` );
		ok( results.every( ( r ) => typeof r.severity === 'string' ), 'every result has a severity' );

		const nA = sessionNonce( admin, 'store_audit_export' );
		const page = await http( 'GET', '/wp-admin/admin.php?page=store-audit', { cookie: admin.cookie } );
		eq( page.status, 200, 'admin page renders with hostile report' );
		const pp = htmlProblems( page.text );
		ok( pp.length === 0, `admin page: ${ pp.join( ', ' ) }` );
		const { outside, scripts } = splitHtml( page.text );
		neutral( outside, 'admin page markup' );
		const cfg = pageConfig( page.text );
		ok( cfg && cfg.lastReport, 'admin page config parses as JSON and carries the report' );
		for ( const s of scripts ) {
			if ( ! s.includes( 'storeAuditConfig' ) ) {
				ok( ! /^\s*alert\(xssmark/.test( s ) && ! s.includes( '<script' ), 'a payload escaped into a separate script element' );
			}
		}
		const cfgScript = scripts.find( ( s ) => s.includes( 'storeAuditConfig' ) ) || '';
		ok( ! /<(script|\/script|img|!--)/i.test( cfgScript.replace( /\/\/# sourceURL.*$/m, '' ) ), 'the inline JSON holds no raw "<" sequences that could close the script element' );

		const print = await http( 'GET', `/wp-admin/admin-post.php?action=store_audit_print&_wpnonce=${ nA }`, { cookie: admin.cookie } );
		eq( print.status, 200, 'print view renders' );
		const prp = htmlProblems( print.text );
		ok( prp.length === 0, `print view: ${ prp.join( ', ' ) }` );
		neutral( splitHtml( print.text ).outside, 'print markup' );
		ok( print.text.includes( 'xssmark' ), 'print view still shows the (escaped) text' );

		const dash = await http( 'GET', '/wp-admin/index.php', { cookie: admin.cookie } );
		eq( dash.status, 200, 'dashboard renders' );
		const at = dash.text.indexOf( 'id="store_audit_dashboard_widget"' );
		ok( at !== -1, 'dashboard widget present' );
		const rest = dash.text.slice( at );
		const next = rest.search( /<div id="dashboard_|<div id="[a-z_]+" class="postbox/ );
		const widget = rest.slice( 0, next > 0 ? next : 8000 );
		const dp = htmlProblems( widget );
		ok( dp.length === 0, `dashboard widget: ${ dp.join( ', ' ) }` );
		neutral( widget, 'dashboard widget markup' );

		const dl = await http( 'GET', `/wp-admin/admin-post.php?action=store_audit_download&_wpnonce=${ nA }`, { cookie: admin.cookie } );
		ok( jsonBody( dl ) && jsonBody( dl ).results, 'download is valid JSON' );
		ok( ( dl.headers.get( 'content-type' ) || '' ).startsWith( 'application/json' ) && dl.headers.get( 'x-content-type-options' ) === 'nosniff', 'download is served as JSON with nosniff' );

		const md = jsonBody( await ajax( admin, 'store_audit_markdown' ) );
		ok( md && md.success, 'markdown request works' );
		const text = md?.data?.markdown || '';
		ok( ! /(^|[^\\])<[a-zA-Z\/!]/.test( text ), 'Markdown holds no unescaped HTML tags' );
		const mdLines = text.split( '\n' );
		ok( ! mdLines.some( ( l ) => /^(#{1,6} |- )?(<|\[)?.*xssmark/.test( l ) && /^#{1,6} xssmark/.test( l ) ), 'hostile text never starts a Markdown heading' );
		ok( ! /\[[^\]]*xssmark[^\]]*\]\(/.test( text ), 'Markdown holds no link built from hostile text' );

		// admin.js: render the real report through the real script in a stub DOM that rejects innerHTML.
		const strings = cfg ? cfg.strings : {};
		const runJs = runAdminJs( `${ PLUGIN_DIR }/resources/admin.js`, { ...cfg } );
		ok( runJs.error === null, `admin.js runs: ${ runJs.error }` );
		ok( runJs.doc.violations.length === 0, `admin.js wrote HTML: ${ runJs.doc.violations.join( ', ' ) }` );
		ok( runJs.text().includes( 'xssmark' ), 'admin.js shows the hostile text as plain text' );
		const anyHref = runJs.doc.created.filter( ( n ) => n.href ).every( ( n ) => n.href.startsWith( '#' ) );
		ok( anyHref, 'admin.js only creates in-page links' );

		logClean( mark, 'xss data' );
		unpoison();
		const rec = await http( 'GET', '/wp-admin/admin.php?page=store-audit', { cookie: admin.cookie } );
		eq( rec.status, 200, 'page still renders after cleanup' );
	}, 'tests/Destructive/s1x.mjs; PageTest::test_inline_config_cannot_close_script' );
}
