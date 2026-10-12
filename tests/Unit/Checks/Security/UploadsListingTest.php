<?php
/**
 * UploadsListing tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\UploadsListing;

/**
 * Tests for UploadsListing.
 *
 * @covers \StoreAudit\Checks\Security\UploadsListing
 */
final class UploadsListingTest extends ProbeTestCase {

	/**
	 * A directory index is a warning.
	 */
	public function test_listing_is_warning(): void {
		$probe = $this->probe( array( '/wp-content/uploads/' => array( 200, '<title>Index of /wp-content/uploads</title>' ) ) );

		$this->assertSame( 'warning', ( new UploadsListing( $probe ) )->run()->severity() );
	}

	/**
	 * Forbidden or blank pages are fine.
	 */
	public function test_no_listing_is_ok(): void {
		$this->assertSame( 'ok', ( new UploadsListing( $this->probe( array( '/wp-content/uploads/' => array( 403, '' ) ) ) ) )->run()->severity() );
		$this->assertSame( 'ok', ( new UploadsListing( $this->probe( array( '/wp-content/uploads/' => array( 200, '' ) ) ) ) )->run()->severity() );
	}

	/**
	 * Without a loopback the check is informational.
	 */
	public function test_unreachable_is_info(): void {
		$this->assertSame( 'info', ( new UploadsListing( $this->broken_probe() ) )->run()->severity() );
	}
}
