<?php
/**
 * PluginsInactive tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Plugins;

use StoreAudit\Checks\Plugins\PluginInventory;
use Brain\Monkey\Functions;
use StoreAudit\Checks\Plugins\PluginsInactive;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for PluginsInactive.
 *
 * @covers \StoreAudit\Checks\Plugins\PluginsInactive
 */
final class PluginsInactiveTest extends TestCase {

	/**
	 * A single site unless a test says otherwise.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	/**
	 * Build an inventory.
	 *
	 * @param string[] $active Active files.
	 */
	private function inventory( array $active ): PluginInventory {
		return new PluginInventory(
			static fn(): array => array(
				'a/a.php'   => array( 'Name' => 'Alpha' ),
				'b/b.php'   => array( 'Name' => 'Beta' ),
				'hello.php' => array( 'Name' => 'Hello Dolly' ),
			),
			static fn(): array => $active
		);
	}

	/**
	 * All active is ok.
	 */
	public function test_all_active_is_ok(): void {
		$result = ( new PluginsInactive( $this->inventory( array( 'a/a.php', 'b/b.php', 'hello.php' ) ) ) )->run();

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Inactive plugins are a warning with names.
	 */
	public function test_inactive_plugins_warn_with_names(): void {
		$result = ( new PluginsInactive( $this->inventory( array( 'a/a.php' ) ) ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'Beta', 'Hello Dolly' ), $result->details() );
		$this->assertStringContainsString( '2 plugins', $result->summary() );
	}

	/**
	 * On a single site the advice is to delete.
	 */
	public function test_single_site_advice_is_to_delete(): void {
		$check = new PluginsInactive( $this->inventory( array( 'a/a.php' ) ), static fn() => null );

		$this->assertSame( '', $check->run()->fix_hint() );
	}

	/**
	 * A plugin that another site of the network uses is not inactive; the advice is to ask the network admin.
	 */
	public function test_network_counts_plugins_active_on_any_site(): void {
		$network = static fn(): array => array(
			'too_large' => false,
			'active'    => array( 'b/b.php' ),
		);
		$result  = ( new PluginsInactive( $this->inventory( array( 'a/a.php' ) ), $network ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'Hello Dolly' ), $result->details() );
		$this->assertStringContainsString( 'network admin', $result->fix_hint() );
		$this->assertStringNotContainsString( 'Delete the plugins', $result->fix_hint() );
	}

	/**
	 * Everything used somewhere in the network is ok.
	 */
	public function test_network_all_used_is_ok(): void {
		$network = static fn(): array => array(
			'too_large' => false,
			'active'    => array( 'b/b.php', 'hello.php' ),
		);

		$this->assertSame( 'ok', ( new PluginsInactive( $this->inventory( array( 'a/a.php' ) ), $network ) )->run()->severity() );
	}

	/**
	 * A very large network is not walked: info, only this site is looked at, and no advice to delete.
	 */
	public function test_large_network_is_info_without_delete_advice(): void {
		$network = static fn(): array => array(
			'too_large' => true,
			'active'    => array(),
		);
		$result  = ( new PluginsInactive( $this->inventory( array( 'a/a.php' ) ), $network ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'on a large network this check only looks at this site', strtolower( $result->summary() ) );
		$this->assertStringNotContainsString( 'Delete', $result->fix_hint() );
		$this->assertNotSame( '', $result->fix_hint() );
	}

	/**
	 * The live reader walks the sites in batches of 100 and reads each site's active plugins.
	 */
	public function test_live_network_reader_walks_sites_in_batches(): void {
		$calls = array();
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->alias(
			static function ( array $args ) use ( &$calls ) {
				$calls[] = $args;
				if ( ! empty( $args['count'] ) ) {
					return 130;
				}
				return 0 === $args['offset'] ? range( 1, 100 ) : range( 101, 130 );
			}
		);
		Functions\when( 'get_blog_option' )->alias(
			static fn ( int $id ) => 130 === $id ? array( 'b/b.php' ) : array( 'a/a.php' )
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_site_option' )->justReturn( array() );

		$result = ( new PluginsInactive( $this->inventory( array() ) ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'Hello Dolly' ), $result->details() );
		$this->assertSame( 100, $calls[1]['number'] );
		$this->assertSame( 100, $calls[2]['offset'] );
	}

	/**
	 * The live reader gives up above 500 sites.
	 */
	public function test_live_network_reader_does_not_walk_a_huge_network(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( 501 );

		$result = ( new PluginsInactive( $this->inventory( array( 'a/a.php' ) ) ) )->run();

		$this->assertSame( 'info', $result->severity() );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new PluginsInactive( $this->inventory( array() ) );

		$this->assertSame( 'plugins_inactive', $check->id() );
		$this->assertSame( 'plugins', $check->section() );
	}
}
