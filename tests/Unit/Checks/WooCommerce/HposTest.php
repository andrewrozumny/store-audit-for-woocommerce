<?php
/**
 * Hpos tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\Hpos;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Hpos.
 *
 * @covers \StoreAudit\Checks\WooCommerce\Hpos
 */
final class HposTest extends TestCase {

	/**
	 * HPOS on, sync off.
	 */
	public function test_hpos_without_sync_is_ok(): void {
		$this->assertSame(
			'ok',
			( new Hpos(
				static fn(): array => array(
					'hpos' => true,
					'sync' => false,
				)
			) )->run()->severity()
		);
	}

	/**
	 * HPOS on, sync on.
	 */
	public function test_hpos_with_sync_is_info(): void {
		$result = ( new Hpos(
			static fn(): array => array(
				'hpos' => true,
				'sync' => true,
			)
		) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'doubles the writes', $result->summary() );
	}

	/**
	 * Legacy storage.
	 */
	public function test_legacy_is_info(): void {
		$this->assertSame(
			'info',
			( new Hpos(
				static fn(): array => array(
					'hpos' => false,
					'sync' => false,
				)
			) )->run()->severity()
		);
		$this->assertSame(
			'info',
			( new Hpos(
				static fn(): array => array(
					'hpos' => false,
					'sync' => true,
				)
			) )->run()->severity()
		);
	}
}
