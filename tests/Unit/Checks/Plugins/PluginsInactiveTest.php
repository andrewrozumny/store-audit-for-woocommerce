<?php
/**
 * PluginsInactive tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Plugins;

use StoreAudit\Checks\Plugins\PluginInventory;
use StoreAudit\Checks\Plugins\PluginsInactive;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for PluginsInactive.
 *
 * @covers \StoreAudit\Checks\Plugins\PluginsInactive
 */
final class PluginsInactiveTest extends TestCase {

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
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new PluginsInactive( $this->inventory( array() ) );

		$this->assertSame( 'plugins_inactive', $check->id() );
		$this->assertSame( 'plugins', $check->section() );
	}
}
