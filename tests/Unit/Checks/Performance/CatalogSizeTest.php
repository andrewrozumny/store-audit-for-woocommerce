<?php
/**
 * CatalogSize tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Performance;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Performance\CatalogSize;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for CatalogSize.
 *
 * @covers \StoreAudit\Checks\Performance\CatalogSize
 */
final class CatalogSizeTest extends TestCase {

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
		return ( new CatalogSize( $data ) )->run();
	}

	/**
	 * Small catalog is ok.
	 */
	public function test_small_catalog_is_ok(): void {
		$result = $this->run_with(
			array(
				'products'   => 18,
				'variations' => 5,
				'ext'        => false,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( '18 published products', $result->summary() . implode( ' ', $result->details() ) );
		$this->assertStringContainsString( '5 published variations', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Huge without cache is only info: the missing cache is penalised once, in Environment.
	 */
	public function test_huge_without_cache_is_info_pointing_to_object_cache(): void {
		$result = $this->run_with(
			array(
				'products'   => 900,
				'variations' => 12000,
				'ext'        => false,
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'Persistent object cache in Environment', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Huge with cache is ok.
	 */
	public function test_huge_with_cache_is_ok(): void {
		$result = $this->run_with(
			array(
				'products'   => 900,
				'variations' => 12000,
				'ext'        => true,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new CatalogSize();

		$this->assertSame( 'performance_catalog_size', $check->id() );
		$this->assertSame( 'performance', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
