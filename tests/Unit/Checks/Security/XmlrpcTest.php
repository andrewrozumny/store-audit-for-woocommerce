<?php
/**
 * Xmlrpc tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\Xmlrpc;

/**
 * Tests for Xmlrpc.
 *
 * @covers \StoreAudit\Checks\Security\Xmlrpc
 */
final class XmlrpcTest extends ProbeTestCase {

	/**
	 * An answering endpoint is a warning and receives a POST.
	 */
	public function test_open_endpoint_is_warning(): void {
		$probe = $this->probe( array( '/xmlrpc.php' => array( 200, '<?xml version="1.0"?><methodResponse><params></params></methodResponse>' ) ) );

		$this->assertSame( 'warning', ( new Xmlrpc( $probe ) )->run()->severity() );
		$this->assertSame( 'POST', $this->requests[0]['method'] );
	}

	/**
	 * Blocked endpoints and fault answers are fine.
	 */
	public function test_blocked_endpoint_is_ok(): void {
		$this->assertSame( 'ok', ( new Xmlrpc( $this->probe( array( '/xmlrpc.php' => array( 403, '' ) ) ) ) )->run()->severity() );
		$this->assertSame( 'ok', ( new Xmlrpc( $this->probe( array( '/xmlrpc.php' => array( 200, '<methodResponse><fault></fault></methodResponse>' ) ) ) ) )->run()->severity() );
	}

	/**
	 * Without a loopback the check is informational.
	 */
	public function test_unreachable_is_info(): void {
		$this->assertSame( 'info', ( new Xmlrpc( $this->broken_probe() ) )->run()->severity() );
	}

	/**
	 * Metadata.
	 */
	public function test_metadata(): void {
		$this->assertSame( 'security_xmlrpc', ( new Xmlrpc() )->id() );
		$this->assertSame( 'security', ( new Xmlrpc() )->section() );
	}
}
