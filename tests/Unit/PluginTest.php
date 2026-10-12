<?php
/**
 * Plugin bootstrap tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use StoreAudit\Plugin;

/**
 * Tests for Plugin::boot().
 *
 * @covers \StoreAudit\Plugin
 */
final class PluginTest extends TestCase {

	/**
	 * A fresh instance (the real one is a singleton shared by the process).
	 */
	private function fresh(): Plugin {
		$instance = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$this->assertInstanceOf( Plugin::class, $instance );
		return $instance;
	}

	/**
	 * A network-activated WooCommerce is found even before its class exists.
	 */
	public function test_network_activated_woocommerce_boots_the_plugin(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( array( 'woocommerce/woocommerce.php' => 1760000000 ) );
		Actions\expectAdded( 'admin_menu' )->once();
		Actions\expectAdded( 'admin_notices' )->never();

		$this->fresh()->boot();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Without WooCommerce anywhere only the notice is registered.
	 */
	public function test_missing_woocommerce_only_shows_the_notice(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( array( 'other/other.php' => 1760000000 ) );
		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'admin_menu' )->never();

		$this->fresh()->boot();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * A per-site activation on a single site works as before.
	 */
	public function test_single_site_activation(): void {
		Functions\when( 'get_option' )->justReturn( array( 'woocommerce/woocommerce.php' ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Actions\expectAdded( 'admin_menu' )->once();

		$this->fresh()->boot();
		$this->addToAssertionCount( 1 );
	}
}
