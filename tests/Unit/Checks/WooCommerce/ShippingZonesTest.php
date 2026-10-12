<?php
/**
 * ShippingZones tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\ShippingZones;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for ShippingZones.
 *
 * @covers \StoreAudit\Checks\WooCommerce\ShippingZones
 */
final class ShippingZonesTest extends TestCase {

	/**
	 * Build data.
	 *
	 * @param array<int,array{name:string,methods:int}> $zones     Zones.
	 * @param bool                                      $fallback  Only the rest-of-world zone.
	 * @param bool                                      $shippable Sells shippable products.
	 * @param bool                                      $enabled   Shipping enabled.
	 * @return callable
	 */
	private function data( array $zones, bool $fallback = false, bool $shippable = true, bool $enabled = true ): callable {
		return static fn(): array => array(
			'enabled'         => $enabled,
			'sells_shippable' => $shippable,
			'fallback'        => $fallback,
			'zones'           => $zones,
		);
	}

	/**
	 * All good.
	 */
	public function test_zones_with_methods_are_ok(): void {
		$check = new ShippingZones(
			$this->data(
				array(
					array(
						'name'    => 'EU',
						'methods' => 2,
					),
				)
			)
		);

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Zone with no methods.
	 */
	public function test_empty_zone_warns(): void {
		$check = new ShippingZones(
			$this->data(
				array(
					array(
						'name'    => 'EU',
						'methods' => 2,
					),
					array(
						'name'    => 'US',
						'methods' => 0,
					),
				)
			)
		);

		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'US' ), $result->details() );
	}

	/**
	 * No zones, shippable products.
	 */
	public function test_no_zones_but_shippable_products_warns(): void {
		$check = new ShippingZones(
			$this->data(
				array(
					array(
						'name'    => 'Rest',
						'methods' => 0,
					),
				),
				true
			)
		);

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * No zones, digital store.
	 */
	public function test_no_zones_and_only_digital_is_info(): void {
		$check = new ShippingZones(
			$this->data(
				array(
					array(
						'name'    => 'Rest',
						'methods' => 0,
					),
				),
				true,
				false
			)
		);

		$this->assertSame( 'info', $check->run()->severity() );
	}

	/**
	 * Shipping off.
	 */
	public function test_shipping_off_is_info(): void {
		$this->assertSame( 'info', ( new ShippingZones( $this->data( array(), false, true, false ) ) )->run()->severity() );
	}

	/**
	 * The "sells something shippable" probe is one LIMIT 1 query, on the lookup table when it exists and on
	 * the postmeta flags otherwise.
	 *
	 * @dataProvider lookup_tables
	 *
	 * @param bool $has_lookup Whether the product lookup table exists.
	 * @param bool $found      Whether the probe finds a product.
	 */
	public function test_shippable_probe_is_one_limited_query( bool $has_lookup, bool $found ): void {
		$queries      = array();
		$db           = \Mockery::mock( 'wpdb' );
		$db->prefix   = 'wp_';
		$db->posts    = 'wp_posts';
		$db->postmeta = 'wp_postmeta';
		$db->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $text ): string => $text );
		$db->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $query, ...$args ): string => $query . ' /* ' . implode( ',', $args ) . ' */' );
		$db->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( string $query ) use ( &$queries, $has_lookup, $found ) {
				if ( str_contains( $query, 'SHOW TABLES' ) ) {
					return $has_lookup ? 'wp_wc_product_meta_lookup' : null;
				}
				$queries[] = $query;
				return $found ? '42' : null;
			}
		);
		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.

		$result = ShippingZones::has_shippable_product();
		unset( $GLOBALS['wpdb'] );

		$this->assertSame( $found, $result );
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'LIMIT 1', $queries[0] );
		$this->assertStringContainsString( "'publish'", $queries[0] );
		$this->assertStringContainsString( $has_lookup ? 'l.virtual = 0 AND l.downloadable = 0' : "'_virtual'", $queries[0] );
	}

	/**
	 * Both storage layouts, with and without a hit.
	 *
	 * @return array<string,array{0:bool,1:bool}>
	 */
	public static function lookup_tables(): array {
		return array(
			'lookup, found'   => array( true, true ),
			'lookup, none'    => array( true, false ),
			'postmeta, found' => array( false, true ),
			'postmeta, none'  => array( false, false ),
		);
	}
}
