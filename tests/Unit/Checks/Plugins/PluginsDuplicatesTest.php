<?php
/**
 * PluginsDuplicates tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Plugins;

use StoreAudit\Checks\Plugins\PluginInventory;
use StoreAudit\Checks\Plugins\PluginsDuplicates;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for PluginsDuplicates.
 *
 * @covers \StoreAudit\Checks\Plugins\PluginsDuplicates
 */
final class PluginsDuplicatesTest extends TestCase {

	/**
	 * Build the check with a set of active plugin files.
	 *
	 * @param string[] $active Active plugin files.
	 */
	private function check( array $active ): PluginsDuplicates {
		$installed = array();
		foreach ( $active as $file ) {
			$installed[ $file ] = array( 'Name' => $file );
		}
		return new PluginsDuplicates(
			new PluginInventory( static fn(): array => $installed, static fn(): array => $active ),
			array(
				'SEO'     => array( 'wordpress-seo', 'seo-by-rank-math' ),
				'Caching' => array( 'wp-rocket', 'w3-total-cache' ),
			)
		);
	}

	/**
	 * One plugin per category is fine.
	 */
	public function test_one_per_category_is_ok(): void {
		$result = $this->check( array( 'wordpress-seo/wp-seo.php', 'wp-rocket/wp-rocket.php' ) )->run();

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Two active plugins of one category warn.
	 */
	public function test_two_seo_plugins_warn(): void {
		$result = $this->check( array( 'wordpress-seo/wp-seo.php', 'seo-by-rank-math/rank-math.php' ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringStartsWith( 'SEO: ', $result->details()[0] );
	}

	/**
	 * A scanner reports problems but is no firewall, so a scanner next to a firewall is not a duplicate.
	 */
	public function test_sucuri_scanner_next_to_a_firewall_is_not_a_duplicate(): void {
		$active    = array( 'wordfence/wordfence.php', 'sucuri-scanner/sucuri.php' );
		$installed = array(
			'wordfence/wordfence.php'   => array( 'Name' => 'Wordfence' ),
			'sucuri-scanner/sucuri.php' => array( 'Name' => 'Sucuri Scanner' ),
		);
		$check     = new PluginsDuplicates( new PluginInventory( static fn(): array => $installed, static fn(): array => $active ) );

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Two real firewalls still are.
	 */
	public function test_two_firewalls_are_still_a_duplicate(): void {
		$active    = array( 'wordfence/wordfence.php', 'wp-cerber/wp-cerber.php' );
		$installed = array(
			'wordfence/wordfence.php' => array( 'Name' => 'Wordfence' ),
			'wp-cerber/wp-cerber.php' => array( 'Name' => 'Cerber' ),
		);
		$check     = new PluginsDuplicates( new PluginInventory( static fn(): array => $installed, static fn(): array => $active ) );

		$this->assertSame( 'warning', $check->run()->severity() );
	}
}
