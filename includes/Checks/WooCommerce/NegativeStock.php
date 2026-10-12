<?php
/**
 * Negative stock check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Products with stock below zero point to overselling.
 */
final class NegativeStock extends AbstractCheck {

	/**
	 * SQL that leaves out products allowed to go below zero, for the product lookup table: backorders on the product itself,
	 * or a variation that inherits ("parent") from a product with backorders on.
	 */
	private const NO_BACKORDERS_LOOKUP = " AND NOT EXISTS ( SELECT 1 FROM %i bo WHERE bo.post_id = s.product_id AND bo.meta_key = '_backorders' AND bo.meta_value IN ('yes','notify') )"
		. " AND NOT EXISTS ( SELECT 1 FROM %i vp JOIN %i bp ON bp.post_id = vp.post_parent AND bp.meta_key = '_backorders' AND bp.meta_value IN ('yes','notify')"
		. " JOIN %i bv ON bv.post_id = vp.ID AND bv.meta_key = '_backorders' AND bv.meta_value = 'parent' WHERE vp.ID = s.product_id )";

	/**
	 * The same for the postmeta fallback.
	 */
	private const NO_BACKORDERS_META = " AND NOT EXISTS ( SELECT 1 FROM %i bo WHERE bo.post_id = s.post_id AND bo.meta_key = '_backorders' AND bo.meta_value IN ('yes','notify') )"
		. " AND NOT EXISTS ( SELECT 1 FROM %i vp JOIN %i bp ON bp.post_id = vp.post_parent AND bp.meta_key = '_backorders' AND bp.meta_value IN ('yes','notify')"
		. " JOIN %i bv ON bv.post_id = vp.ID AND bv.meta_key = '_backorders' AND bv.meta_value = 'parent' WHERE vp.ID = s.post_id )";

	/**
	 * Returns array{managing:bool,count:int,names:list<string>}.
	 *
	 * @var \Closure
	 */
	private \Closure $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = \Closure::fromCallable( $source ?? array( $this, 'read_stock' ) );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_negative_stock';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::WOOCOMMERCE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Negative stock', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'A negative stock number means you sold more than you had (backorders or counting errors). Customers wait for goods you may not be able to deliver.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Correct the stock quantity of these products, or review your backorder settings.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$data  = Arr::to_array( ( $this->source )() );
		$count = Arr::get_int( $data, 'count' );

		if ( ! Arr::get_bool( $data, 'managing' ) ) {
			return Result::info( __( 'Stock management is turned off, so stock levels are not tracked.', 'store-audit-for-woocommerce' ) );
		}

		if ( 0 === $count ) {
			return Result::ok( __( 'No product has negative stock.', 'store-audit-for-woocommerce' ) );
		}

		$names = Arr::to_string_list( Arr::get_array( $data, 'names' ) );
		if ( $count > count( $names ) ) {
			$names[] = sprintf(
				/* translators: %d: number of items not shown. */
				__( 'and %d more', 'store-audit-for-woocommerce' ),
				$count - count( $names )
			);
		}

		return Result::warning(
			sprintf(
				/* translators: %d: number of products. */
				_n( '%d product has negative stock.', '%d products have negative stock.', $count, 'store-audit-for-woocommerce' ),
				$count
			),
			$names
		);
	}

	/**
	 * Read negative-stock products. Uses the indexed product lookup table when it exists
	 * (one COUNT plus a LIMIT 20 sample, both without products that take backorders), else a postmeta query on meta_key _stock (indexed by meta_key).
	 *
	 * @return array{managing:bool,count:int,names:list<string>}
	 */
	private function read_stock(): array {
		$db = Db::wpdb();

		$managing = 'yes' === get_option( 'woocommerce_manage_stock', 'yes' );
		if ( ! $managing ) {
			return array(
				'managing' => false,
				'count'    => 0,
				'names'    => array(),
			);
		}

		$lookup     = $db->prefix . 'wc_product_meta_lookup';
		$has_lookup = Db::table_exists( $lookup );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Read-only audit counts on indexed columns; there is no WooCommerce API for them. The fragments are constants, every value goes through prepare().
		if ( $has_lookup ) {
			$from  = 'FROM %i s WHERE s.stock_quantity < 0' . self::NO_BACKORDERS_LOOKUP;
			$args  = array( $lookup, $db->postmeta, $db->posts, $db->postmeta, $db->postmeta );
			$count = Arr::to_int( $db->get_var( $db->prepare( 'SELECT COUNT(*) ' . $from, $args ) ) );
			$ids   = $db->get_col( $db->prepare( 'SELECT s.product_id ' . $from . ' ORDER BY s.product_id LIMIT 20', $args ) );
		} else {
			$from  = "FROM %i s WHERE s.meta_key = '_stock' AND CAST(s.meta_value AS SIGNED) < 0" . self::NO_BACKORDERS_META;
			$args  = array( $db->postmeta, $db->postmeta, $db->posts, $db->postmeta, $db->postmeta );
			$count = Arr::to_int( $db->get_var( $db->prepare( 'SELECT COUNT(*) ' . $from, $args ) ) );
			$ids   = $db->get_col( $db->prepare( 'SELECT s.post_id ' . $from . ' ORDER BY s.post_id LIMIT 20', $args ) );
		}
		// phpcs:enable

		$names = array();
		foreach ( $ids as $id ) {
			$post_id = Arr::to_int( $id );
			$title   = get_the_title( $post_id );
			$names[] = '' !== $title ? $title : '#' . $post_id;
		}

		return array(
			'managing' => true,
			'count'    => $count,
			'names'    => $names,
		);
	}
}
