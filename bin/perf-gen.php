<?php
/**
 * Performance data set generator (development only, not shipped).
 *
 * PERF_HPOS_MODE=on|off PERF_PRODUCTS=10000 PERF_ORDERS=50000 wp eval-file bin/perf-gen.php
 *
 * Builds a few template products, customers and orders through the WooCommerce API (so every row has the shape
 * WooCommerce itself writes), then multiplies them with INSERT ... SELECT in batches, straight into the right tables.
 * Orders go to the HPOS tables (with the placeholder post WooCommerce keeps) or to posts/postmeta, depending on the mode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;
$wpdb->show_errors( false );
$wpdb->suppress_errors( false );
$P        = $wpdb->prefix;
$hpos     = 'on' === getenv( 'PERF_HPOS_MODE' );
$n_prod   = max( 60, (int) ( getenv( 'PERF_PRODUCTS' ) ?: 10000 ) );
$n_orders = max( 100, (int) ( getenv( 'PERF_ORDERS' ) ?: 50000 ) );
$GLOBALS['t_start'] = microtime( true );

function sa_say( string $m ): void {
	printf( "[gen %5.1fs] %s\n", microtime( true ) - $GLOBALS['t_start'], $m );
}

function sa_q( string $sql ): int {
	global $wpdb;
	$r = $wpdb->query( $sql ); // phpcs:ignore
	if ( false === $r || '' !== $wpdb->last_error ) {
		fwrite( STDERR, 'SQL FAILED: ' . $wpdb->last_error . "\n" . substr( $sql, 0, 1500 ) . "\n" );
		exit( 1 );
	}
	return (int) $r;
}

/** @return array<string,string> column => EXTRA */
function sa_cols( string $table ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT COLUMN_NAME c, EXTRA e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', $table ), ARRAY_A );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ $r['c'] ] = (string) $r['e'];
	}
	if ( ! $out ) {
		fwrite( STDERR, "table $table has no columns (missing?)\n" );
		exit( 1 );
	}
	return $out;
}

/**
 * INSERT INTO $table SELECT ... FROM $from. Every column is copied from alias s unless $expr overrides it.
 *
 * @param array<string,string> $expr Column => SQL expression.
 * @param string[]             $skip Columns left to the database (auto increment).
 */
function sa_clone( string $table, string $from, array $expr, array $skip = array() ): int {
	$cols = array();
	$sel  = array();
	foreach ( sa_cols( $table ) as $c => $extra ) {
		if ( in_array( $c, $skip, true ) || false !== stripos( $extra, 'GENERATED' ) ) {
			continue;
		}
		$cols[] = "`$c`";
		$sel[]  = $expr[ $c ] ?? "s.`$c`";
	}
	return sa_q( "INSERT INTO `$table` (" . implode( ',', $cols ) . ') SELECT ' . implode( ',', $sel ) . " FROM $from" );
}

function sa_batches( int $total, int $size, callable $fn ): void {
	for ( $a = 1; $a <= $total; $a += $size ) {
		$fn( $a, min( $total, $a + $size - 1 ) );
	}
}

// ---- helpers for templates -------------------------------------------------------------------------------
$lorem = str_repeat( 'Hand finished and tested. Ships in two working days. Care instructions are on the label. ', 6 );

// ---- 1. categories -----------------------------------------------------------------------------------------
$cats = array();
foreach ( array( 'Home', 'Garden', 'Kitchen', 'Office', 'Outdoor' ) as $name ) {
	$term   = wp_insert_term( 'Perf ' . $name, 'product_cat' );
	$cats[] = is_wp_error( $term ) ? (int) $term->get_error_data() : (int) $term['term_id'];
}

// ---- 2. template products ----------------------------------------------------------------------------------
$groups_simple = array();
for ( $i = 1; $i <= 10; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( "Perf simple template $i" );
	$p->set_regular_price( (string) ( 10 + 5 * $i ) );
	if ( 0 === $i % 4 ) {
		$p->set_sale_price( (string) ( 8 + 5 * $i ) );
	}
	$p->set_sku( "TPL-S-$i" );
	$p->set_description( $lorem );
	$p->set_short_description( 'Short description for template ' . $i );
	$p->set_category_ids( array( $cats[ $i % 5 ] ) );
	$p->set_status( 'publish' );
	$p->set_weight( (string) $i );
	if ( 0 === $i % 2 ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 40 + $i );
	}
	if ( 3 === $i ) {
		$p->set_virtual( true );
	}
	$groups_simple[] = array( (int) $p->save() );
}

