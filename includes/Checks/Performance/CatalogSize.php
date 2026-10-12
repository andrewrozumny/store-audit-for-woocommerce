<?php
/**
 * Catalog size check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Performance;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog size.
 */
final class CatalogSize extends AbstractCheck {

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
		return 'performance_catalog_size';
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
		return __( 'Catalog size', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'The number of products and variations decides how much work the database does on every search, filter and export. Big catalogs need a persistent object cache to stay fast.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'With a very large catalog, ask your host for Redis or Memcached and keep unused variations out of the store.', 'store-audit-for-woocommerce' );
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$variations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status = 'publish'" );
		// phpcs:enable

		return array(
			'products'   => $products,
			'variations' => $variations,
			'ext'        => wp_using_ext_object_cache(),
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$products   = (int) ( $d['products'] ?? 0 );
		$variations = (int) ( $d['variations'] ?? 0 );
		$summary    = sprintf(
			/* translators: 1: number of products, 2: number of variations. */
			__( '%1$d published products and %2$d published variations.', 'store-audit-for-woocommerce' ),
			$products,
			$variations
		);

		if ( $variations > 10000 && empty( $d['ext'] ) ) {
			return Result::warning( $summary . ' ' . __( 'A catalog this size runs slowly without a persistent object cache.', 'store-audit-for-woocommerce' ) );
		}
		return Result::info( $summary );
	}
}
