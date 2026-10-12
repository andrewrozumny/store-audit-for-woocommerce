<?php
/**
 * SelfProbe tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use Brain\Monkey\Functions;

/**
 * Tests for SelfProbe.
 *
 * @covers \StoreAudit\Checks\Security\SelfProbe
 * @covers \StoreAudit\Checks\Security\ProbeResponse
 */
final class SelfProbeTest extends ProbeTestCase {

	/**
	 * A path is requested on the site itself with safe defaults.
	 */
	public function test_get_uses_safe_defaults(): void {
		$response = $this->probe( array( '/a' => array( 200, 'hello' ) ) )->get( '/a', 100 );

		$this->assertTrue( $response->reachable() );
		$this->assertSame( 200, $response->status() );
		$this->assertSame( 'hello', $response->body() );
		$this->assertSame( 'text/html', $response->headers()['content-type'] );
		$args = $this->requests[0]['args'];
		$this->assertSame( 'https://shop.test/a', $this->requests[0]['url'] );
		$this->assertSame( 5, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( array(), $args['cookies'] );
		$this->assertSame( 100, $args['limit_response_size'] );
		$this->assertSame( 'StoreAudit/' . STORE_AUDIT_VERSION, $args['user-agent'] );
		$this->assertArrayNotHasKey( 'sslverify', $args );
	}

	/**
	 * Timeout never exceeds 5 seconds.
	 */
	public function test_timeout_is_capped(): void {
		$this->probe( array() )->head( '/a', 60 );

		$this->assertSame( 5, $this->requests[0]['args']['timeout'] );
		$this->assertSame( 'HEAD', $this->requests[0]['method'] );
	}

	/**
	 * Foreign hosts and odd targets are refused without any request.
	 */
	public function test_foreign_urls_are_refused(): void {
		$probe = $this->probe( array() );

		foreach ( array( 'https://evil.test/x', '//evil.test/x', 'ftp://shop.test/x', '/a b', '', 'http://shop.test.evil.test/x' ) as $target ) {
			$response = $probe->get( $target );
			$this->assertFalse( $response->reachable(), $target );
		}
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A full URL on the same host is allowed.
	 */
	public function test_own_full_url_is_allowed(): void {
		$response = $this->probe( array( '/wp-json/wp/v2/users' => array( 200, '[]' ) ) )->get( 'https://shop.test/wp-json/wp/v2/users' );

		$this->assertSame( 200, $response->status() );
	}

	/**
	 * Transport failures become an unreachable response.
	 */
	public function test_transport_failure_is_unreachable(): void {
		$response = $this->broken_probe()->get( '/a' );

		$this->assertFalse( $response->reachable() );
		$this->assertNotSame( '', $response->error() );
	}

	/**
	 * WP_Error answers become an unreachable response.
	 */
	public function test_wp_error_is_unreachable(): void {
		Functions\when( 'is_wp_error' )->justReturn( true );
		$error = new class() {
			/**
			 * Message.
			 */
			public function get_error_message(): string {
				return 'cURL error 60';
			}
		};
		$probe = new \StoreAudit\Checks\Security\SelfProbe( static fn () => $error );

		$response = $probe->post( '/a', 'x' );

		$this->assertFalse( $response->reachable() );
		$this->assertSame( 'cURL error 60', $response->error() );
	}

	/**
	 * An address on another host is not requested and is reported as such (not as a blocked loopback).
	 */
	public function test_other_host_is_marked_foreign(): void {
		$response = $this->probe( array() )->get( 'https://cdn.example.net/uploads/' );

		$this->assertFalse( $response->reachable() );
		$this->assertSame( 'cdn.example.net', $response->foreign() );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( '', $this->probe( array() )->get( '/a' )->foreign() );
		$this->assertSame( '', $this->probe( array() )->get( 'ftp://shop.test/x' )->foreign(), 'not an http address at all' );
	}

	/**
	 * Same host on another port or with the other scheme is another origin and is never requested.
	 */
	public function test_port_and_scheme_must_match(): void {
		$probe = $this->probe( array() );

		foreach ( array( 'https://shop.test:6379/x', 'https://shop.test:8443/wp-json/', 'http://shop.test/x', 'http://shop.test:443/x' ) as $target ) {
			$this->assertFalse( $probe->get( $target )->reachable(), $target );
		}
		$this->assertSame( array(), $this->requests );

		$this->assertTrue( $probe->get( 'https://shop.test:443/a' )->reachable(), 'the default port written out is the same origin' );
		$this->assertTrue( $probe->get( 'https://SHOP.test/a' )->reachable(), 'host case does not matter' );
	}

	/**
	 * Home and site address may use different schemes; both origins are the site's own.
	 */
	public function test_home_and_site_url_variants_are_both_own(): void {
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'http://shop.test' . $path );
		$probe = $this->probe( array() );

		$this->assertTrue( $probe->get( 'http://shop.test/a' )->reachable(), 'scheme of home_url' );
		$this->assertTrue( $probe->get( 'https://shop.test/a' )->reachable(), 'scheme of site_url' );
		$this->assertFalse( $probe->get( 'http://shop.test:8080/a' )->reachable() );
	}
}
