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
}
