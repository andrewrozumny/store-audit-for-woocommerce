// Scenario 5: a product with 500 variations and an order with 300 lines at memory_limit=128M.
import fs from 'node:fs';
import { spawn } from 'node:child_process';
import { SITE, ajax, eq, fullAudit, logClean, logMark, note, ok, scenario, sh, sql, wp, evalPhp } from './lib.mjs';

function restartServer( memory ) {
	const pids = sh( 'pgrep', [ '-f', '[p]hp -d memory_limit.*-S 0.0.0.0:8080' ] ).out.trim().split( '\n' ).filter( Boolean );
	for ( const pid of pids ) {
		sh( 'kill', [ pid ] );
	}
	const child = spawn( 'php', [ '-d', `memory_limit=${ memory }`, '-d', 'max_execution_time=30', '-d', 'display_errors=0', '-d', `error_log=${ SITE }/wp-content/php-error.log`, '-S', '0.0.0.0:8080', '-t', SITE ], { detached: true, stdio: 'ignore', env: { ...process.env, PHP_CLI_SERVER_WORKERS: '8' } } );
	child.unref();
}

const MEM_MU = `<?php
register_shutdown_function( function () {
	if ( isset( $_POST['check_id'] ) && is_string( $_POST['check_id'] ) ) {
		file_put_contents( '/cache/tmp/mem.log', sanitize_key( $_POST['check_id'] ) . ' ' . memory_get_peak_usage( false ) . "\\n", FILE_APPEND );
	}
} );
`;

const BUILD = `
$product = new WC_Product_Variable();
$product->set_name( 'Destructive 500 variations' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$options = array();
for ( $i = 1; $i <= 500; $i++ ) { $options[] = 'Size ' . $i; }
$attr->set_options( $options );
$attr->set_visible( true );
$attr->set_variation( true );
$product->set_attributes( array( $attr ) );
$pid = $product->save();
for ( $i = 1; $i <= 500; $i++ ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $pid );
	$v->set_attributes( array( 'size' => 'Size ' . $i ) );
	$v->set_regular_price( (string) ( 5 + $i % 7 ) );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( $i % 3 === 0 ? -1 : 10 );
	$v->save();
}
WC_Product_Variable::sync( $pid );
$simple = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'Destructive simple ' . $i );
	$p->set_regular_price( '9.99' );
	$simple[] = $p->save();
}
$order = wc_create_order();
for ( $i = 0; $i < 300; $i++ ) {
	$order->add_product( wc_get_product( $simple[ $i % 30 ] ), 1 );
}
$order->set_status( 'pending' );
$order->calculate_totals();
$order->save();
echo 'built ' . $pid . ' ' . $order->get_id() . ' ' . count( $order->get_items() );
`;

