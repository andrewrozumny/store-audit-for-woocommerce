<?php
/**
 * MemoryLimit tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Environment\MemoryLimit;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for MemoryLimit.
 *
 * @covers \StoreAudit\Checks\Environment\MemoryLimit
 */
final class MemoryLimitTest extends TestCase {

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
		return ( new MemoryLimit( $data ) )->run();
	}

	/**
	 * Under 128m is critical.
	 */
	public function test_under_128m_is_critical(): void {
		$result = $this->run_with(
			array(
				'wp'  => 67108864,
				'php' => 67108864,
			)
		);

		$this->assertSame( 'critical', $result->severity() );
	}

	/**
	 * Under 256m is warning.
	 */
	public function test_under_256m_is_warning(): void {
		$result = $this->run_with(
			array(
				'wp'  => 134217728,
				'php' => 134217728,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
	}

	/**
	 * Higher of the two counts.
	 */
	public function test_higher_of_the_two_counts(): void {
		$result = $this->run_with(
			array(
				'wp'  => 268435456,
				'php' => 134217728,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * 256m is ok.
	 */
	public function test_256m_is_ok(): void {
		$result = $this->run_with(
			array(
				'wp'  => 268435456,
				'php' => 536870912,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( '512', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Unlimited is ok.
	 */
	public function test_unlimited_is_ok(): void {
		$result = $this->run_with(
			array(
				'wp'  => 268435456,
				'php' => -1,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( 'unlimited', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new MemoryLimit();

		$this->assertSame( 'environment_memory_limit', $check->id() );
		$this->assertSame( 'environment', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
