<?php
/**
 * SslAdmin tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\SslAdmin;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for SslAdmin.
 *
 * @covers \StoreAudit\Checks\Security\SslAdmin
 */
final class SslAdminTest extends TestCase {

	/**
	 * Forced is ok.
	 */
	public function test_forced_is_ok(): void {
		$this->assertSame( 'ok', ( new SslAdmin( true, true ) )->run()->severity() );
	}

	/**
	 * Https without force is info.
	 */
	public function test_not_forced_on_https_is_info(): void {
		$this->assertSame( 'info', ( new SslAdmin( false, true ) )->run()->severity() );
	}

	/**
	 * A plain http site is not applicable.
	 */
	public function test_http_site_is_info(): void {
		$result = ( new SslAdmin( false, false ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'not https', $result->summary() );
	}
}
