<?php
/**
 * PhpVersion tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use DateTimeImmutable;
use StoreAudit\Checks\Environment\PhpVersion;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for PhpVersion.
 *
 * @covers \StoreAudit\Checks\Environment\PhpVersion
 */
final class PhpVersionTest extends TestCase {

	/**
	 * Run the check for a version on a given day.
	 *
	 * @param string $version PHP version.
	 * @param string $today   Date.
	 */
	private function severity( string $version, string $today = '2026-10-12' ): string {
		return ( new PhpVersion( $version, new DateTimeImmutable( $today ) ) )->run()->severity();
	}

	/**
	 * Old branches are critical.
	 */
	public function test_end_of_life_is_critical(): void {
		$this->assertSame( 'critical', $this->severity( '7.4.33' ) );
		$this->assertSame( 'critical', $this->severity( '5.6.40' ) );
		$this->assertSame( 'critical', $this->severity( '8.0.30' ) );
		$this->assertSame( 'critical', $this->severity( '8.1.27' ) );
	}

	/**
	 * Summary mentions the branch and the date.
	 */
	public function test_critical_summary_has_branch_and_date(): void {
		$result = ( new PhpVersion( '8.0.30', new DateTimeImmutable( '2026-10-12' ) ) )->run();

		$this->assertSame( 'PHP 8.0 reached end of life on 26 Nov 2023', $result->summary() );
	}

	/**
	 * Within six months of the end of security support is a warning.
	 */
	public function test_close_to_end_is_warning(): void {
		$this->assertSame( 'warning', $this->severity( '8.2.20' ) );
		// Boundary: 183 days before 2026-12-31 is 2026-07-01.
		$this->assertSame( 'ok', $this->severity( '8.2.20', '2026-06-30' ) );
		$this->assertSame( 'warning', $this->severity( '8.2.20', '2026-07-01' ) );
		// Last day of support is still supported, the next day is end of life.
		$this->assertSame( 'warning', $this->severity( '8.2.20', '2026-12-31' ) );
		$this->assertSame( 'critical', $this->severity( '8.2.20', '2027-01-01' ) );
	}

	/**
	 * Supported branches are ok.
	 */
	public function test_supported_is_ok(): void {
		$this->assertSame( 'ok', $this->severity( '8.3.12' ) );
		$this->assertSame( 'ok', $this->severity( '8.4.1' ) );
		$this->assertSame( 'ok', $this->severity( '8.5.10' ) );
	}

	/**
	 * A branch newer than the table is assumed fine.
	 */
	public function test_unknown_future_branch_is_ok(): void {
		$this->assertSame( 'ok', $this->severity( '9.0.0' ) );
	}

	/**
	 * Garbage version strings give an error result, not a crash.
	 */
	public function test_unparseable_version_is_error(): void {
		$this->assertSame( 'error', $this->severity( 'banana' ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new PhpVersion();

		$this->assertSame( 'environment_php_version', $check->id() );
		$this->assertSame( 'environment', $check->section() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
	}
}
