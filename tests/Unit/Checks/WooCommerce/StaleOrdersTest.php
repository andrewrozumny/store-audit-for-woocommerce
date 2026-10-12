<?php
/**
 * StaleOrders tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\StaleOrders;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for StaleOrders.
 *
 * @covers \StoreAudit\Checks\WooCommerce\StaleOrders
 */
final class StaleOrdersTest extends TestCase {

	/**
	 * No stale orders.
	 */
	public function test_none_is_ok(): void {
		$this->assertSame(
			'ok',
			( new StaleOrders(
				static fn(): array => array(
					'pending' => 0,
					'failed'  => 0,
				)
			) )->run()->severity()
		);
	}

	/**
	 * Some stale orders.
	 */
	public function test_some_is_info(): void {
		$result = ( new StaleOrders(
			static fn(): array => array(
				'pending' => 4,
				'failed'  => 2,
			)
		) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( '4 pending, 2 failed', $result->summary() );
	}

	/**
	 * Many stale orders.
	 */
	public function test_over_500_warns(): void {
		$this->assertSame(
			'warning',
			( new StaleOrders(
				static fn(): array => array(
					'pending' => 501,
					'failed'  => 0,
				)
			) )->run()->severity()
		);
	}
}