$groups_var = array();
for ( $k = 5; $k <= 20; $k++ ) {
	$parent = new WC_Product_Variable();
	$parent->set_name( "Perf variable template $k" );
	$parent->set_description( $lorem );
	$parent->set_category_ids( array( $cats[ $k % 5 ] ) );
	$parent->set_status( 'publish' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array_map( static fn( $j ) => "S$j", range( 1, $k ) ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$parent->set_attributes( array( $attr ) );
	$pid = (int) $parent->save();
	$ids = array( $pid );
	for ( $j = 1; $j <= $k; $j++ ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_attributes( array( 'size' => "S$j" ) );
		$v->set_regular_price( (string) ( 20 + $j ) );
		$v->set_status( 'publish' );
		if ( 0 === $j % 3 ) {
			$v->set_manage_stock( true );
			$v->set_stock_quantity( 10 + $j );
		}
		$ids[] = (int) $v->save();
	}
	WC_Product_Variable::sync( $pid );
	$groups_var[] = $ids;
}

// The product with 500 variations: one variation by the API, the rest cloned below.
$big = new WC_Product_Variable();
$big->set_name( 'Perf product with 500 variations' );
$big->set_description( $lorem );
$big->set_status( 'publish' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Variant' );
$attr->set_options( array_map( static fn( $j ) => "v$j", range( 1, 500 ) ) );
$attr->set_visible( true );
$attr->set_variation( true );
$big->set_attributes( array( $attr ) );
$big_id = (int) $big->save();
$bv     = new WC_Product_Variation();
$bv->set_parent_id( $big_id );
$bv->set_attributes( array( 'variant' => 'v1' ) );
$bv->set_regular_price( '30' );
$bv->set_status( 'publish' );
$bv->set_manage_stock( true );
$bv->set_stock_quantity( 5 );
$big_var_id = (int) $bv->save();

// ---- 3. template customer, admins, orders ------------------------------------------------------------------
$tpl_user = wp_insert_user(
	array(
		'user_login' => 'tplcustomer',
		'user_pass'  => 'x-Pass-123!',
		'user_email' => 'tplcustomer@example.test',
		'first_name' => 'Template',
		'last_name'  => 'Customer',
		'role'       => 'customer',
	)
);
for ( $a = 1; $a <= 4; $a++ ) {
	wp_insert_user(
		array(
			'user_login' => "perfadmin$a",
			'user_pass'  => 'x-Pass-123!',
			'user_email' => "perfadmin$a@example.test",
			'role'       => 'administrator',
		)
	);
}

// Template attachment: fixed-width path so the serialized metadata stays valid after REPLACE().
$att_id = wp_insert_post(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_title'     => 'img-00000000',
		'post_name'      => 'img-00000000',
		'post_mime_type' => 'image/jpeg',
	)
);
update_post_meta( $att_id, '_wp_attached_file', '2024/01/img-00000000.jpg' );
update_post_meta(
	$att_id,
	'_wp_attachment_metadata',
	array(
		'width'      => 1600,
		'height'     => 1200,
		'file'       => '2024/01/img-00000000.jpg',
		'filesize'   => 245000,
		'sizes'      => array(
			'thumbnail'    => array( 'file' => 'img-00000000-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg', 'filesize' => 6000 ),
			'medium'       => array( 'file' => 'img-00000000-300x300.jpg', 'width' => 300, 'height' => 225, 'mime-type' => 'image/jpeg', 'filesize' => 14000 ),
			'medium_large' => array( 'file' => 'img-00000000-768x768.jpg', 'width' => 768, 'height' => 576, 'mime-type' => 'image/jpeg', 'filesize' => 60000 ),
			'large'        => array( 'file' => 'img-00000000-1024x1024.jpg', 'width' => 1024, 'height' => 768, 'mime-type' => 'image/jpeg', 'filesize' => 100000 ),
		),
		'image_meta' => array( 'aperture' => '0', 'credit' => '', 'camera' => '', 'caption' => '', 'created_timestamp' => '0', 'copyright' => '', 'focal_length' => '0', 'iso' => '0', 'shutter_speed' => '0', 'title' => '', 'orientation' => '0', 'keywords' => array() ),
	)
);
update_post_meta( $att_id, '_wp_attachment_image_alt', 'Product image' );

$n_tpl_orders = 24;
$tpl_orders   = array();
$statuses_t   = array( 'completed', 'processing', 'on-hold', 'pending', 'cancelled', 'failed' );
for ( $i = 1; $i <= $n_tpl_orders; $i++ ) {
	$order = wc_create_order( array( 'customer_id' => (int) $tpl_user ) );
	$items = ( $i % 5 ) + 1;
	for ( $j = 0; $j < $items; $j++ ) {
		$product = wc_get_product( $groups_simple[ ( $i + $j ) % 10 ][0] );
		$order->add_product( $product, 1 + ( $i + $j ) % 3 );
	}
	if ( 0 === $i % 2 && $items < 5 ) {
		$ship = new WC_Order_Item_Shipping();
		$ship->set_method_title( 'Flat rate' );
		$ship->set_method_id( 'flat_rate' );
		$ship->set_total( '5.00' );
		$order->add_item( $ship );
	}
	$order->set_address(
		array(
			'first_name' => 'Template',
			'last_name'  => 'Customer',
			'email'      => 'tplcustomer@example.test',
			'phone'      => '5551234567',
			'address_1'  => '1 Test Street',
			'city'       => 'Testville',
			'postcode'   => '90001',
			'country'    => 'US',
			'state'      => 'CA',
		),
		'billing'
	);
	$order->set_address(
		array(
			'first_name' => 'Template',
			'last_name'  => 'Customer',
			'address_1'  => '1 Test Street',
			'city'       => 'Testville',
			'postcode'   => '90001',
			'country'    => 'US',
			'state'      => 'CA',
		),
		'shipping'
	);
	$order->set_payment_method( 'cod' );
	$order->set_payment_method_title( 'Cash on delivery' );
	$order->set_created_via( 'checkout' );
	$order->set_customer_note( 'Please leave at the door.' );
	$order->calculate_totals();
	$order->set_status( $statuses_t[ $i % 6 ] );
	if ( in_array( $statuses_t[ $i % 6 ], array( 'completed', 'processing' ), true ) ) {
		$order->set_date_paid( time() - 86400 );
	}
	if ( 'completed' === $statuses_t[ $i % 6 ] ) {
		$order->set_date_completed( time() - 3600 );
	}
	$tpl_orders[] = (int) $order->save();
}
sa_say( 'templates created: ' . count( $groups_simple ) . ' simple, ' . count( $groups_var ) . ' variable, big #' . $big_id . ', ' . count( $tpl_orders ) . ' orders' );

// ---- 4. number table and maps --------------------------------------------------------------------------------
$max_n = max( $n_prod * 3, $n_orders, 600 );
sa_q( 'DROP TABLE IF EXISTS sa_nums' );
sa_q( 'CREATE TABLE sa_nums (n INT NOT NULL PRIMARY KEY) ENGINE=InnoDB' );
sa_q( 'SET SESSION cte_max_recursion_depth = 10000000' );
sa_q( "INSERT INTO sa_nums WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < $max_n) SELECT n FROM s" );

sa_q( 'DROP TABLE IF EXISTS sa_map' );
sa_q( 'CREATE TABLE sa_map (kind VARCHAR(8) NOT NULL, grp INT NOT NULL, old_id BIGINT UNSIGNED NOT NULL, off INT NOT NULL, PRIMARY KEY (old_id), KEY kg (kind, grp)) ENGINE=InnoDB' );
foreach ( $groups_simple as $g => $ids ) {
	sa_q( "INSERT INTO sa_map VALUES ('simple', $g, {$ids[0]}, 0)" );
}
foreach ( $groups_var as $g => $ids ) {
	foreach ( $ids as $off => $id ) {
		sa_q( "INSERT INTO sa_map VALUES ('var', $g, $id, $off)" );
	}
}
$ts = count( $groups_simple );
$tv = count( $groups_var );

// ---- 5. clone products ------------------------------------------------------------------------------------
$n_var_clone = max( 2, (int) round( $n_prod * 0.0184 ) );
$n_simple    = max( 1, $n_prod - $ts - $tv - 1 - $n_var_clone );
$max_id      = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$P}posts" );
$sbase       = $max_id + 1000;
$vbase       = $sbase + $n_simple + 1000;
$vstride     = 21;

$product_kinds = array(
	'simple' => array( $n_simple, 1, $sbase, $ts ),
	'var'    => array( $n_var_clone, $vstride, $vbase, $tv ),
);
foreach ( $product_kinds as $kind => $def ) {
	list( $count, $stride, $base, $ng ) = $def;
	$new = "($base + nn.n * $stride + m.off)";
	$is_simple = 'simple' === $kind;
	sa_batches(
		$count,
		2000,
		static function ( $a, $b ) use ( $P, $kind, $stride, $base, $ng, $new, $is_simple ) {
			$on = "sa_nums nn JOIN sa_map m ON m.kind = '$kind' AND m.grp = nn.n % $ng";
			// posts
			sa_clone(
				"{$P}posts",
				"$on JOIN {$P}posts s ON s.ID = m.old_id LEFT JOIN sa_map pm ON pm.old_id = s.post_parent WHERE nn.n BETWEEN $a AND $b",
				array(
					'ID'            => $new,
					'post_parent'   => "IF(s.post_parent = 0, 0, $base + nn.n * $stride + pm.off)",
					'post_title'    => "IF(s.post_parent = 0, CONCAT(s.post_title, ' ', nn.n), s.post_title)",
					'post_name'     => "IF(s.post_parent = 0, CONCAT(s.post_name, '-', nn.n), CONCAT('product-variation-', $new))",
					'guid'          => "CONCAT('http://127.0.0.1:8080/?p=', $new)",
					'post_date'     => 'DATE_SUB(s.post_date, INTERVAL (nn.n % 900) DAY)',
					'post_date_gmt' => 'DATE_SUB(s.post_date_gmt, INTERVAL (nn.n % 900) DAY)',
				)
			);
			// postmeta
			$stock = $is_simple ? "WHEN '_stock' THEN IF(nn.n % 100 = 11, '-2', s.meta_value)" : '';
			sa_clone(
				"{$P}postmeta",
				"$on JOIN {$P}postmeta s ON s.post_id = m.old_id WHERE nn.n BETWEEN $a AND $b",
				array(
					'post_id'    => $new,
					'meta_value' => "CASE s.meta_key WHEN '_sku' THEN IF(s.meta_value = '', '', CONCAT('SKU-', $new)) $stock ELSE s.meta_value END",
				),
				array( 'meta_id' )
			);
			// terms
			sa_clone( "{$P}term_relationships", "$on JOIN {$P}term_relationships s ON s.object_id = m.old_id WHERE nn.n BETWEEN $a AND $b", array( 'object_id' => $new ) );
			// lookup
			$lookup_expr = array(
				'product_id' => $new,
				'sku'        => "IF(s.sku = '', '', CONCAT('SKU-', $new))",
			);
			if ( $is_simple ) {
				$lookup_expr['stock_quantity'] = 'IF(s.stock_quantity IS NULL, NULL, IF(nn.n % 100 = 11, -2, s.stock_quantity))';
			}
			if ( isset( sa_cols( "{$P}wc_product_meta_lookup" )['global_unique_id'] ) ) {
				$lookup_expr['global_unique_id'] = "''";
			}
			sa_clone( "{$P}wc_product_meta_lookup", "$on JOIN {$P}wc_product_meta_lookup s ON s.product_id = m.old_id WHERE nn.n BETWEEN $a AND $b", $lookup_expr );
		}
	);
	sa_say( "cloned $count $kind products" );
}

// The 500-variation product: variations 2..500 cloned from variation 1.
$bbase = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$P}posts" ) + 1000;
$bnew  = "($bbase + nn.n)";
$bfrom = "sa_nums nn JOIN {$P}posts s ON s.ID = $big_var_id WHERE nn.n BETWEEN 1 AND 499";
sa_clone( "{$P}posts", $bfrom, array( 'ID' => $bnew, 'post_name' => "CONCAT('product-variation-', $bnew)", 'guid' => "CONCAT('http://127.0.0.1:8080/?p=', $bnew)", 'post_title' => "CONCAT('Perf big variation ', nn.n + 1)" ) );
sa_clone(
	"{$P}postmeta",
	"sa_nums nn JOIN {$P}postmeta s ON s.post_id = $big_var_id WHERE nn.n BETWEEN 1 AND 499",
	array( 'post_id' => $bnew, 'meta_value' => "IF(s.meta_key = 'attribute_variant', CONCAT('v', nn.n + 1), s.meta_value)" ),
	array( 'meta_id' )
);
sa_clone( "{$P}wc_product_meta_lookup", "sa_nums nn JOIN {$P}wc_product_meta_lookup s ON s.product_id = $big_var_id WHERE nn.n BETWEEN 1 AND 499", array( 'product_id' => $bnew ) );
WC_Product_Variable::sync( $big_id );
wc_delete_product_transients( $big_id );
sa_say( 'cloned the 500-variation product' );

