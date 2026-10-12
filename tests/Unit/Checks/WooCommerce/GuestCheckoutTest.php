<?php
/**
 * GuestCheckout tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\GuestCheckout;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for GuestCheckout.
 *
 * @covers \StoreAudit\Checks\WooCommerce\GuestCheckout
 */
final class GuestCheckoutTest extends TestCase {

	/**
	 * Guest checkout allowed is a plain fact, not a finding.
	 */
	public function test_guest_allowed_is_ok(): void {
		$check = new GuestCheckout( static fn( string $name ) => 'woocommerce_enable_guest_checkout' === $name ? 'yes' : 'no' );

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Guest off and no registration at checkout.
	 */
	public function test_no_way_to_buy_warns(): void {
		$result = ( new GuestCheckout( static fn() => 'no' ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertCount( 3, $result->details() );
	}

	/**
	 * Guest off but checkout registration on.
	 */
	public function test_account_required_is_info(): void {
		$check = new GuestCheckout( static fn( string $name ) => 'woocommerce_enable_signup_and_login_from_checkout' === $name ? 'yes' : 'no' );

		$this->assertSame( 'info', $check->run()->severity() );
	}

	/**
	 * Metadata.
	 */
	public function test_metadata(): void {
		$check = new GuestCheckout( static fn() => 'yes' );

		$this->assertSame( 'woocommerce_guest_checkout', $check->id() );
		$this->assertSame( 'woocommerce', $check->section() );
	}
}
