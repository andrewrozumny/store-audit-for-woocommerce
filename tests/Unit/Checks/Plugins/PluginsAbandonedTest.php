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
	 * Build the check with canned API answers keyed by slug.
	 *
	 * @param array<string,string> $answers Slug => JSON body.
	 * @param int                  $count   Number of installed plugins named p0..pN when $answers is empty.
	 * @param callable|null        $clock   Clock.
	 */
	private function check( array $answers, int $count = 0, ?callable $clock = null ): PluginsAbandoned {
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args ): string => 'https://api.wordpress.org/?slug=' . $args['slug']
		);
		$files = array();
		foreach ( array_keys( $answers ) as $slug ) {
			$files[ $slug . '/' . $slug . '.php' ] = array( 'Name' => strtoupper( $slug ) );
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