sa_q( "UPDATE {$P}term_taxonomy tt SET count = (SELECT COUNT(*) FROM {$P}term_relationships tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id) WHERE taxonomy IN ('product_cat','product_type','product_visibility')" );

// ---- 6. customers ---------------------------------------------------------------------------------------------
$n_cust = 500;
$ubase  = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$P}users" ) + 100;
sa_clone(
	"{$P}users",
	"sa_nums nn JOIN {$P}users s ON s.ID = $tpl_user WHERE nn.n <= $n_cust",
	array(
		'ID'            => "($ubase + nn.n)",
		'user_login'    => "CONCAT('customer', nn.n - 1)",
		'user_nicename' => "CONCAT('customer', nn.n - 1)",
		'user_email'    => "CONCAT('customer', nn.n - 1, '@example.test')",
		'display_name'  => "CONCAT('Customer ', nn.n - 1)",
	)
);
sa_clone( "{$P}usermeta", "sa_nums nn JOIN {$P}usermeta s ON s.user_id = $tpl_user WHERE nn.n <= $n_cust", array( 'user_id' => "($ubase + nn.n)" ), array( 'umeta_id' ) );
sa_say( "$n_cust customers" );

// ---- 7. clone orders ------------------------------------------------------------------------------------------------
$o_posts_max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$P}posts" );
$o_wc_max    = $hpos ? (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$P}wc_orders" ) : 0;
$obase       = max( $o_posts_max, $o_wc_max ) + 1000;
$i_max       = (int) $wpdb->get_var( "SELECT COALESCE(MAX(order_item_id),0) FROM {$P}woocommerce_order_items" );
$ibase       = $i_max + 1000;
$istride     = 8;
$first_simple = $sbase + 1;

