<?php
/**
 * Uninstall tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Functions;
use StoreAudit\Uninstall;

/**
 * Tests for Uninstall.
 *
 * @covers \StoreAudit\Uninstall
 */
final class UninstallTest extends TestCase {

	/**
	 * Calls recorded by the stubs.
	 *
	 * @var list<string>
	 */
	private array $deleted = array();

	/**
	 * Patterns the queries were built with.
	 *
	 * @var list<string>
	 */
	private array $patterns = array();

	/**
	 * Install a fake wpdb that "finds" a fixed list of rows.
	 *
	 * @param array<int,string> $names Option names the query returns.
	 */
	private function fake_db( array $names ): void {
		$db          = \Mockery::mock( 'wpdb' );
		$db->options = 'wp_options'; // phpcs:ignore WordPress.DB.RestrictedClasses -- Test double.
		$db->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $text ): string => addcslashes( $text, '_%\\' ) );
		$db->shouldReceive( 'prepare' )->andReturnUsing(
			function ( string $query, ...$args ): string {
				foreach ( $args as $arg ) {
					if ( is_string( $arg ) ) {
						$this->patterns[] = $arg;
					}
				}
				return $query;
			}
		);
		$db->shouldReceive( 'get_col' )->andReturn( $names );
		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				$this->deleted[] = $name;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->justReturn( true );
	}

	/**
	 * Remove the fake database.
	 */
	protected function tear_down(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	/**
	 * The two options are removed by exact name; the run rows and cached wordpress.org answers by prefix.
	 */
	public function test_single_site_removes_options_and_transients(): void {
		$found = array(
			'store_audit_run_result_security_b',
			'_transient_store_audit_wporg_abc',
			'_transient_timeout_store_audit_wporg_abc',
		);
		$this->fake_db( $found );
		Functions\when( 'is_multisite' )->justReturn( false );

		Uninstall::run();

		$expected = array_merge( array( 'store_audit_last_report', 'store_audit_current_run' ), $found );
		sort( $expected );
		$deleted = $this->deleted;
		sort( $deleted );
		$this->assertSame( $expected, $deleted );
		$this->assertContains( 'store_audit_run_result_', $this->patterns_without_wildcard() );
		$this->assertContains( '_transient_store_audit_wporg_', $this->patterns_without_wildcard() );
		$this->assertContains( '_transient_timeout_store_audit_wporg_', $this->patterns_without_wildcard() );
		foreach ( array_filter( $this->patterns, static fn( string $p ): bool => str_contains( $p, 'store' ) ) as $pattern ) {
			$this->assertStringStartsNotWith( '%', $pattern, 'patterns must be anchored at the start so other plugins are not touched' );
			$this->assertStringEndsWith( '%', $pattern );
			$this->assertNotSame( 'store\_audit\_%', $pattern, 'the broad store_audit_ prefix must not be used' );
		}
	}

	/**
	 * Patterns the query was built with, without the trailing wildcard and without the escaping of underscores.
	 *
	 * @return list<string>
	 */
	private function patterns_without_wildcard(): array {
		return array_map( static fn( string $pattern ): string => str_replace( '\\_', '_', rtrim( $pattern, '%' ) ), array_values( array_filter( $this->patterns, static fn( string $p ): bool => str_ends_with( $p, '%' ) ) ) );
	}

	/**
	 * Every site of a network is cleaned in batches of 100, and the current site is restored each time.
	 */
	public function test_multisite_cleans_every_site_in_batches(): void {
		$this->fake_db( array() );
		Functions\when( 'is_multisite' )->justReturn( true );
		$calls = array();
		Functions\when( 'get_sites' )->alias(
			static function ( array $args ) use ( &$calls ): array {
				$calls[] = $args;
				return 0 === $args['offset'] ? range( 1, 100 ) : array( 101, 102, 103 );
			}
		);
		Functions\expect( 'switch_to_blog' )->times( 103 );
		Functions\expect( 'restore_current_blog' )->times( 103 );

		Uninstall::run();

		$this->assertCount( 2, $calls );
		$this->assertSame( 100, $calls[0]['number'] );
		$this->assertSame( 0, $calls[0]['offset'] );
		$this->assertSame( 100, $calls[1]['offset'] );
		$this->assertCount( 206, $this->deleted, 'two options per site' );
	}

	/**
	 * A network of fewer than 100 sites needs a single query.
	 */
	public function test_small_network_is_one_query(): void {
		$this->fake_db( array() );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'get_sites' )->once()->andReturn( array( 1, 2, 5 ) );
		Functions\expect( 'switch_to_blog' )->times( 3 );
		Functions\expect( 'restore_current_blog' )->times( 3 );

		Uninstall::run();

		$this->assertCount( 6, $this->deleted );
	}
}
