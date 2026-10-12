<?php
/**
 * PluginsAbandoned tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Plugins;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Plugins\PluginInventory;
use StoreAudit\Checks\Plugins\PluginsAbandoned;
use StoreAudit\Checks\Plugins\WporgApi;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for PluginsAbandoned.
 *
 * @covers \StoreAudit\Checks\Plugins\PluginsAbandoned
 */
final class PluginsAbandonedTest extends TestCase {

	private const NOW = 1790000000; // Late Sept 2026.

	/**
	 * Host parsing.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	/**
	 * Build the check with canned API answers keyed by slug.
	 *
	 * @param array<string,string>                   $answers Slug => JSON body.
	 * @param int                                    $count   Number of installed plugins named p0..pN when $answers is empty.
	 * @param callable|null                          $clock   Clock.
	 * @param array<string,array{0:string,1:string}> $headers Slug => header name and value, as get_plugins() returns them (for example UpdateURI).
	 */
	private function check( array $answers, int $count = 0, ?callable $clock = null, array $headers = array() ): PluginsAbandoned {
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args ): string => 'https://api.wordpress.org/?slug=' . $args['slug']
		);
		$files = array();
		foreach ( array_keys( $answers ) as $slug ) {
			$files[ $slug . '/' . $slug . '.php' ] = array_merge( array( 'Name' => strtoupper( $slug ) ), isset( $headers[ $slug ] ) ? array( $headers[ $slug ][0] => $headers[ $slug ][1] ) : array() );
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$files[ 'p' . $i . '/p' . $i . '.php' ] = array( 'Name' => 'P' . $i );
		}
		$api = new WporgApi(
			static function ( string $url ) use ( $answers ): ?array {
				$slug = substr( $url, strrpos( $url, '=' ) + 1 );
				if ( ! isset( $answers[ $slug ] ) ) {
					return array(
						'code' => 200,
						'body' => '{"version":"1.0","last_updated":"2026-09-01 10:00am GMT"}',
					);
				}
				return array(
					'code' => 200,
					'body' => $answers[ $slug ],
				);
			},
			static fn() => false,
			static function (): void {}
		);
		return new PluginsAbandoned( new PluginInventory( static fn(): array => $files, static fn(): array => array() ), $api, $clock, self::NOW );
	}

	/**
	 * Recently updated plugins are ok.
	 */
	public function test_recent_plugin_is_ok(): void {
		$result = $this->check( array( 'fresh' => '{"version":"2","last_updated":"2026-08-01 10:00am GMT"}' ) )->run();

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Old plugin warns with the date.
	 */
	public function test_old_plugin_warns(): void {
		$result = $this->check( array( 'stale' => '{"version":"2","last_updated":"2024-01-15 10:00am GMT"}' ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '15 Jan 2024', $result->details()[0] );
	}

	/**
	 * Closed plugin is critical.
	 */
	public function test_closed_plugin_is_critical(): void {
		$result = $this->check( array( 'gone' => '{"error":"closed","closed":true,"reason_text":"Security Issue"}' ) )->run();

		$this->assertSame( 'critical', $result->severity() );
		$this->assertStringContainsString( 'Security Issue', $result->details()[0] );
	}

	/**
	 * Closed for a reason other than security is a warning: the author may simply have stopped.
	 */
	public function test_closed_without_security_is_warning(): void {
		$result = $this->check( array( 'quit' => '{"error":"closed","closed":true,"name":"QUIT","reason":"author-request","reason_text":"Author Request"}' ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( 'Author Request', $result->details()[0] );
	}

	/**
	 * Closed with the reason code "security-issue" is critical even when the text is odd.
	 */
	public function test_closed_with_security_reason_code_is_critical(): void {
		$result = $this->check( array( 'bad' => '{"error":"closed","closed":true,"name":"BAD","reason":"security-issue","reason_text":"Closed"}' ) )->run();

		$this->assertSame( 'critical', $result->severity() );
	}

	/**
	 * A plugin that gets updates from its own server is not looked up on wordpress.org.
	 */
	public function test_update_uri_on_another_host_is_skipped(): void {
		$check  = $this->check(
			array( 'premium' => '{"error":"closed","closed":true,"name":"PREMIUM","reason":"security-issue","reason_text":"Security Issue"}' ),
			0,
			null,
			array( 'premium' => array( 'UpdateURI', 'https://updates.vendor.example/premium' ) )
		);
		$result = $check->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertContains( 'PREMIUM: not checked, updates come from updates.vendor.example', $result->details() );
	}

	/**
	 * An Update URI that points at wordpress.org still gets checked.
	 */
	public function test_update_uri_on_wordpress_org_is_checked(): void {
		$check = $this->check(
			array( 'stale' => '{"name":"STALE","version":"2","last_updated":"2024-01-15 10:00am GMT"}' ),
			0,
			null,
			array( 'stale' => array( 'UpdateURI', 'https://wordpress.org/plugins/stale/' ) )
		);

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * Another plugin that took the slug on wordpress.org says nothing about this one.
	 */
	public function test_different_name_on_wordpress_org_is_not_checked(): void {
		$result = $this->check( array( 'mine' => '{"name":"Totally Different Plugin","version":"2","last_updated":"2020-01-15 10:00am GMT"}' ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'not hosted on wordpress.org', $result->details()[0] );
	}

	/**
	 * A name that only differs in case or by extra words still matches.
	 */
	public function test_similar_name_still_matches(): void {
		$result = $this->check( array( 'akismet' => '{"name":"akismet anti-spam: spam protection","version":"2","last_updated":"2020-01-15 10:00am GMT"}' ) )->run();

		$this->assertSame( 'warning', $result->severity() );
	}

	/**
	 * Plugins not on wordpress.org are listed as not checked.
	 */
	public function test_unhosted_plugin_is_listed_as_not_checked(): void {
		$result = $this->check( array( 'premium' => '{"error":"Plugin not found."}' ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'not hosted on wordpress.org', $result->details()[0] );
	}

	/**
	 * More than 40 plugins: the rest is reported as not checked.
	 */
	public function test_plugin_limit_is_reported(): void {
		$result = $this->check( array(), 45 )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertContains( '5 plugins not checked (time limit)', $result->details() );
	}

	/**
	 * A spent time budget stops lookups.
	 */
	public function test_time_budget_stops_lookups(): void {
		$ticks  = array( 0.0, 0.0, 100.0, 100.0, 100.0 );
		$clock  = static function () use ( &$ticks ): float {
			return count( $ticks ) > 1 ? (float) array_shift( $ticks ) : $ticks[0];
		};
		$result = $this->check( array(), 3, $clock )->run();

		$this->assertContains( '2 plugins not checked (time limit)', $result->details() );
	}
}
