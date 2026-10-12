<?php
/**
 * Heartbeat tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Performance;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Performance\Heartbeat;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Heartbeat.
 *
 * @covers \StoreAudit\Checks\Performance\Heartbeat
 */
final class HeartbeatTest extends TestCase {

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
		return ( new Heartbeat( $data ) )->run();
	}

	/**
	 * Default is ok.
	 */
	public function test_default_is_ok(): void {
		$result = $this->run_with(
			array(
				'interval'  => null,
				'callbacks' => 7,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( 'default', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Fast interval is warning.
	 */
	public function test_fast_interval_is_warning(): void {
		$result = $this->run_with(
			array(
				'interval'  => 15,
				'callbacks' => 7,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '15 seconds', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Slow interval is ok.
	 */
	public function test_slow_interval_is_ok(): void {
		$result = $this->run_with(
			array(
				'interval'  => 60,
				'callbacks' => 7,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Many callbacks is info.
	 */
	public function test_many_callbacks_is_info(): void {
		$result = $this->run_with(
			array(
				'interval'  => null,
				'callbacks' => 14,
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( '14 functions', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new Heartbeat();

		$this->assertSame( 'performance_heartbeat', $check->id() );
		$this->assertSame( 'performance', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
