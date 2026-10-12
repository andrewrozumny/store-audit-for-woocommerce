<?php
/**
 * Order storage check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Performance;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order storage.
 */
final class OrdersStorage extends AbstractCheck {

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
		return 'performance_orders_storage';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::PERFORMANCE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Order storage', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Older WooCommerce stores keep orders mixed with posts, which gets slow as orders pile up. The dedicated orders tables (HPOS) are faster for big stores.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Switch to High-Performance Order Storage in WooCommerce > Settings > Advanced > Features (back up first and check your plugins support it).', 'store-audit-for-woocommerce' );
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
		if ( ! function_exists( 'WC' ) ) {
			return array( 'wc' => false );
		}
		$hpos = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( $hpos ) {
			$orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" );
		} else {
			$orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'" );
		}
		// phpcs:enable

		return array(
			'wc'     => true,
			'hpos'   => $hpos,
			'orders' => $orders,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		if ( empty( $d['wc'] ) ) {
			return Result::info( __( 'WooCommerce is not active, so there are no orders to count.', 'store-audit-for-woocommerce' ) );
		}
		$orders  = (int) ( $d['orders'] ?? 0 );
		$hpos    = ! empty( $d['hpos'] );
		$storage = $hpos ? __( 'dedicated orders tables (HPOS)', 'store-audit-for-woocommerce' ) : __( 'the posts tables (legacy)', 'store-audit-for-woocommerce' );
		$summary = sprintf(
			/* translators: 1: number of orders, 2: storage type. */
			_n( '%1$d order, stored in %2$s.', '%1$d orders, stored in %2$s.', $orders, 'store-audit-for-woocommerce' ),
			$orders,
			$storage
		);

		if ( $orders > 100000 && ! $hpos ) {
			return Result::warning( $summary . ' ' . __( 'With this many orders the legacy storage slows down the admin and reports.', 'store-audit-for-woocommerce' ) );
		}
		return Result::info( $summary );
	}
}
