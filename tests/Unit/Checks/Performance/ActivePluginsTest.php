<?php
/**
 * ActivePlugins tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Performance;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Performance\ActivePlugins;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for ActivePlugins.
 *
 * @covers \StoreAudit\Checks\Performance\ActivePlugins
 */
final class ActivePluginsTest extends TestCase {

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
		return ( new ActivePlugins( $data ) )->run();
	}

	/**
	 * Few is ok.
	 */
	public function test_few_is_ok(): void {
		$result = $this->run_with(
			array(
				'active'  => 12,
				'network' => 0,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( '12 plugins', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Over 25 is info.
	 */
	public function test_over_25_is_info(): void {
		$result = $this->run_with(
			array(
				'active'  => 30,
				'network' => 0,
			)
		);

		$this->assertSame( 'info', $result->severity() );
	}

	/**
	 * Over 40 is warning.
	 */
	public function test_over_40_is_warning(): void {
		$result = $this->run_with(
			array(
				'active'  => 35,
				'network' => 8,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( 'network-wide', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new ActivePlugins();

		$this->assertSame( 'performance_active_plugins', $check->id() );
		$this->assertSame( 'performance', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
