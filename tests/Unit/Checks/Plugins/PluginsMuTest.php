<?php
/**
 * PluginsMu tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Plugins;

use StoreAudit\Checks\Plugins\PluginsMu;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for PluginsMu.
 *
 * @covers \StoreAudit\Checks\Plugins\PluginsMu
 */
final class PluginsMuTest extends TestCase {

	private const NOW = 1790000000;

	/**
	 * None installed is ok.
	 */
	public function test_none_is_ok(): void {
		$this->assertSame( 'ok', ( new PluginsMu( static fn(): array => array(), self::NOW ) )->run()->severity() );
	}

	/**
	 * Recent files are listed as info.
	 */
	public function test_recent_is_info(): void {
		$check = new PluginsMu(
			static fn(): array => array(
				'loader.php' => array(
					'name'  => 'Loader',
					'mtime' => self::NOW - 86400,
				),
			),
			self::NOW
		);

		$result = $check->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertSame( array( 'Loader (loader.php)' ), $result->details() );
	}

	/**
	 * A file untouched for over two years warns.
	 */
	public function test_old_file_warns(): void {
		$check = new PluginsMu(
			static fn(): array => array(
				'old.php' => array(
					'name'  => 'Old',
					'mtime' => self::NOW - ( 3 * 365 * 86400 ),
				),
			),
			self::NOW
		);

		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( 'check if still needed', $result->details()[0] );
	}

	/**
	 * Mu-plugins that a host installs are not the owner's forgotten code, even when they are old.
	 */
	public function test_host_mu_plugins_are_not_warned_about(): void {
		$old   = self::NOW - ( 5 * 365 * 86400 );
		$check = new PluginsMu(
			static fn(): array => array(
				'kinsta-mu-plugins.php' => array(
					'name'  => 'Kinsta Must-Use',
					'mtime' => $old,
				),
				'mu-plugin.php'         => array(
					'name'  => 'WP Engine System',
					'mtime' => $old,
				),
				'gd-system-plugin.php'  => array(
					'name'  => 'GD System Plugin',
					'mtime' => $old,
				),
				'wpcomsh-loader.php'    => array(
					'name'  => 'WordPress.com Site Helper',
					'mtime' => $old,
				),
			),
			self::NOW
		);

		$result = $check->run();

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( 'host', $result->summary() );
		$this->assertCount( 4, $result->details() );
	}

	/**
	 * Only the owner's own mu-plugins are judged, and the host's are left out of the count.
	 */
	public function test_own_old_mu_plugin_still_warns_next_to_a_host_one(): void {
		$old   = self::NOW - ( 5 * 365 * 86400 );
		$check = new PluginsMu(
			static fn(): array => array(
				'kinsta-mu-plugins.php' => array(
					'name'  => 'Kinsta',
					'mtime' => $old,
				),
				'legacy.php'            => array(
					'name'  => 'Legacy',
					'mtime' => $old,
				),
			),
			self::NOW
		);

		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '1 of 1', $result->summary() );
		$this->assertStringContainsString( 'Legacy', implode( ' ', $result->details() ) );
		$this->assertStringNotContainsString( 'Kinsta', implode( ' ', $result->details() ) );
	}
}
