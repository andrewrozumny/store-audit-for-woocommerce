<?php
/**
 * Persistent object cache check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Environment;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent object cache.
 */
final class ObjectCache extends AbstractCheck {

	/**
	 * Data to evaluate (tests) or null to read the live site.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $data Pre-collected data for tests; null reads the live site.
	 */
	public function __construct( ?array $data = null ) {
		$this->data = $data;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'environment_object_cache';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::ENVIRONMENT;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Persistent object cache', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'An object cache (Redis or Memcached) keeps database answers in memory so big stores load much faster. Without it every page asks the database the same questions again.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Ask your host to enable Redis or Memcached and install the matching object cache plugin.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		return $this->evaluate( $this->data ?? $this->collect() );
	}

	/**
	 * Read the live site.
	 *
	 * @return array<string,mixed>
	 */
	private function collect(): array {
		global $wpdb;
		$counts   = wp_count_posts( 'product' );
		$products = isset( $counts->publish ) ? (int) $counts->publish : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" );
		} else {
			$orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'" );
		}
		// phpcs:enable

		return array(
			'ext'      => wp_using_ext_object_cache(),
			'products' => $products,
			'orders'   => $orders,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		if ( ! empty( $d['ext'] ) ) {
			return Result::ok( __( 'A persistent object cache is in use.', 'store-audit-for-woocommerce' ) );
		}
		$products = (int) ( $d['products'] ?? 0 );
		$orders   = (int) ( $d['orders'] ?? 0 );
		$details  = array(
			/* translators: %d: number of products. */
			sprintf( __( 'Published products: %d', 'store-audit-for-woocommerce' ), $products ),
			/* translators: %d: number of orders. */
			sprintf( __( 'Orders: %d', 'store-audit-for-woocommerce' ), $orders ),
		);

		if ( $products > 1000 || $orders > 5000 ) {
			return Result::warning( __( 'There is no persistent object cache, and the store is large enough to benefit from one.', 'store-audit-for-woocommerce' ), $details );
		}
		return Result::info( __( 'There is no persistent object cache. It is not needed yet at this store size.', 'store-audit-for-woocommerce' ), $details );
	}
}
