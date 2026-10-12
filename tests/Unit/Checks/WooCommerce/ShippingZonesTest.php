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
}
