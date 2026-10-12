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
		$this->assertFalse( $info['security'] );
	}

	/**
	 * Closed because of a security problem: the reason code is "security-issue".
	 */
	public function test_closed_for_security_is_flagged(): void {
		$api  = $this->api(
			array(
				'code' => 404,
				'body' => '{"error":"closed","name":"Risky Plugin","closed":true,"closed_date":"2024-02-23","reason":"security-issue","reason_text":"Security Issue"}',
			)
		);
		$info = $api->plugin( 'risky-plugin' );

		$this->assertSame( 'closed', $info['status'] );
		$this->assertTrue( $info['security'] );
		$this->assertSame( 'Risky Plugin', $info['name'] );
	}

	/**
	 * The name the API reports is returned so the caller can tell a different plugin that took the slug.
	 */
	public function test_found_returns_the_name(): void {
		$api = $this->api(
			array(
				'code' => 200,
				'body' => '{"name":"Akismet Anti-spam: Spam Protection","version":"5.7.2"}',
			)
		);

		$this->assertSame( 'Akismet Anti-spam: Spam Protection', $api->plugin( 'akismet' )['name'] );
	}

	/**
	 * Entries cached before the name existed still come back with the full shape.
	 */
	public function test_old_cache_entries_get_defaults(): void {
		$api = $this->api( null );
		$this->cache[ 'store_audit_wporg_' . md5( 'plugin:old' ) ] = array(
			'status'       => 'found',
			'version'      => '1',
			'last_updated' => 5,
			'reason'       => '',
		);

		$info = $api->plugin( 'old' );

		$this->assertSame( '', $info['name'] );
		$this->assertFalse( $info['security'] );
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

	/**
	 * The reason text comes from a remote server. It is cut to a short single line without tags before it is
	 * cached and shown, so one odd answer cannot bloat the report or the options table.
	 */
	public function test_closed_reason_is_short_and_plain(): void {
		$long = '<script>alert(1)</script>' . str_repeat( "long reason\n", 20000 );
		$api  = $this->api(
			array(
				'code' => 404,
				'body' => (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test fixture.
					array(
						'error'       => 'closed',
						'closed'      => true,
						'reason_text' => $long,
					)
				),
			)
		);

		$info = $api->plugin( 'some-plugin' );

		$this->assertSame( 'closed', $info['status'] );
		$this->assertLessThanOrEqual( 303, strlen( $info['reason'] ) );
		$this->assertStringNotContainsString( '<', $info['reason'] );
		$this->assertStringNotContainsString( "\n", $info['reason'] );
		$this->assertStringContainsString( 'long reason', $info['reason'] );
		$cached = array_values( $this->cache );
		$this->assertLessThan( 1000, strlen( serialize( $cached ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Size check only.
	}
}
