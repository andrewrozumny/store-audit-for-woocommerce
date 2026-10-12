<?php
/**
 * Shipping zones check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Support\DetailList;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customers cannot receive shippable products without a shipping method.
 */
final class ShippingZones extends AbstractCheck {

	/**
	 * Returns array{enabled:bool,sells_shippable:bool,fallback:bool,zones:list<array{name:string,methods:int}>}.
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
		$this->source = \Closure::fromCallable( $source ?? array( $this, 'read_zones' ) );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_shipping_zones';
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
		return __( 'Shipping zones', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'A shipping zone without any enabled shipping method means customers from that region cannot check out, or see no way to receive their order. That is lost sales.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'In WooCommerce, Settings, Shipping, add at least one shipping method to every zone (flat rate, free shipping or local pickup).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$data  = Arr::to_array( ( $this->source )() );
		$zones = Arr::get_array( $data, 'zones' );

		if ( ! Arr::get_bool( $data, 'enabled' ) ) {
			return Result::info( __( 'Shipping is turned off in WooCommerce.', 'store-audit-for-woocommerce' ) );
		}

		$empty = array();
		foreach ( $zones as $zone ) {
			$zone = Arr::to_string_keyed( $zone );
			if ( 0 === ( $zone['methods'] ?? null ) ) {
				$empty[] = Arr::get_string( $zone, 'name' );
			}
		}

		if ( array() !== $empty ) {
			if ( Arr::get_bool( $data, 'fallback' ) && ! Arr::get_bool( $data, 'sells_shippable' ) ) {
				return Result::info( __( 'No shipping zones are set up (no products that need shipping were found).', 'store-audit-for-woocommerce' ) );
			}
			return Result::warning(
				sprintf(
					/* translators: %d: number of zones. */
					_n( '%d shipping zone has no enabled shipping method.', '%d shipping zones have no enabled shipping method.', count( $empty ), 'store-audit-for-woocommerce' ),
					count( $empty )
				),
				DetailList::cap( $empty )
			);
		}

		return Result::ok(
			sprintf(
				/* translators: %d: number of zones. */
				_n( 'Every shipping zone (%d) has at least one shipping method.', 'Every shipping zone (%d) has at least one shipping method.', count( $zones ), 'store-audit-for-woocommerce' ),
				count( $zones )
			)
		);
	}

	/**
	 * Read zones from WooCommerce.
	 *
	 * @return array{enabled:bool,sells_shippable:bool,fallback:bool,zones:list<array{name:string,methods:int}>}
	 */
	private function read_zones(): array {
		$db = Db::wpdb();
		Db::require_table( $db->prefix . 'woocommerce_shipping_zones' );
		Db::require_table( $db->prefix . 'woocommerce_shipping_zone_methods' );
		$zones = array();
		foreach ( \WC_Shipping_Zones::get_zones() as $raw_zone ) {
			$zone    = Arr::to_string_keyed( $raw_zone );
			$zones[] = array(
				'name'    => Arr::get_string( $zone, 'zone_name' ),
				'methods' => $this->count_enabled( Arr::get_array( $zone, 'shipping_methods' ) ),
			);
		}

		// With no zones of its own, the "rest of the world" zone is the only one that can carry shipping.
		// When real zones exist it is optional (stores often ship to chosen regions only), so it is not reported.
		$fallback = array() === $zones;
		if ( $fallback ) {
			$rest    = new \WC_Shipping_Zone( 0 );
			$zones[] = array(
				'name'    => __( 'Locations not covered by other zones', 'store-audit-for-woocommerce' ),
				'methods' => $this->count_enabled( $rest->get_shipping_methods() ),
			);
		}

		return array(
			'enabled'         => wc_shipping_enabled(),
			'sells_shippable' => self::has_shippable_product(),
			'fallback'        => $fallback,
			'zones'           => $zones,
		);
	}

	/**
	 * Whether at least one published product needs shipping (not virtual, not downloadable). One indexed LIMIT 1 query:
	 * the product lookup table when it exists, else the postmeta flags. wc_get_products() ran a WP_Query with a filesort
	 * over the whole catalogue for the same answer.
	 */
	public static function has_shippable_product(): bool {
		$db = Db::wpdb();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only existence probe on indexed columns; there is no WooCommerce API without a full WP_Query.
		$lookup = $db->prefix . 'wc_product_meta_lookup';
		if ( Db::table_exists( $lookup ) ) {
			$found = $db->get_var(
				$db->prepare(
					"SELECT l.product_id FROM %i l INNER JOIN %i p ON p.ID = l.product_id WHERE l.virtual = 0 AND l.downloadable = 0 AND p.post_type = 'product' AND p.post_status = 'publish' LIMIT 1",
					$lookup,
					$db->posts
				)
			);
		} else {
			$found = $db->get_var(
				$db->prepare(
					"SELECT p.ID FROM %i p INNER JOIN %i v ON v.post_id = p.ID AND v.meta_key = '_virtual' AND v.meta_value = 'no' INNER JOIN %i d ON d.post_id = p.ID AND d.meta_key = '_downloadable' AND d.meta_value = 'no' WHERE p.post_type = 'product' AND p.post_status = 'publish' LIMIT 1",
					$db->posts,
					$db->postmeta,
					$db->postmeta
				)
			);
		}
		// phpcs:enable
		return null !== $found && '' !== $found && '0' !== $found;
	}

	/**
	 * Count enabled methods.
	 *
	 * @param array<mixed> $methods Shipping method objects.
	 */
	private function count_enabled( array $methods ): int {
		$count = 0;
		foreach ( $methods as $method ) {
			if ( is_object( $method ) && isset( $method->enabled ) && 'yes' === $method->enabled ) {
				++$count;
			}
		}
		return $count;
	}
}
