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
		$this->remove( $this->dir );
		parent::tear_down();
	}

	/**
	 * Delete a folder and everything in it.
	 *
	 * @param string $path Path.
	 */
	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		chmod( $path, 0755 );
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

	/**
	 * Create a file with its folders.
	 *
	 * @param string $relative Path under the temp root.
	 * @param string $body     Content.
	 */
	private function put( string $relative, string $body = 'x' ): void {
		$path = $this->dir . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $body );
	}

	/**
	 * The check on the temp tree, with WordPress content and uploads folders in the usual places.
	 *
	 * @param callable|null $attached Attachment lookup override.
	 * @param int|null      $limit    Entry budget override.
	 * @param callable|null $clock    Clock override.
	 */
	private function check( ?callable $attached = null, ?int $limit = null, ?callable $clock = null ): BackupsInWebroot {
		return new BackupsInWebroot( $this->dir, $this->dir . '/uploads', $this->dir . '/wp-content', $attached ?? static fn(): array => array(), $limit, $clock );
	}

	/**
	 * A clean tree is ok.
	 */
	public function test_clean_tree_is_ok(): void {
		file_put_contents( $this->dir . '/index.php', '<?php' );
		file_put_contents( $this->dir . '/uploads/2024/photo.jpg', 'x' );

		$this->assertSame( 'ok', $this->check()->run()->severity() );
	}

	/**
	 * Archives are a warning with names and sizes.
	 */
	public function test_archives_warn(): void {
		file_put_contents( $this->dir . '/site.zip', '12345' );
		file_put_contents( $this->dir . '/uploads/2024/old.bak', '12' );
		$result = $this->check()->run();

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

		$this->assertSame( 'critical', $this->check()->run()->severity() );
	}

	/**
	 * A folder that cannot be read is reported as such. It is neither a PHP warning nor a false "all clear".
	 */
	public function test_unreadable_folder_is_not_a_clean_bill(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'The root user can read any folder.' );
		}
		chmod( $this->dir . '/uploads', 0000 );
		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Catches the warning this test is about.
			static function ( int $no, string $message ) use ( &$warnings ): bool {
				$warnings[] = $no . ': ' . $message;
				return true;
			}
		);
		try {
			$result = $this->check()->run();
		} finally {
			restore_error_handler();
			chmod( $this->dir . '/uploads', 0755 );
		}

		$this->assertSame( array(), $warnings );
		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'uploads', implode( ' ', $result->details() ) );
	}

	/**
	 * Archives in wp-content and in the folders backup plugins use are found.
	 */
	public function test_wp_content_and_backup_plugin_folders_are_scanned(): void {
		$this->put( 'wp-content/old-site.7z' );
		$this->put( 'wp-content/ai1wm-backups/shop.wpress' );
		$this->put( 'wp-content/updraft/backup_2024-db.gz' );
		$this->put( 'wp-content/uploads/backwpup-abc123-backups/full.tgz' );
		$this->put( 'wp-content/uploads/wp-staging/clone.zip' );
		$this->put( 'wp-content/themes/twenty/readme.zip' );

		$result = $this->check()->run();

		$this->assertSame( 'warning', $result->severity() );
		$details = implode( "\n", $result->details() );
		foreach ( array( 'wp-content/old-site.7z', 'wp-content/ai1wm-backups/shop.wpress', 'wp-content/updraft/backup_2024-db.gz' ) as $expected ) {
			$this->assertStringContainsString( $expected, $details );
		}
		$this->assertStringNotContainsString( 'themes/twenty', $details );
	}

	/**
	 * A folder that is both a known backup folder and inside uploads is listed once.
	 */
	public function test_a_file_is_listed_once(): void {
		$this->put( 'uploads/backwpup-abc123-backups/full.tgz' );

		$this->assertCount( 1, $this->check()->run()->details() );
	}

	/**
	 * Protected downloadable products are no backups.
	 */
	public function test_woocommerce_uploads_are_skipped(): void {
		$this->put( 'uploads/woocommerce_uploads/2024/ebook.zip' );
		$this->put( 'uploads/woocommerce_uploads/dump.sql' );

		$this->assertSame( 'ok', $this->check()->run()->severity() );
	}

	/**
	 * A zip that belongs to a media library item is a normal upload; one that does not is a finding. The lookup is
	 * one call for all candidates.
	 */
	public function test_media_library_zips_are_skipped_with_one_lookup(): void {
		$this->put( 'uploads/2024/05/theme-pack.zip' );
		$this->put( 'uploads/2024/05/site-backup.zip' );
		$this->put( 'uploads/2024/06/other.zip' );
		$calls  = 0;
		$lookup = static function ( array $paths ) use ( &$calls ): array {
			++$calls;
			return array_values( array_intersect( $paths, array( '2024/05/theme-pack.zip', '2024/06/other.zip' ) ) );
		};

		$result = $this->check( $lookup )->run();

		$this->assertSame( 1, $calls );
		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'uploads/2024/05/site-backup.zip (1 B)' ), $result->details() );
	}

	/**
	 * Dumps and archives of every listed type are recognised.
	 *
	 * @dataProvider extensions
	 *
	 * @param string $file     File name.
	 * @param string $severity Expected severity.
	 */
	public function test_extensions( string $file, string $severity ): void {
		$this->put( $file );

		$this->assertSame( $severity, $this->check()->run()->severity() );
	}

	/**
	 * Names and what they should be.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function extensions(): array {
		return array(
			'sql'     => array( 'a.sql', 'critical' ),
			'sql.gz'  => array( 'a.sql.gz', 'critical' ),
			'sql.zip' => array( 'a.sql.zip', 'critical' ),
			'gz'      => array( 'a.gz', 'warning' ),
			'tgz'     => array( 'a.tgz', 'warning' ),
			'tar'     => array( 'a.tar', 'warning' ),
			'7z'      => array( 'a.7z', 'warning' ),
			'rar'     => array( 'a.rar', 'warning' ),
			'wpress'  => array( 'a.wpress', 'warning' ),
			'daf'     => array( 'a.daf', 'warning' ),
			'bak'     => array( 'a.bak', 'warning' ),
			'txt'     => array( 'a.txt', 'ok' ),
			'jpg'     => array( 'a.jpg', 'ok' ),
		);
	}

	/**
	 * Uploads are walked within an entry budget and the result says it was partial.
	 */
	public function test_entry_budget_is_reported_as_partial(): void {
		for ( $i = 0; $i < 30; $i++ ) {
			$this->put( 'uploads/2024/01/img-' . $i . '.jpg' );
		}

		$result = $this->check( null, 10 )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'artially scanned', implode( ' ', $result->details() ) );
	}

	/**
	 * A spent time budget stops the walk too, and a finding found before that is still reported.
	 */
	public function test_time_budget_is_reported_as_partial(): void {
		$this->put( 'site.zip' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->put( 'uploads/2024/01/img-' . $i . '.jpg' );
		}
		$ticks = array( 0.0, 0.0, 0.0, 0.0, 9.0 );
		$clock = static function () use ( &$ticks ): float {
			return count( $ticks ) > 1 ? (float) array_shift( $ticks ) : $ticks[0];
		};

		$result = $this->check( null, null, $clock )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertContains( 'site.zip (1 B)', $result->details() );
		$this->assertStringContainsString( 'artially scanned', implode( ' ', $result->details() ) );
	}
}