export async function s5( S ) {
	const { admin } = S;
	await scenario( '5.1', 'Product with 500 variations and an order with 300 lines, memory_limit=128M', 'the audit completes without a timeout or a memory error; the slowest check is measured', async () => {
		const mark = logMark();
		fs.mkdirSync( '/cache/tmp', { recursive: true } );
		fs.rmSync( '/cache/tmp/mem.log', { force: true } );
		const t0 = Date.now();
		const built = evalPhp( BUILD );
		ok( built.out.includes( 'built' ), `data built: ${ built.out.slice( -120 ) } ${ built.err.slice( -200 ) }` );
		note( `built the data in ${ Math.round( ( Date.now() - t0 ) / 1000 ) }s: ${ built.out.trim() }` );
		const variations = sql( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'product_variation'" );
		eq( variations, '500', 'variations in the database' );
		fs.writeFileSync( `${ SITE }/wp-content/mu-plugins/sa-destr-mem.php`, MEM_MU );
		wp( [ 'config', 'set', 'WP_MEMORY_LIMIT', '128M', '--type=constant' ] );
		wp( [ 'config', 'set', 'WP_MAX_MEMORY_LIMIT', '128M', '--type=constant' ] );
		restartServer( '128M' );
		await new Promise( ( r ) => setTimeout( r, 2500 ) );
		try {
			const full = await fullAudit( admin );
			ok( full.ok && full.finishStatus === 200, 'browser audit completes' );
			if ( full.ok ) {
				const slow = [ ...full.timings ].sort( ( a, b ) => b.ms - a.ms ).slice( 0, 5 );
				note( `slowest checks (browser, 128M): ${ slow.map( ( s ) => `${ s.id } ${ s.ms } ms` ).join( ', ' ) }` );
				ok( full.timings.every( ( t ) => t.status === 200 ), `every request answers 200 (${ full.timings.filter( ( t ) => t.status !== 200 ).map( ( t ) => t.id + ':' + t.status ).join( ',' ) })` );
				ok( slow[ 0 ].ms < 25000, `slowest check ${ slow[ 0 ].id } took ${ slow[ 0 ].ms } ms` );
				const errors = full.results.filter( ( r ) => r.severity === 'error' );
				ok( errors.length === 0, `checks that could not run: ${ errors.map( ( e ) => e.id + ' ' + e.summary ).join( ' | ' ) }` );
				const names = full.results.map( ( r ) => r.id );
				ok( names.includes( 'performance_catalog_size' ), 'catalog size check ran' );
			}
			if ( fs.existsSync( '/cache/tmp/mem.log' ) ) {
				const peaks = fs.readFileSync( '/cache/tmp/mem.log', 'utf8' ).trim().split( '\n' ).map( ( l ) => l.split( ' ' ) ).map( ( [ id, b ] ) => [ id, Number( b ) ] ).sort( ( a, b ) => b[ 1 ] - a[ 1 ] );
				note( `peak memory per check (top 3): ${ peaks.slice( 0, 3 ).map( ( [ id, b ] ) => `${ id } ${ Math.round( b / 1048576 ) } MB` ).join( ', ' ) }` );
				ok( peaks.length > 0 && peaks[ 0 ][ 1 ] < 128 * 1048576, 'peak memory stays under 128 MB' );
			}
			const cli = sh( 'php', [ '-d', 'memory_limit=128M', '-d', 'display_errors=stderr', '/wp-cli.phar', `--path=${ SITE }`, 'store-audit', 'run', '--format=json' ] );
			ok( cli.code === 0 && ! /Allowed memory size|Fatal error/.test( cli.out + cli.err ), `CLI audit at 128M: exit ${ cli.code } ${ cli.err.slice( 0, 200 ) }` );
			try {
				const rows = JSON.parse( cli.out ).results.slice().sort( ( a, b ) => b.duration_ms - a.duration_ms ).slice( 0, 3 );
				note( `slowest checks (CLI): ${ rows.map( ( r ) => `${ r.id } ${ r.duration_ms } ms` ).join( ', ' ) }` );
			} catch {
				ok( false, 'CLI output is JSON' );
			}
		} finally {
			wp( [ 'config', 'delete', 'WP_MEMORY_LIMIT', '--type=constant' ] );
			wp( [ 'config', 'delete', 'WP_MAX_MEMORY_LIMIT', '--type=constant' ] );
			restartServer( '256M' );
			fs.rmSync( `${ SITE }/wp-content/mu-plugins/sa-destr-mem.php`, { force: true } );
			evalPhp( `
				global $wpdb;
				foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE 'Destructive %' AND post_type IN ('product','product_variation')" ) as $id ) { wp_delete_post( (int) $id, true ); }
				foreach ( wc_get_orders( array( 'limit' => -1, 'status' => 'pending' ) ) as $o ) { $o->delete( true ); }
			` );
			await new Promise( ( r ) => setTimeout( r, 2000 ) );
		}
		logClean( mark, 'resources' );
	}, 'manual: bash bin/destructive.sh run 5' );
}