// Template item map.
sa_q( 'DROP TABLE IF EXISTS sa_imap' );
sa_q( 'CREATE TABLE sa_imap (old_item BIGINT UNSIGNED NOT NULL PRIMARY KEY, tpl INT NOT NULL, off INT NOT NULL, old_order BIGINT UNSIGNED NOT NULL, KEY t (tpl)) ENGINE=InnoDB' );
sa_q( 'DROP TABLE IF EXISTS sa_omap' );
sa_q( 'CREATE TABLE sa_omap (tpl INT NOT NULL PRIMARY KEY, old_order BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB' );
foreach ( $tpl_orders as $t => $oid ) {
	sa_q( "INSERT INTO sa_omap VALUES ($t, $oid)" );
	$items = $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$P}woocommerce_order_items WHERE order_id = %d ORDER BY order_item_id", $oid ) );
	if ( count( $items ) >= $istride ) {
		fwrite( STDERR, "template order $oid has too many items\n" );
		exit( 1 );
	}
	foreach ( $items as $off => $iid ) {
		sa_q( 'INSERT INTO sa_imap VALUES (' . (int) $iid . ", $t, " . ( $off + 1 ) . ", $oid)" );
	}
}

// Plan: one row per new order with its status, age, customer.
sa_q( 'DROP TABLE IF EXISTS sa_plan' );
sa_q( 'CREATE TABLE sa_plan (n INT NOT NULL PRIMARY KEY, tpl INT NOT NULL, st VARCHAR(20) NOT NULL, age BIGINT NOT NULL, cust BIGINT NOT NULL, oid BIGINT UNSIGNED NOT NULL, cidx INT NOT NULL, dt DATETIME NOT NULL, pid BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB' );
$tn = count( $tpl_orders );
sa_q(
	"INSERT INTO sa_plan SELECT q.n, q.n % $tn, q.st, q.age, IF(q.n % 10 = 0, 0, $ubase + 1 + (q.n % $n_cust)), $obase + q.n, q.n % $n_cust,
		DATE_SUB(UTC_TIMESTAMP(), INTERVAL q.age SECOND), $first_simple + (q.n * 7919) % $n_simple
	FROM (
		SELECT n,
			CASE
				WHEN n % 100 IN (0, 50) THEN 'wc-pending'
				WHEN n % 100 IN (1, 51) THEN 'wc-failed'
				WHEN n % 100 IN (2, 52) THEN 'wc-pending'
				WHEN n % 100 = 3 THEN 'wc-failed'
				WHEN n % 100 BETWEEN 4 AND 8 THEN 'wc-on-hold'
				WHEN n % 100 BETWEEN 9 AND 13 THEN 'wc-cancelled'
				WHEN n % 100 BETWEEN 14 AND 16 THEN 'wc-refunded'
				WHEN n % 100 BETWEEN 17 AND 26 THEN 'wc-processing'
				ELSE 'wc-completed' END AS st,
			CASE
				WHEN n % 100 IN (0, 1, 50, 51) THEN (31 + n % 300) * 86400 + (n * 7919) % 86400
				WHEN n % 100 IN (2, 3, 52) THEN (1 + n % 20) * 86400 + (n * 7919) % 86400
				ELSE (1 + (n * 37) % 720) * 86400 + (n * 7919) % 86400 END AS age
		FROM sa_nums WHERE n <= $n_orders
	) q"
);

