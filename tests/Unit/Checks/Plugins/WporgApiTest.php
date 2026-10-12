<?php
/**
 * WporgApi tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Plugins;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Plugins\WporgApi;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for WporgApi.
 *
 * @covers \StoreAudit\Checks\Plugins\WporgApi
 */
final class WporgApiTest extends TestCase {

	/**
	 * In-memory cache.
	 *
	 * @var array<string,mixed>
	 */
	private array $cache = array();

	/**
	 * Build a client with a canned response.
	 *
	 * @param array{code:int,body:string}|null $response Response.
	 */
	private function api( ?array $response ): WporgApi {
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		$this->cache = array();
		return new WporgApi(
			static fn(): ?array => $response,
			fn( string $key ) => $this->cache[ $key ] ?? false,
			function ( string $key, array $value ): void {
				$this->cache[ $key ] = $value;
			}
		);
	}

	/**
	 * Slugs come from the plugin folder or file name.
	 */
	public function test_slug_from_file(): void {
		$this->assertSame( 'akismet', WporgApi::slug_from_file( 'akismet/akismet.php' ) );
		$this->assertSame( 'hello', WporgApi::slug_from_file( 'hello.php' ) );
	}

	/**
	 * Found plugin.
	 */
	public function test_found(): void {
		$api  = $this->api(
			array(
				'code' => 200,
				'body' => '{"name":"Akismet","version":"5.7.2","last_updated":"2026-08-18 11:42pm GMT"}',
			)
		);
		$info = $api->plugin( 'akismet' );

		$this->assertSame( 'found', $info['status'] );
		$this->assertSame( '5.7.2', $info['version'] );
		$this->assertSame( strtotime( '2026-08-18 11:42pm GMT' ), $info['last_updated'] );
	}

	/**
	 * Closed plugin (real shape of the wordpress.org answer).
	 */
	public function test_closed(): void {
		$api  = $this->api(
			array(
				'code' => 404,
				'body' => '{"error":"closed","name":"X","closed":true,"closed_date":"2024-02-23","reason":"author-request","reason_text":"Author Request"}',
			)
		);
		$info = $api->plugin( 'wp-gdpr-compliance' );

		$this->assertSame( 'closed', $info['status'] );
		$this->assertSame( 'Author Request', $info['reason'] );
	}

	/**
	 * Unknown plugin.
	 */
	public function test_not_found(): void {
		$api = $this->api(
			array(
				'code' => 404,
				'body' => '{"error":"Plugin not found."}',
			)
		);

		$this->assertSame( 'not_found', $api->plugin( 'nope' )['status'] );
	}

	/**
	 * Network failure is unavailable and is not cached.
	 */
	public function test_failure_is_unavailable_and_not_cached(): void {
		$api = $this->api( null );

		$this->assertSame( 'unavailable', $api->plugin( 'x' )['status'] );
		$this->assertSame( array(), $this->cache );
	}

	/**
	 * A second lookup is served from cache, including "not found".
	 */
	public function test_results_are_cached(): void {
		$api = $this->api(
			array(
				'code' => 404,
				'body' => '{"error":"Plugin not found."}',
			)
		);
		$api->plugin( 'nope' );
		$api->plugin( 'nope' );

		$this->assertSame( 1, $api->request_count() );
		$this->assertArrayHasKey( 'store_audit_wporg_' . md5( 'plugin:nope' ), $this->cache );
	}

	/**
	 * Theme lookup.
	 */
	public function test_theme_found(): void {
		$api = $this->api(
			array(
				'code' => 200,
				'body' => '{"name":"Storefront","version":"4.6.2"}',
			)
		);

		$this->assertSame( 'found', $api->theme( 'storefront' )['status'] );
	}
}
