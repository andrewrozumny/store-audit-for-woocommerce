<?php
/**
 * ExposedDotfiles tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Security\ExposedDotfiles;

/**
 * Tests for ExposedDotfiles.
 *
 * @covers \StoreAudit\Checks\Security\ExposedDotfiles
 */
final class ExposedDotfilesTest extends ProbeTestCase {

	/**
	 * A served .git/HEAD and .env are critical and never echoed.
	 */
	public function test_served_files_are_critical(): void {
		$probe  = $this->probe(
			array(
				'/.git/HEAD' => array( 200, "ref: refs/heads/main\n" ),
				'/.env'      => array( 200, 'SECRET_KEY=abc123' ),
			)
		);
		$result = ( new ExposedDotfiles( $probe, '/var/www', static fn () => false ) )->run();

		$this->assertSame( 'critical', $result->severity() );
		$this->assertSame( array( '/.git/HEAD', '/.env' ), $result->details() );
		$this->assertStringNotContainsString( 'abc123', $result->summary() );
	}

	/**
	 * An HTML catch-all page for /.env is not a leak.
	 */
	public function test_html_catch_all_is_not_a_leak(): void {
		$probe = $this->probe( array( '/.env' => array( 200, '<!DOCTYPE html><html></html>' ) ) );

		$this->assertSame( 'ok', ( new ExposedDotfiles( $probe, '/var/www', static fn () => false ) )->run()->severity() );
	}

	/**
	 * Files on disk but not served are info.
	 */
	public function test_on_disk_but_hidden_is_info(): void {
		$result = ( new ExposedDotfiles( $this->probe( array() ), '/var/www', static fn ( string $path ) => str_ends_with( $path, '.env' ) ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertSame( array( '.env' ), $result->details() );
	}

	/**
	 * Without a loopback the check is not checked (an error row), never a pass.
	 */
	public function test_unreachable_is_not_checked(): void {
		$this->assertSame( 'error', ( new ExposedDotfiles( $this->broken_probe(), '/var/www', static fn () => false ) )->run()->severity() );
	}

	/**
	 * A catch-all page that answers 200 to everything is not a leak, whatever it says.
	 */
	public function test_soft_404_pages_are_not_a_leak(): void {
		$page  = '<html><body>Welcome. ref: this is just text</body></html>';
		$probe = $this->probe(
			array(
				'/.git/HEAD'   => array( 200, $page ),
				'/.git/config' => array( 200, 'Page not found [core]' ),
				'/.env'        => array( 200, "Sorry, page not found\nTry again" ),
			)
		);

		$this->assertSame( 'ok', ( new ExposedDotfiles( $probe, '/var/www', static fn () => false ) )->run()->severity() );
	}

	/**
	 * A detached HEAD holds a 40 character commit id instead of "ref: ".
	 */
	public function test_detached_head_is_a_leak(): void {
		$probe = $this->probe( array( '/.git/HEAD' => array( 200, str_repeat( 'a1', 20 ) . "\n" ) ) );

		$this->assertSame( 'critical', ( new ExposedDotfiles( $probe, '/var/www', static fn () => false ) )->run()->severity() );
	}

	/**
	 * A .env body is a leak only when it looks like KEY=value lines, wherever they start in the first 2 KB.
	 */
	public function test_env_must_look_like_dotenv(): void {
		$real = $this->probe( array( '/.env' => array( 200, "# secrets\nDB_PASSWORD = hunter2\n" ) ) );
		$this->assertSame( 'critical', ( new ExposedDotfiles( $real, '/var/www', static fn () => false ) )->run()->severity() );

		$fake = $this->probe( array( '/.env' => array( 200, 'lowercase=not env, plain text without html' ) ) );
		$this->assertSame( 'ok', ( new ExposedDotfiles( $fake, '/var/www', static fn () => false ) )->run()->severity() );
	}

	/**
	 * The probes go to the home address, where the public site lives, not to the WordPress address.
	 */
	public function test_probes_use_the_home_address(): void {
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test/shop' . $path );
		Functions\when( 'site_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test/wp' . $path );
		$probe = $this->probe( array( '/shop/.env' => array( 200, 'APP_KEY=base64:abc' ) ) );

		$result = ( new ExposedDotfiles( $probe, '/var/www', static fn () => false ) )->run();

		$this->assertSame( 'critical', $result->severity() );
		$this->assertSame( 'https://shop.test/shop/.git/HEAD', $this->requests[0]['url'] );
	}

	/**
	 * On disk, the site root (where the home page is served from) is looked at, and ABSPATH too when it differs.
	 */
	public function test_disk_check_covers_home_path_and_abspath(): void {
		Functions\when( 'get_home_path' )->justReturn( '/var/www/public/' );
		$seen  = array();
		$check = new ExposedDotfiles(
			$this->probe( array() ),
			null,
			static function ( string $path ) use ( &$seen ): bool {
				$seen[] = $path;
				return '/var/www/public/.env' === $path;
			}
		);

		$result = $check->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertContains( '/var/www/public/.git', $seen );
		$this->assertContains( ABSPATH . '.git', $seen );
		$this->assertSame( array( '.env' ), $result->details() );
	}
}