$cust_email = "CONCAT('customer', pl.cidx, '@example.test')";
$o_from     = static fn( string $src, string $key, int $a, int $b ): string => "sa_plan pl JOIN sa_omap om ON om.tpl = pl.tpl JOIN $src s ON s.$key = om.old_order WHERE pl.n BETWEEN $a AND $b";
$ts_utc     = 'pl.dt';

sa_batches(
	$n_orders,
	4000,
	static function ( $a, $b ) use ( $P, $hpos, $istride, $ibase, $o_from, $cust_email, $ts_utc ) {
		$item_from = "sa_plan pl JOIN sa_imap im ON im.tpl = pl.tpl";
		$new_item  = "($ibase + pl.n * $istride + im.off)";

		if ( $hpos ) {
			// Placeholder post (the id reservation WooCommerce keeps) and the order tables.
			sa_clone(
				"{$P}posts",
				$o_from( "{$P}posts", 'ID', $a, $b ),
				array(
					'ID'            => 'pl.oid',
					'post_date'     => $ts_utc,
					'post_date_gmt' => $ts_utc,
					'post_modified' => $ts_utc,
					'post_modified_gmt' => $ts_utc,
					'post_name'     => "CONCAT('order-', pl.oid)",
					'guid'          => "CONCAT('http://127.0.0.1:8080/?post_type=shop_order_placehold&p=', pl.oid)",
				)
			);
			sa_clone(
				"{$P}wc_orders",
				$o_from( "{$P}wc_orders", 'id', $a, $b ),
				array(
					'id'               => 'pl.oid',
					'status'           => 'pl.st',
					'customer_id'      => 'pl.cust',
					'billing_email'    => $cust_email,
					'date_created_gmt' => $ts_utc,
					'date_updated_gmt' => $ts_utc,
				)
			);
			sa_clone(
				"{$P}wc_order_addresses",
				$o_from( "{$P}wc_order_addresses", 'order_id', $a, $b ),
				array( 'order_id' => 'pl.oid', 'email' => "IF(s.address_type = 'billing', $cust_email, s.email)" ),
				array( 'id' )
			);
			sa_clone(
				"{$P}wc_order_operational_data",
				$o_from( "{$P}wc_order_operational_data", 'order_id', $a, $b ),
				array(
					'order_id'           => 'pl.oid',
					'order_key'          => "CONCAT('wc_order_', pl.oid)",
					'date_paid_gmt'      => "IF(s.date_paid_gmt IS NULL, NULL, $ts_utc)",
					'date_completed_gmt' => "IF(s.date_completed_gmt IS NULL, NULL, $ts_utc)",
				),
				array( 'id' )
			);
			sa_clone( "{$P}wc_orders_meta", $o_from( "{$P}wc_orders_meta", 'order_id', $a, $b ), array( 'order_id' => 'pl.oid' ), array( 'id' ) );
		} else {
			sa_clone(
				"{$P}posts",
				$o_from( "{$P}posts", 'ID', $a, $b ),
				array(
					'ID'                => 'pl.oid',
					'post_status'       => 'pl.st',
					'post_date'         => $ts_utc,
					'post_date_gmt'     => $ts_utc,
					'post_modified'     => $ts_utc,
					'post_modified_gmt' => $ts_utc,
					'post_name'         => "CONCAT('order-', pl.oid)",
					'guid'              => "CONCAT('http://127.0.0.1:8080/?post_type=shop_order&p=', pl.oid)",
				)
			);
			sa_clone(
				"{$P}postmeta",
				$o_from( "{$P}postmeta", 'post_id', $a, $b ),
				array(
					'post_id'    => 'pl.oid',
					'meta_value' => "CASE s.meta_key
						WHEN '_customer_user' THEN pl.cust
						WHEN '_billing_email' THEN $cust_email
						WHEN '_order_key' THEN CONCAT('wc_order_', pl.oid)
						WHEN '_date_paid' THEN IF(s.meta_value = '', '', UNIX_TIMESTAMP(pl.dt))
						WHEN '_date_completed' THEN IF(s.meta_value = '', '', UNIX_TIMESTAMP(pl.dt))
						WHEN '_paid_date' THEN IF(s.meta_value = '', '', pl.dt)
						WHEN '_completed_date' THEN IF(s.meta_value = '', '', pl.dt)
						ELSE s.meta_value END",
				),
				array( 'meta_id' )
			);
		}

		sa_clone(
			"{$P}woocommerce_order_items",
			"$item_from JOIN {$P}woocommerce_order_items s ON s.order_item_id = im.old_item WHERE pl.n BETWEEN $a AND $b",
			array( 'order_item_id' => $new_item, 'order_id' => 'pl.oid' )
		);
		sa_clone(
			"{$P}woocommerce_order_itemmeta",
			"$item_from JOIN {$P}woocommerce_order_itemmeta s ON s.order_item_id = im.old_item WHERE pl.n BETWEEN $a AND $b",
			array(
				'order_item_id' => $new_item,
				'meta_value'    => "IF(s.meta_key = '_product_id', pl.pid, s.meta_value)",
			),
			array( 'meta_id' )
		);
		sa_say( "orders $a-$b cloned" );
	}
);


