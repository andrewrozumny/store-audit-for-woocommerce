<?php
/**
 * ActivePlugins tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Support;

use Brain\Monkey\Functions;
use StoreAudit\Support\ActivePlugins;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for ActivePlugins.
 *
 * @covers \StoreAudit\Support\ActivePlugins
 */
final class ActivePluginsTest extends TestCase {

	/**
	 * Folder and file names become slugs, duplicates are dropped.
	 */
	public function test_slugs_of_basenames(): void {
		$this->assertSame(
			array( 'akismet', 'hello', 'jetpack' ),
			ActivePlugins::slugs_of( array( 'akismet/akismet.php', 'hello.php', 'jetpack/jetpack.php', 'jetpack/other.php' ) )
		);
	}

	/**
	 * Network-wide plugins count on a multisite.
	 */
	public function test_network_plugins_are_included(): void {
		Functions\when( 'get_option' )->justReturn( array( 'a/a.php' ) );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( array( 'net/net.php' => 123 ) );

		$this->assertSame( array( 'a', 'net' ), ActivePlugins::slugs() );
	}

	/**
	 * One list of every active plugin file, site and network, each once.
	 */
	public function test_files_merges_site_and_network_without_repeats(): void {
		Functions\when( 'get_option' )->justReturn( array( 'a/a.php', 'b/b.php' ) );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn(
			array(
				'b/b.php'     => 1,
				'net/net.php' => 2,
			)
		);

		$this->assertSame( array( 'a/a.php', 'b/b.php', 'net/net.php' ), ActivePlugins::files() );
		$this->assertTrue( ActivePlugins::is_active( 'net/net.php' ) );
		$this->assertFalse( ActivePlugins::is_active( 'other/other.php' ) );
	}

	/**
	 * A single site has no network list.
	 */
	public function test_single_site_has_no_network_files(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'get_site_option' )->never();

		$this->assertSame( array(), ActivePlugins::network_files() );
	}
}
