<?php
/**
 * Taxes tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\Taxes;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Taxes.
 *
 * @covers \StoreAudit\Checks\WooCommerce\Taxes
 */
final class TaxesTest extends TestCase {

	/**
	 * Taxes off.
	 */
	public function test_disabled_is_info(): void {
		$check = new Taxes(
			static fn(): array => array(
				'enabled' => false,
				'rates'   => 0,
			)
		);

		$this->assertSame( 'info', $check->run()->severity() );
	}

	/**
	 * Taxes on, no rates.
	 */
	public function test_enabled_without_rates_warns(): void {
		$check = new Taxes(
			static fn(): array => array(
				'enabled' => true,
				'rates'   => 0,
			)
		);

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * Taxes on with rates.
	 */
	public function test_enabled_with_rates_is_ok(): void {
		$check = new Taxes(
			static fn(): array => array(
				'enabled' => true,
				'rates'   => 3,
			)
		);

		$this->assertSame( 'ok', $check->run()->severity() );
	}
}