// ---- 7b. media library, revisions, transients, autoloaded options, upload files ------------------------------------------
$n_att = max( 20, $n_prod * 2 );
$n_rev = $n_prod * 3;
$abase = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$P}posts" ) + 1000;
sa_batches(
	$n_att,
	5000,
	static function ( $a, $b ) use ( $P, $abase, $att_id ) {
		$new = "($abase + nn.n)";
		$yr  = '(2024 + nn.n % 2)';
		$mo  = "LPAD(1 + nn.n % 12, 2, '0')";
		$rep = static fn( string $col ): string => "REPLACE(REPLACE(s.$col, '2024/01', CONCAT($yr, '/', $mo)), 'img-00000000', CONCAT('img-', LPAD($new, 8, '0')))";
		sa_clone(
			"{$P}posts",
			"sa_nums nn JOIN {$P}posts s ON s.ID = $att_id WHERE nn.n BETWEEN $a AND $b",
			array(
				'ID'            => $new,
				'post_title'    => "CONCAT('img-', LPAD($new, 8, '0'))",
				'post_name'     => "CONCAT('img-', LPAD($new, 8, '0'))",
				'guid'          => "CONCAT('http://127.0.0.1:8080/wp-content/uploads/', $yr, '/', $mo, '/img-', LPAD($new, 8, '0'), '.jpg')",
				'post_date'     => 'DATE_SUB(s.post_date, INTERVAL (nn.n % 700) DAY)',
				'post_date_gmt' => 'DATE_SUB(s.post_date_gmt, INTERVAL (nn.n % 700) DAY)',
			)
		);
		sa_clone(
			"{$P}postmeta",
			"sa_nums nn JOIN {$P}postmeta s ON s.post_id = $att_id WHERE nn.n BETWEEN $a AND $b",
			array(
				'post_id'    => $new,
				'meta_value' => "CASE WHEN s.meta_key IN ('_wp_attached_file', '_wp_attachment_metadata') THEN " . $rep( 'meta_value' ) . ' ELSE s.meta_value END',
			),
			array( 'meta_id' )
		);
	}
);
sa_say( "$n_att attachments" );

