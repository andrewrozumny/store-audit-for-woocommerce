<?php
/**
 * WpDebug tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Environment\WpDebug;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for WpDebug.
 *
 * @covers \StoreAudit\Checks\Environment\WpDebug
 */
final class WpDebugTest extends TestCase {

	/**
	 * Debug off is ok in any environment.
	 */
	public function test_debug_off_is_ok(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$this->assertSame( 'ok', ( new WpDebug( false, true ) )->run()->severity() );
	}

	/**
	 * Debug on in production with display on is critical.
	 */
	public function test_debug_with_display_on_production_is_critical(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$this->assertSame( 'critical', ( new WpDebug( true, true ) )->run()->severity() );
	}

	/**
	 * Debug on in production with display off is a warning.
	 */
	public function test_debug_without_display_on_production_is_warning(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$this->assertSame( 'warning', ( new WpDebug( true, false ) )->run()->severity() );
	}

	/**
	 * Debug on in a non-production environment is only informational.
	 */
	public function test_debug_on_local_is_info(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );

		$result = ( new WpDebug( true, true ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'non-production', $result->summary() );
		$this->assertStringContainsString( 'local', $result->summary() );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new WpDebug( false, false );

		$this->assertSame( 'environment_wp_debug', $check->id() );
		$this->assertSame( 'environment', $check->section() );
	}
}
