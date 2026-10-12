<?php
/**
 * Autoload tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Performance;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Performance\Autoload;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Autoload.
 *
 * @covers \StoreAudit\Checks\Performance\Autoload
 */
final class AutoloadTest extends TestCase {

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
		return ( new Autoload( $data ) )->run();
	}

	/**
	 * Small autoload is ok.
	 */
	public function test_small_autoload_is_ok(): void {
		$result = $this->run_with(
			array(
				'count' => 200,
				'bytes' => 307200,
				'top'   => array(
					array(
						'name' => 'alloptions_big',
						'size' => 102400,
					),
				),
			)
		);

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( '200 options', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Over 1mb is warning.
	 */
	public function test_over_1mb_is_warning(): void {
		$result = $this->run_with(
			array(
				'count' => 900,
				'bytes' => 2097152,
				'top'   => array(
					array(
						'name' => 'big_opt',
						'size' => 1048576,
					),
				),
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( 'big_opt', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Over 3mb is critical.
	 */
	public function test_over_3mb_is_critical(): void {
		$result = $this->run_with(
			array(
				'count' => 900,
				'bytes' => 4194304,
				'top'   => array(),
			)
		);

		$this->assertSame( 'critical', $result->severity() );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new Autoload();

		$this->assertSame( 'performance_autoload', $check->id() );
		$this->assertSame( 'performance', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
