<?php
/**
 * WpVersion tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Environment\WpVersion;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for WpVersion.
 *
 * @covers \StoreAudit\Checks\Environment\WpVersion
 */
final class WpVersionTest extends TestCase {

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
		return ( new WpVersion( $data ) )->run();
	}

	/**
	 * Up to date is ok.
	 */
	public function test_up_to_date_is_ok(): void {
		$result = $this->run_with(
			array(
				'current' => '7.1.3',
				'latest'  => '7.1.3',
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Major behind is warning.
	 */
	public function test_major_behind_is_warning(): void {
		$result = $this->run_with(
			array(
				'current' => '6.8.1',
				'latest'  => '7.0',
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '7.0', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Minor behind is info.
	 */
	public function test_minor_behind_is_info(): void {
		$result = $this->run_with(
			array(
				'current' => '7.1.1',
				'latest'  => '7.1.3',
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'minor', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Missing update data is info.
	 */
	public function test_missing_update_data_is_info(): void {
		$result = $this->run_with(
			array(
				'current' => '7.1.3',
				'latest'  => null,
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'Could not compare', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new WpVersion();

		$this->assertSame( 'environment_wp_version', $check->id() );
		$this->assertSame( 'environment', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
