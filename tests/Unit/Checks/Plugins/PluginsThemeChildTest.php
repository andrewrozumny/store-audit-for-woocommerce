<?php
/**
 * PluginsThemeChild tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Plugins;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Plugins\PluginsThemeChild;
use StoreAudit\Checks\Plugins\WporgApi;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for PluginsThemeChild.
 *
 * @covers \StoreAudit\Checks\Plugins\PluginsThemeChild
 */
final class PluginsThemeChildTest extends TestCase {

	/**
	 * Build the check.
	 *
	 * @param string            $stylesheet Active stylesheet slug.
	 * @param array<string,int> $files      File mtimes.
	 */
	private function check( string $stylesheet, array $files ): PluginsThemeChild {
		Functions\when( 'add_query_arg' )->justReturn( 'https://api.wordpress.org/themes/' );
		$api = new WporgApi(
			static fn(): array => array(
				'code' => 200,
				'body' => '{"version":"4.6.2"}',
			),
			static fn() => false,
			static function (): void {}
		);
		return new PluginsThemeChild(
			static fn(): array => array(
				'template'   => 'storefront',
				'stylesheet' => $stylesheet,
				'name'       => 'Storefront',
			),
			static fn(): array => $files,
			$api,
			static fn(): string => '/themes/storefront'
		);
	}

	/**
	 * No child theme, unmodified files: info.
	 */
	public function test_no_child_theme_is_info(): void {
		$result = $this->check(
			'storefront',
			array(
				'style.css'     => 1000,
				'functions.php' => 1000,
			)
		)->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'without a child theme', $result->summary() );
	}

	/**
	 * Child theme with untouched parent: ok.
	 */
	public function test_child_theme_is_ok(): void {
		$result = $this->check(
			'storefront-child',
			array(
				'style.css'     => 1000,
				'functions.php' => 1100,
			)
		)->run();

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Files newer than style.css by more than a day look edited.
	 */
	public function test_edited_files_warn(): void {
		$result = $this->check(
			'storefront',
			array(
				'style.css'     => 1000,
				'functions.php' => 1000 + 86400 * 3,
				'header.php'    => 1000 + 100,
			)
		)->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'functions.php' ), $result->details() );
		$this->assertStringContainsString( 'look edited', $result->summary() );
	}

	/**
	 * A folder inside the theme that cannot be read is skipped; the rest is still listed.
	 */
	public function test_unreadable_folder_is_skipped(): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- The test builds and removes a real temporary folder.
		$dir = sys_get_temp_dir() . '/sa-theme-' . uniqid( '', true );
		mkdir( $dir . '/readable', 0777, true );
		mkdir( $dir . '/locked', 0777, true );
		file_put_contents( $dir . '/style.css', '/* theme */' );
		file_put_contents( $dir . '/readable/page.php', '<?php' );
		file_put_contents( $dir . '/locked/secret.php', '<?php' );
		chmod( $dir . '/locked', 0000 );

		try {
			if ( is_readable( $dir . '/locked' ) ) {
				$this->markTestSkipped( 'Folder permissions are not enforced for this user.' );
			}
			Functions\when( 'add_query_arg' )->justReturn( 'https://api.wordpress.org/themes/' );
			$api   = new WporgApi(
				static fn(): array => array(
					'code' => 200,
					'body' => '{"version":"4.6.2"}',
				),
				static fn() => false,
				static function (): void {}
			);
			$check = new PluginsThemeChild(
				static fn(): array => array(
					'template'   => 'storefront',
					'stylesheet' => 'storefront',
					'name'       => 'Storefront',
				),
				null,
				$api,
				static fn(): string => $dir
			);

			$result = $check->run();

			$this->assertNotSame( 'error', $result->severity() );
		} finally {
			chmod( $dir . '/locked', 0777 );
			unlink( $dir . '/locked/secret.php' );
			rmdir( $dir . '/locked' );
			unlink( $dir . '/readable/page.php' );
			rmdir( $dir . '/readable' );
			unlink( $dir . '/style.css' );
			rmdir( $dir );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}
}
