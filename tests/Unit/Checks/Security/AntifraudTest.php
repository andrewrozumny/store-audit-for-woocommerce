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
	 * No protection plugin is a warning.
	 */
	public function test_no_plugin_warns(): void {
		$check = new Antifraud( static fn () => array( 'woocommerce/woocommerce.php', 'hello.php' ) );

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * A known plugin is ok.
	 */
	public function test_known_plugin_is_ok(): void {
		$check  = new Antifraud( static fn () => array( 'woocommerce/woocommerce.php', 'wordfence/wordfence.php' ) );
		$result = $check->run();

		$this->assertSame( 'ok', $result->severity() );
		$this->assertSame( array( 'wordfence' ), $result->details() );
	}

	/**
	 * Stripe alone is partial protection.
	 */
	public function test_stripe_only_is_info(): void {
		$check = new Antifraud( static fn () => array( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' ) );

		$this->assertSame( 'info', $check->run()->severity() );
	}
}
