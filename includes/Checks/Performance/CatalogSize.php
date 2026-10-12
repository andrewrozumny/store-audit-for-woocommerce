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
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

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
		$db       = Db::wpdb();
		$counts   = wp_count_posts( 'product' );
		$products = Arr::to_int( $counts->publish ?? null );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only audit query; WordPress has no API for it.
		$variations = Arr::to_int( $db->get_var( $db->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'product_variation' AND post_status = 'publish'", $db->posts ) ) );
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
		$products   = Arr::get_int( $d, 'products' );
		$variations = Arr::get_int( $d, 'variations' );
		$summary    = sprintf(
			/* translators: 1: products, for example "18 published products", 2: variations, for example "1 published variation". */
			__( '%1$s and %2$s.', 'store-audit-for-woocommerce' ),
			sprintf(
				/* translators: %d: number of products. */
				_n( '%d published product', '%d published products', $products, 'store-audit-for-woocommerce' ),
				$products
			),
			sprintf(
				/* translators: %d: number of variations. */
				_n( '%d published variation', '%d published variations', $variations, 'store-audit-for-woocommerce' ),
				$variations
			)
		);

		if ( $variations > 10000 && ! Arr::get_bool( $d, 'ext' ) ) {
			return Result::warning( $summary . ' ' . __( 'A catalog this size runs slowly without a persistent object cache.', 'store-audit-for-woocommerce' ) );
		}
		return Result::info( $summary );
	}
}
