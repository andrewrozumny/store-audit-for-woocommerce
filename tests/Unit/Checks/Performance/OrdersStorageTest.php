<?php
/**
 * OrdersStorage tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Performance;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Performance\OrdersStorage;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for OrdersStorage.
 *
 * @covers \StoreAudit\Checks\Performance\OrdersStorage
 */
final class OrdersStorageTest extends TestCase {

	/**
	 * Stubs for helpers the check uses.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ) {
				return round( (float) $bytes / 1048576, 1 ) . ' MB';
			}
		);
		Functions\when( 'human_time_diff' )->alias(
			static function ( $from, $to ) {
				return (string) ( (int) $to - (int) $from ) . ' seconds';
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	/**
	 * Run the check on prepared data.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	private function run_with( array $data ): \StoreAudit\Result {
		return ( new OrdersStorage( $data ) )->run();
	}

	/**
	 * Without woocommerce is info.
	 */
	public function test_without_woocommerce_is_info(): void {
		$result = $this->run_with( array( 'wc' => false ) );

		$this->assertSame( 'info', $result->severity() );
	}

	/**
	 * Few orders is info.
	 */
	public function test_few_orders_is_info(): void {
		$result = $this->run_with(
			array(
				'wc'     => true,
				'hpos'   => false,
				'orders' => 20,
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( '20 orders', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Many legacy orders is warning.
	 */
	public function test_many_legacy_orders_is_warning(): void {
		$result = $this->run_with(
			array(
				'wc'     => true,
				'hpos'   => false,
				'orders' => 150000,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
	}

	/**
	 * Many hpos orders is info.
	 */
	public function test_many_hpos_orders_is_info(): void {
		$result = $this->run_with(
			array(
				'wc'     => true,
				'hpos'   => true,
				'orders' => 150000,
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'HPOS', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new OrdersStorage();

		$this->assertSame( 'performance_orders_storage', $check->id() );
		$this->assertSame( 'performance', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
