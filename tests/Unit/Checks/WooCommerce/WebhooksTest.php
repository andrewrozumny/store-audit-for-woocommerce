<?php
/**
 * Webhooks tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\Webhooks;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Webhooks.
 *
 * @covers \StoreAudit\Checks\WooCommerce\Webhooks
 */
final class WebhooksTest extends TestCase {

	/**
	 * No problem webhooks.
	 */
	public function test_none_is_ok(): void {
		$this->assertSame( 'ok', ( new Webhooks( static fn(): array => array() ) )->run()->severity() );
	}

	/**
	 * Paused webhook.
	 */
	public function test_problem_webhooks_warn(): void {
		$check = new Webhooks(
			static fn(): array => array(
				array(
					'name'     => 'Accounting',
					'status'   => 'paused',
					'failures' => 5,
				),
			)
		);

		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'Accounting: status paused, 5 failed deliveries' ), $result->details() );
	}
}
