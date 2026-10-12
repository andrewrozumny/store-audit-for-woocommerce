<?php
/**
 * Negative stock check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Plugins\DetailList;
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
	 * Returns array{managing:bool,count:int,names:list<string>}.
	 *
	 * @var callable
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? array( $this, 'read_stock' );
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
	 * (one COUNT plus a LIMIT 20 sample), else a postmeta query on meta_key _stock (indexed by meta_key).
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only audit counts on indexed columns; there is no WooCommerce API for them.
		if ( $has_lookup ) {
			$count = Arr::to_int( $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i WHERE stock_quantity < 0', $lookup ) ) );
			$ids   = $db->get_col( $db->prepare( 'SELECT product_id FROM %i WHERE stock_quantity < 0 ORDER BY product_id LIMIT 20', $lookup ) );
		} else {
			$count = Arr::to_int( $db->get_var( $db->prepare( "SELECT COUNT(*) FROM %i WHERE meta_key = '_stock' AND CAST(meta_value AS SIGNED) < 0", $db->postmeta ) ) );
			$ids   = $db->get_col( $db->prepare( "SELECT post_id FROM %i WHERE meta_key = '_stock' AND CAST(meta_value AS SIGNED) < 0 ORDER BY post_id LIMIT 20", $db->postmeta ) );
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
