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
	 * Options, run rows and every cached wordpress.org answer (with its timeout row) are removed.
	 */
	public function test_single_site_removes_options_and_transients(): void {
		$names = array(
			'store_audit_last_report',
			'store_audit_current_run',
			'store_audit_run_result_security_b',
			'_transient_store_audit_wporg_abc',
			'_transient_timeout_store_audit_wporg_abc',
		);
		$this->fake_db( $names );
		Functions\when( 'is_multisite' )->justReturn( false );

		Uninstall::run();

		sort( $names );
		$deleted = $this->deleted;
		sort( $deleted );
		$this->assertSame( $names, $deleted );
		foreach ( array_filter( $this->patterns, static fn( string $p ): bool => str_contains( $p, 'store' ) ) as $pattern ) {
			$this->assertStringStartsNotWith( '%', $pattern, 'patterns must be anchored at the start so other plugins are not touched' );
			$this->assertStringEndsWith( '%', $pattern );
		}
		$this->assertNotEmpty( $this->patterns );
	}

	/**
	 * Every site of a network is cleaned, and the current site is restored.
	 */
	public function test_multisite_cleans_every_site(): void {
		$this->fake_db( array( 'store_audit_last_report' ) );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'get_sites' )->once()->andReturn( array( 1, 2, 5 ) );
		Functions\expect( 'switch_to_blog' )->times( 3 );
		Functions\expect( 'restore_current_blog' )->times( 3 );

		Uninstall::run();

		$this->assertSame( array( 'store_audit_last_report', 'store_audit_last_report', 'store_audit_last_report' ), $this->deleted );
	}
}
