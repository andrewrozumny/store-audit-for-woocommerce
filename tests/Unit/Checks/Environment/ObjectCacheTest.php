<?php
/**
 * ObjectCache tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Environment\ObjectCache;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for ObjectCache.
 *
 * @covers \StoreAudit\Checks\Environment\ObjectCache
 */
final class ObjectCacheTest extends TestCase {

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
		return ( new ObjectCache( $data ) )->run();
	}

	/**
	 * External cache is ok.
	 */
	public function test_external_cache_is_ok(): void {
		$result = $this->run_with(
			array(
				'ext'      => true,
				'products' => 5000,
				'orders'   => 90000,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Large store without cache is warning.
	 */
	public function test_large_store_without_cache_is_warning(): void {
		$result = $this->run_with(
			array(
				'ext'      => false,
				'products' => 1500,
				'orders'   => 10,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
	}

	/**
	 * Many orders without cache is warning.
	 */
	public function test_many_orders_without_cache_is_warning(): void {
		$result = $this->run_with(
			array(
				'ext'      => false,
				'products' => 10,
				'orders'   => 6000,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
	}

	/**
	 * Small store without cache is info.
	 */
	public function test_small_store_without_cache_is_info(): void {
		$result = $this->run_with(
			array(
				'ext'      => false,
				'products' => 18,
				'orders'   => 3,
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'not needed yet', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new ObjectCache();

		$this->assertSame( 'environment_object_cache', $check->id() );
		$this->assertSame( 'environment', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
