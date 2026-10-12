<?php
/**
 * ReadmeLicense tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\ReadmeLicense;

/**
 * Tests for ReadmeLicense.
 *
 * @covers \StoreAudit\Checks\Security\ReadmeLicense
 */
final class ReadmeLicenseTest extends ProbeTestCase {

	/**
	 * Files that exist and are served are reported as info.
	 */
	public function test_served_files_are_info(): void {
		$probe  = $this->probe(
			array(
				'/readme.html' => array( 200, '' ),
				'/license.txt' => array( 200, '' ),
			)
		);
		$result = ( new ReadmeLicense( $probe, '/var/www', static fn () => true ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertSame( array( 'readme.html', 'license.txt' ), $result->details() );
	}

	/**
	 * Files missing on disk are not probed and are ok.
	 */
	public function test_missing_files_are_ok(): void {
		$result = ( new ReadmeLicense( $this->probe( array() ), '/var/www', static fn () => false ) )->run();

		$this->assertSame( 'ok', $result->severity() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Files that exist but answer 403 are ok.
	 */
	public function test_blocked_files_are_ok(): void {
		$probe = $this->probe( array( '/readme.html' => array( 403, '' ) ) );

		$this->assertSame( 'ok', ( new ReadmeLicense( $probe, '/var/www', static fn () => true ) )->run()->severity() );
	}

	/**
	 * Without a loopback the check is not checked (an error row), never a pass.
	 */
	public function test_unreachable_is_not_checked(): void {
		$this->assertSame( 'error', ( new ReadmeLicense( $this->broken_probe(), '/var/www', static fn () => true ) )->run()->severity() );
	}
}
