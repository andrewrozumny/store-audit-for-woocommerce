<?php
/**
 * Antifraud tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\Antifraud;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Antifraud.
 *
 * @covers \StoreAudit\Checks\Security\Antifraud
 */
final class AntifraudTest extends TestCase {

	/**
	 * Build the check with a list of active plugin files.
	 *
	 * @param string[] $active Active plugin files.
	 */
	private function check( array $active ): Antifraud {
		return new Antifraud( static fn () => $active );
	}

	/**
	 * Nothing at all is a warning.
	 */
	public function test_no_plugin_warns(): void {
		$this->assertSame( 'warning', $this->check( array( 'woocommerce/woocommerce.php', 'hello.php' ) )->run()->severity() );
	}

	/**
	 * A login limiter plus a payment gateway with its own protection is ok, and both facts are listed apart.
	 */
	public function test_login_and_checkout_protection_is_ok(): void {
		$result = $this->check( array( 'wordfence/wordfence.php', 'woocommerce-payments/woocommerce-payments.php' ) )->run();

		$this->assertSame( 'ok', $result->severity() );
		$this->assertSame(
			array(
				'Login brute force protection: wordfence',
				'Checkout card-testing protection: woocommerce-payments',
			),
			$result->details()
		);
	}

	/**
	 * A scanner is neither protection against card testing nor a login limiter.
	 */
	public function test_sucuri_scanner_alone_is_a_warning(): void {
		$this->assertSame( 'warning', $this->check( array( 'sucuri-scanner/sucuri.php' ) )->run()->severity() );
	}

	/**
	 * Only a login limiter: info, and the summary says what is missing.
	 */
	public function test_login_limiter_only_is_info(): void {
		$result = $this->check( array( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'card testing', $result->summary() );
		$this->assertContains( 'Checkout card-testing protection: none found', $result->details() );
	}

	/**
	 * Only a gateway with built-in protection: info, no longer the false "Only Stripe is active".
	 */
	public function test_stripe_only_is_info_without_the_false_claim(): void {
		$result = $this->check( array( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php', 'woocommerce/woocommerce.php', 'akismet/akismet.php' ) )->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringNotContainsString( 'Only Stripe', $result->summary() );
		$this->assertContains( 'Checkout card-testing protection: woocommerce-gateway-stripe', $result->details() );
		$this->assertContains( 'Login brute force protection: none found', $result->details() );
	}

	/**
	 * A checkout captcha counts as card-testing protection.
	 */
	public function test_checkout_captcha_counts_for_checkout(): void {
		$result = $this->check( array( 'recaptcha-for-woocommerce/recaptcha.php', 'loginizer/loginizer.php' ) )->run();

		$this->assertSame( 'ok', $result->severity() );
	}
}
