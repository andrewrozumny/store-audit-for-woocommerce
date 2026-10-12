<?php
/**
 * ExposedDotfiles tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\ExposedDotfiles;

/**
 * Tests for ExposedDotfiles.
 *
 * @covers \StoreAudit\Checks\Security\ExposedDotfiles
 */
final class ExposedDotfilesTest extends ProbeTestCase {

	/**
	 * A served .git/HEAD and .env are critical and never echoed.
	 */
	public function test_served_files_are_critical(): void {
		$probe  = $this->probe(
			array(
				'/.git/HEAD' => array( 200, "ref: refs/heads/main\n" ),
				'/.env'      => array( 200, 'SECRET_KEY=abc123' ),
			)
		);
		$result = ( new ExposedDotfiles( $probe, '/var/www', static fn () => false ) )->run();

		$this->assertSame( 'critical', $result->severity() );
		$this->assertSame( array( '/.git/HEAD', '/.env' ), $result->details() );
		$this->assertStringNotContainsString( 'abc123', $result->summary() );
	}

	/**
	 * An HTML catch-all page for /.env is not a leak.
	 */
	public function test_html_catch_all_is_not_a_leak(): void {
		$probe = $this->probe( array( '/.env' => array( 200, '<!DOCTYPE html><html></html>' ) ) );

		$this->assertSame( 'ok', ( new ExposedDotfiles( $probe, '/var/www', static fn () => false ) )->run()->severity() );
	}

	/**
	 * Files on disk but not served are info.
	 */
	public function test_on_disk_but_hidden_is_info(): void {
		$result = ( new ExposedDotfiles( $this->probe( array() ), '/var/www', static fn ( string $path ) => str_ends_with( $path, '.env' ) ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertSame( array( '.env' ), $result->details() );
	}

	/**
	 * Without a loopback the check is informational.
	 */
	public function test_unreachable_is_info(): void {
		$this->assertSame( 'info', ( new ExposedDotfiles( $this->broken_probe(), '/var/www', static fn () => false ) )->run()->severity() );
	}
}
