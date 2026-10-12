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
	 * Without a loopback the check is not checked (an error row), never a pass.
	 */
	public function test_unreachable_is_not_checked(): void {
		$this->assertSame( 'error', ( new UploadsListing( $this->broken_probe() ) )->run()->severity() );
	}

	/**
	 * Uploads served from a CDN host are not applicable, not a blocked loopback.
	 */
	public function test_uploads_on_another_host_are_not_applicable(): void {
		\Brain\Monkey\Functions\when( 'wp_upload_dir' )->justReturn( array( 'baseurl' => 'https://cdn.example.net/uploads' ) );

		$result = ( new UploadsListing( $this->probe( array() ) ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'Not applicable', $result->summary() );
		$this->assertStringContainsString( 'another host', $result->summary() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A site that cannot call itself says "not checked".
	 */
	public function test_loopback_failure_text(): void {
		$result = ( new UploadsListing( $this->broken_probe() ) )->run();

		$this->assertSame( 'error', $result->severity() );
		$this->assertStringContainsString( 'could not reach itself', $result->summary() );
	}
}
