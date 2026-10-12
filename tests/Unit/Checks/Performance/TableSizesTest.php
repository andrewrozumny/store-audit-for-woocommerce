<?php
/**
 * TableSizes tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Performance;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Performance\TableSizes;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for TableSizes.
 *
 * @covers \StoreAudit\Checks\Performance\TableSizes
 */
final class TableSizesTest extends TestCase {

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
		return ( new TableSizes( $data ) )->run();
	}

	/**
	 * Normal sizes are info.
	 */
	public function test_normal_sizes_are_info(): void {
		$result = $this->run_with(
			array(
				'sizes' => array(
					'options'  => 2097152,
					'postmeta' => 52428800,
					'posts'    => 10485760,
				),
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'postmeta: 50 MB', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Huge postmeta is warning.
	 */
	public function test_huge_postmeta_is_warning(): void {
		$result = $this->run_with(
			array(
				'sizes' => array(
					'options'  => 2097152,
					'postmeta' => 2097152000,
					'posts'    => 10485760,
				),
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( 'postmeta', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Huge options is warning.
	 */
	public function test_huge_options_is_warning(): void {
		$result = $this->run_with(
			array(
				'sizes' => array(
					'options'  => 209715200,
					'postmeta' => 5242880,
				),
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( 'options', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * No data is info.
	 */
	public function test_no_data_is_info(): void {
		$result = $this->run_with( array( 'sizes' => array() ) );

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'Could not read', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new TableSizes();

		$this->assertSame( 'performance_table_sizes', $check->id() );
		$this->assertSame( 'performance', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
