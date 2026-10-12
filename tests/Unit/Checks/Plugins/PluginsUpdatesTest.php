<?php
/**
 * PluginsUpdates tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Plugins;

use StoreAudit\Checks\Plugins\PluginsUpdates;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for PluginsUpdates.
 *
 * @covers \StoreAudit\Checks\Plugins\PluginsUpdates
 */
final class PluginsUpdatesTest extends TestCase {

	/**
	 * Nothing pending is ok.
	 */
	public function test_no_updates_is_ok(): void {
		$check = new PluginsUpdates(
			static fn(): array => array(
				'plugins' => array(),
				'themes'  => array(),
			)
		);

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Pending plugin and theme updates warn with versions.
	 */
	public function test_pending_updates_warn(): void {
		$check = new PluginsUpdates(
			static fn(): array => array(
				'plugins' => array(
					'a/a.php' => array(
						'name'    => 'Alpha',
						'current' => '1.0',
						'new'     => '1.1',
					),
				),
				'themes'  => array(
					'storefront' => array(
						'name'    => 'Storefront',
						'current' => '4.5',
						'new'     => '4.6',
					),
				),
			)
		);

		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( 'Alpha: 1.0 -> 1.1', $result->details()[0] );
		$this->assertSame( 'Theme Storefront: 4.5 -> 4.6', $result->details()[1] );
	}
}