$rbase = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$P}posts" ) + 1000;
$tpl_p = $groups_simple[0][0];
sa_batches(
	$n_rev,
	10000,
	static function ( $a, $b ) use ( $P, $rbase, $tpl_p, $sbase, $n_simple ) {
		$new = "($rbase + nn.n)";
		sa_clone(
			"{$P}posts",
			"sa_nums nn JOIN {$P}posts s ON s.ID = $tpl_p WHERE nn.n BETWEEN $a AND $b",
			array(
				'ID'          => $new,
				'post_type'   => "'revision'",
				'post_status' => "'inherit'",
				'post_parent' => "($sbase + 1 + (nn.n * 31) % $n_simple)",
				'post_name'   => "CONCAT(($sbase + 1 + (nn.n * 31) % $n_simple), '-revision-v', 1 + nn.n % 9)",
				'guid'        => "CONCAT('http://127.0.0.1:8080/?p=', $new)",
				'post_date'   => 'DATE_SUB(s.post_date, INTERVAL (nn.n % 800) DAY)',
				'post_date_gmt' => 'DATE_SUB(s.post_date_gmt, INTERVAL (nn.n % 800) DAY)',
			)
		);
	}
);
sa_say( "$n_rev revisions" );

// Upload files on disk: the original and four sizes per attachment, empty files.
$uploads = WP_CONTENT_DIR . '/uploads';
$made    = 0;
$sizes   = array( '', '-150x150', '-300x300', '-768x768', '-1024x1024' );
for ( $n = 1; $n <= $n_att; $n++ ) {
	$dir = sprintf( '%s/%d/%02d', $uploads, 2024 + $n % 2, 1 + $n % 12 );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	$base = sprintf( 'img-%08d', $abase + $n );
	foreach ( $sizes as $suffix ) {
		$f = "$dir/$base$suffix.jpg";
		if ( ! file_exists( $f ) ) {
			touch( $f );
			++$made;
		}
	}
}
sa_say( "$made upload files" );

