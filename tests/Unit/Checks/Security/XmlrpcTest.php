<?php
/**
 * Xmlrpc tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use Brain\Monkey\Functions;
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

		$this->assertSame( 'warning', ( new Xmlrpc( $probe, static fn(): array => array() ) )->run()->severity() );
		$this->assertSame( 'POST', $this->requests[0]['method'] );
	}

	/**
	 * Route that answers like an open XML-RPC endpoint.
	 *
	 * @return array<string,array{0:int,1:string}>
	 */
	private function open_routes(): array {
		return array( '/xmlrpc.php' => array( 200, '<?xml version="1.0"?><methodResponse><params></params></methodResponse>' ) );
	}

	/**
	 * Jetpack and WooPayments need xmlrpc.php, so an open endpoint is only info with a narrower advice.
	 */
	public function test_open_endpoint_with_jetpack_is_info_with_specific_advice(): void {
		$check  = new Xmlrpc( $this->probe( $this->open_routes() ), static fn(): array => array( 'Jetpack' ) );
		$result = $check->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'system.multicall', $result->fix_hint() );
		$this->assertContains( 'Needed by: Jetpack', $result->details() );
	}

	/**
	 * Without Jetpack or its connection the open endpoint stays a warning.
	 */
	public function test_open_endpoint_without_jetpack_stays_warning(): void {
		$check = new Xmlrpc( $this->probe( $this->open_routes() ), static fn(): array => array() );

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * The live detector sees Jetpack, WooPayments and a stored Jetpack connection.
	 */
	public function test_default_detector_reads_plugins_and_connection(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $fallback ) => 'active_plugins' === $name ? array( 'woocommerce-payments/woocommerce-payments.php' ) : ( 'jetpack_options' === $name ? array( 'id' => 77 ) : $fallback )
		);

		$result = ( new Xmlrpc( $this->probe( $this->open_routes() ) ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertContains( 'Needed by: WooPayments, Jetpack connection', $result->details() );
	}

	/**
	 * Blocked endpoints and fault answers are fine.
	 */
	public function test_blocked_endpoint_is_ok(): void {
		$this->assertSame( 'ok', ( new Xmlrpc( $this->probe( array( '/xmlrpc.php' => array( 403, '' ) ) ) ) )->run()->severity() );
		$this->assertSame( 'ok', ( new Xmlrpc( $this->probe( array( '/xmlrpc.php' => array( 200, '<methodResponse><fault></fault></methodResponse>' ) ) ) ) )->run()->severity() );
	}

	/**
	 * Without a loopback the check is not checked (an error row), never a pass.
	 */
	public function test_unreachable_is_not_checked(): void {
		$this->assertSame( 'error', ( new Xmlrpc( $this->broken_probe() ) )->run()->severity() );
	}

	/**
	 * Metadata.
	 */
	public function test_metadata(): void {
		$this->assertSame( 'security_xmlrpc', ( new Xmlrpc() )->id() );
		$this->assertSame( 'security', ( new Xmlrpc() )->section() );
	}
}
