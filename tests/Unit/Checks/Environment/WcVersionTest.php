<?php
/**
 * WcVersion tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Environment\WcVersion;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for WcVersion.
 *
 * @covers \StoreAudit\Checks\Environment\WcVersion
 */
final class WcVersionTest extends TestCase {

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
		return ( new WcVersion( $data ) )->run();
	}

	/**
	 * Not active is info.
	 */
	public function test_not_active_is_info(): void {
		$result = $this->run_with( array( 'active' => false ) );

		$this->assertSame( 'info', $result->severity() );
	}

	/**
	 * Very old is critical.
	 */
	public function test_very_old_is_critical(): void {
		$result = $this->run_with(
			array(
				'active'  => true,
				'current' => '8.5.0',
				'latest'  => '8.5.0',
			)
		);

		$this->assertSame( 'critical', $result->severity() );
		$this->assertStringContainsString( '8.5.0', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Up to date is ok.
	 */
	public function test_up_to_date_is_ok(): void {
		$result = $this->run_with(
			array(
				'active'  => true,
				'current' => '11.2.1',
				'latest'  => '11.2.1',
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Newer branch available is warning.
	 */
	public function test_newer_branch_available_is_warning(): void {
		$result = $this->run_with(
			array(
				'active'  => true,
				'current' => '10.1.0',
				'latest'  => '11.2.1',
			)
		);

		$this->assertSame( 'warning', $result->severity() );
	}

	/**
	 * Patch available is info.
	 */
	public function test_patch_available_is_info(): void {
		$result = $this->run_with(
			array(
				'active'  => true,
				'current' => '11.2.0',
				'latest'  => '11.2.1',
			)
		);

		$this->assertSame( 'info', $result->severity() );
	}

	/**
	 * No update data is info.
	 */
	public function test_no_update_data_is_info(): void {
		$result = $this->run_with(
			array(
				'active'  => true,
				'current' => '11.2.1',
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
		$check = new WcVersion();

		$this->assertSame( 'environment_wc_version', $check->id() );
		$this->assertSame( 'environment', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