// ---- 8. finish ------------------------------------------------------------------------------------------------------
foreach ( array( 'sa_map', 'sa_imap', 'sa_omap', 'sa_plan' ) as $t ) {
	sa_q( "DROP TABLE IF EXISTS $t" );
}
sa_q( "DELETE FROM {$P}options WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );

// Transients (expired and live) and autoloaded options, after the cleanup above.
$n_tr  = (int) round( $n_prod * 2.5 );
$n_opt = max( 50, (int) ( $n_prod / 5 ) );
sa_q( "INSERT INTO {$P}options (option_name, option_value, autoload) SELECT CONCAT('_transient_perf_', n), REPEAT('x', 200), 'off' FROM sa_nums WHERE n <= $n_tr" );
sa_q( "INSERT INTO {$P}options (option_name, option_value, autoload) SELECT CONCAT('_transient_timeout_perf_', n), IF(n % 5 < 3, UNIX_TIMESTAMP() - 3600 * (1 + n % 48), UNIX_TIMESTAMP() + 86400), 'off' FROM sa_nums WHERE n <= $n_tr" );
sa_q( "INSERT INTO {$P}options (option_name, option_value, autoload) SELECT CONCAT('perf_opt_', n), REPEAT(CHAR(65 + n % 26), 800), 'on' FROM sa_nums WHERE n <= $n_opt" );
sa_say( "$n_tr transient pairs, $n_opt autoloaded options" );
sa_q( 'DROP TABLE IF EXISTS sa_nums' );
foreach ( array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'term_relationships', 'term_taxonomy', 'wc_product_meta_lookup', 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'woocommerce_order_items', 'woocommerce_order_itemmeta' ) as $t ) {
	$wpdb->query( "ANALYZE TABLE {$P}{$t}" ); // phpcs:ignore
}

// ---- 9. integrity ----------------------------------------------------------------------------------------------------------
wp_cache_flush();
$fail = array();
$counts = array(
	'products'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}posts WHERE post_type = 'product'" ),
	'variations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}posts WHERE post_type = 'product_variation'" ),
	'lookup'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}wc_product_meta_lookup" ),
	'orders'     => $hpos ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}wc_orders WHERE type = 'shop_order'" ) : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}posts WHERE post_type = 'shop_order'" ),
	'items'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}woocommerce_order_items" ),
	'users'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$P}users" ),
	'admins'     => count( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) ),
	'customers'  => count( get_users( array( 'role' => 'customer', 'fields' => 'ID' ) ) ),
);
$counts['old_pending'] = (int) wc_get_orders( array( 'status' => 'pending', 'date_created' => '<' . ( time() - 30 * 86400 ), 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) )->total;
$counts['old_failed']  = (int) wc_get_orders( array( 'status' => 'failed', 'date_created' => '<' . ( time() - 30 * 86400 ), 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) )->total;
sa_say( 'COUNTS ' . wp_json_encode( $counts ) );

mt_srand( 42 );
for ( $s = 0; $s < 25; $s++ ) {
	$oid = $obase + mt_rand( 1, $n_orders );
	$o   = wc_get_order( $oid );
	if ( ! $o instanceof WC_Order ) {
		$fail[] = "wc_get_order($oid) failed";
		continue;
	}
	if ( 0 === count( $o->get_items() ) ) {
		$fail[] = "order $oid has no items";
	}
	if ( '' === $o->get_status() || (float) $o->get_total() <= 0 ) {
		$fail[] = "order $oid status/total odd: " . $o->get_status() . ' ' . $o->get_total();
	}
	if ( '' === $o->get_billing_email() ) {
		$fail[] = "order $oid has no billing email";
	}
}
for ( $s = 0; $s < 25; $s++ ) {
	$pid = $sbase + mt_rand( 1, $n_simple );
	$p   = wc_get_product( $pid );
	if ( ! $p instanceof WC_Product_Simple || '' === $p->get_sku() || '' === $p->get_price() ) {
		$fail[] = "simple product $pid broken";
	}
}
for ( $s = 0; $s < 10; $s++ ) {
	$pid = $vbase + mt_rand( 1, $n_var_clone ) * $vstride;
	$p   = wc_get_product( $pid );
	if ( ! $p instanceof WC_Product_Variable || count( $p->get_children() ) < 5 ) {
		$fail[] = "variable product $pid broken";
	}
}
$bp = wc_get_product( $big_id );
if ( ! $bp instanceof WC_Product_Variable || 500 !== count( $bp->get_children() ) ) {
	$fail[] = 'big product has ' . ( $bp instanceof WC_Product_Variable ? count( $bp->get_children() ) : 'n/a' ) . ' children';
}
if ( $counts['admins'] !== 5 ) {
	$fail[] = 'admins ' . $counts['admins'];
}
if ( $fail ) {
	fwrite( STDERR, 'INTEGRITY FAIL: ' . implode( '; ', array_slice( $fail, 0, 8 ) ) . "\n" );
	exit( 1 );
}
sa_say( 'INTEGRITY ok (25 orders, 25 simple, 10 variable, big product)' );
update_option( 'sa_perf_counts', $counts, false );
