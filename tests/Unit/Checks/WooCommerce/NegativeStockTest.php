<?php
/**
 * NegativeStock tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\NegativeStock;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for NegativeStock.
 *
 * @covers \StoreAudit\Checks\WooCommerce\NegativeStock
 */
final class NegativeStockTest extends TestCase {

	/**
	 * No negative stock.
	 */
	public function test_none_is_ok(): void {
		$check = new NegativeStock(
			static fn(): array => array(
				'managing' => true,
				'count'    => 0,
				'names'    => array(),
			)
		);

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Some negative.
	 */
	public function test_negative_warns_with_names(): void {
		$check = new NegativeStock(
			static fn(): array => array(
				'managing' => true,
				'count'    => 25,
				'names'    => array( 'Mug', 'Cap' ),
			)
		);

		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'Mug', 'Cap', 'and 23 more' ), $result->details() );
	}

	/**
	 * Stock management off.
	 */
	public function test_stock_management_off_is_info(): void {
		$check = new NegativeStock(
			static fn(): array => array(
				'managing' => false,
				'count'    => 0,
				'names'    => array(),
			)
		);

		$this->assertSame( 'info', $check->run()->severity() );
	}
}
