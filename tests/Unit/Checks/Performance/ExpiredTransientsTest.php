<?php
/**
 * ExpiredTransients tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Performance;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Performance\ExpiredTransients;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for ExpiredTransients.
 *
 * @covers \StoreAudit\Checks\Performance\ExpiredTransients
 */
final class ExpiredTransientsTest extends TestCase {

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
		return ( new ExpiredTransients( $data ) )->run();
	}

	/**
	 * None is ok.
	 */
	public function test_none_is_ok(): void {
		$result = $this->run_with( array( 'count' => 0 ) );

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Some is info.
	 */
	public function test_some_is_info(): void {
		$result = $this->run_with( array( 'count' => 40 ) );

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( '40 expired', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Many is warning.
	 */
	public function test_many_is_warning(): void {
		$result = $this->run_with( array( 'count' => 501 ) );

		$this->assertSame( 'warning', $result->severity() );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new ExpiredTransients();

		$this->assertSame( 'performance_expired_transients', $check->id() );
		$this->assertSame( 'performance', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
