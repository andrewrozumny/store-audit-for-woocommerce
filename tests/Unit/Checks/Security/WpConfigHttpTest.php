<?php
/**
 * WpConfigHttp tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\WpConfigHttp;

/**
 * Tests for WpConfigHttp.
 *
 * @covers \StoreAudit\Checks\Security\WpConfigHttp
 */
final class WpConfigHttpTest extends ProbeTestCase {

	/**
	 * Source served as text is critical, and the body is never reported.
	 */
	public function test_served_source_is_critical_without_leaking(): void {
		$probe  = $this->probe( array( '/wp-config.php' => array( 200, "<?php\ndefine( 'DB_PASSWORD', 'hunter2' );" ) ) );
		$result = ( new WpConfigHttp( $probe ) )->run();

		$this->assertSame( 'critical', $result->severity() );
		$this->assertStringNotContainsString( 'hunter2', $result->summary() . implode( '', $result->details() ) );
	}

	/**
	 * An empty 200 (PHP executed) and 404 are fine.
	 */
	public function test_executed_or_missing_is_ok(): void {
		$this->assertSame( 'ok', ( new WpConfigHttp( $this->probe( array( '/wp-config.php' => array( 200, '' ) ) ) ) )->run()->severity() );
		$this->assertSame( 'ok', ( new WpConfigHttp( $this->probe( array() ) ) )->run()->severity() );
	}

	/**
	 * Without a loopback the check is not checked (an error row), never a pass.
	 */
	public function test_unreachable_is_not_checked(): void {
		$this->assertSame( 'error', ( new WpConfigHttp( $this->broken_probe() ) )->run()->severity() );
	}
}
