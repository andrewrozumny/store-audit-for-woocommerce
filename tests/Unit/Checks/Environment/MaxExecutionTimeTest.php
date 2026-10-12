<?php
/**
 * MaxExecutionTime tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Environment\MaxExecutionTime;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for MaxExecutionTime.
 *
 * @covers \StoreAudit\Checks\Environment\MaxExecutionTime
 */
final class MaxExecutionTimeTest extends TestCase {

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
		return ( new MaxExecutionTime( $data ) )->run();
	}

	/**
	 * Short limit is warning.
	 */
	public function test_short_limit_is_warning(): void {
		$result = $this->run_with(
			array(
				'seconds' => 10,
				'cli'     => false,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '10 seconds', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * 60 seconds is ok.
	 */
	public function test_60_seconds_is_ok(): void {
		$result = $this->run_with(
			array(
				'seconds' => 60,
				'cli'     => false,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Zero is ok and noted on cli.
	 */
	public function test_zero_is_ok_and_noted_on_cli(): void {
		$result = $this->run_with(
			array(
				'seconds' => 0,
				'cli'     => true,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( 'command line', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Zero on web is ok.
	 */
	public function test_zero_on_web_is_ok(): void {
		$result = $this->run_with(
			array(
				'seconds' => 0,
				'cli'     => false,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new MaxExecutionTime();

		$this->assertSame( 'environment_max_execution_time', $check->id() );
		$this->assertSame( 'environment', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
