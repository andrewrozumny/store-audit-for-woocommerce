<?php
/**
 * BackupsInWebroot tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The test builds a throw-away tree in the system temp dir.

use Brain\Monkey\Functions;
use StoreAudit\Checks\Security\BackupsInWebroot;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for BackupsInWebroot.
 *
 * @covers \StoreAudit\Checks\Security\BackupsInWebroot
 */
final class BackupsInWebrootTest extends TestCase {

	/**
	 * Temp directory.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Create a temp tree.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'trailingslashit' )->alias( static fn ( string $value ): string => rtrim( $value, '/\\' ) . '/' );
		Functions\when( 'size_format' )->alias( static fn ( $bytes ): string => $bytes . ' B' );
		$this->dir = sys_get_temp_dir() . '/sa-backups-' . uniqid( '', true );
		mkdir( $this->dir . '/uploads/2024', 0777, true );
	}

	/**
	 * Remove the temp tree.
	 */
	protected function tear_down(): void {
		foreach ( array( '/index.php', '/site.zip', '/dump.sql', '/uploads/b.tar.gz', '/uploads/2024/old.bak', '/uploads/2024/photo.jpg' ) as $file ) {
			if ( is_file( $this->dir . $file ) ) {
				unlink( $this->dir . $file );
			}
		}
		rmdir( $this->dir . '/uploads/2024' );
		rmdir( $this->dir . '/uploads' );
		rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * A clean tree is ok.
	 */
	public function test_clean_tree_is_ok(): void {
		file_put_contents( $this->dir . '/index.php', '<?php' );
		file_put_contents( $this->dir . '/uploads/2024/photo.jpg', 'x' );

		$this->assertSame( 'ok', ( new BackupsInWebroot( $this->dir, $this->dir . '/uploads' ) )->run()->severity() );
	}

	/**
	 * Archives are a warning with names and sizes.
	 */
	public function test_archives_warn(): void {
		file_put_contents( $this->dir . '/site.zip', '12345' );
		file_put_contents( $this->dir . '/uploads/2024/old.bak', '12' );
		$result = ( new BackupsInWebroot( $this->dir, $this->dir . '/uploads' ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertContains( 'site.zip (5 B)', $result->details() );
		$this->assertContains( 'uploads/2024/old.bak (2 B)', $result->details() );
	}

	/**
	 * A database dump is critical.
	 */
	public function test_sql_dump_is_critical(): void {
		file_put_contents( $this->dir . '/dump.sql', 'DROP TABLE x;' );
		file_put_contents( $this->dir . '/uploads/b.tar.gz', 'x' );

		$this->assertSame( 'critical', ( new BackupsInWebroot( $this->dir, $this->dir . '/uploads' ) )->run()->severity() );
	}
}
