<?php
/**
 * TermsPage tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\TermsPage;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for TermsPage.
 *
 * @covers \StoreAudit\Checks\WooCommerce\TermsPage
 */
final class TermsPageTest extends TestCase {

	/**
	 * Not set.
	 */
	public function test_missing_warns(): void {
		$check = new TermsPage(
			static fn(): array => array(
				'id'     => 0,
				'status' => '',
			)
		);

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * Unpublished.
	 */
	public function test_draft_warns(): void {
		$check = new TermsPage(
			static fn(): array => array(
				'id'     => 9,
				'status' => 'draft',
			)
		);

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * Published.
	 */
	public function test_published_is_ok(): void {
		$check = new TermsPage(
			static fn(): array => array(
				'id'     => 9,
				'status' => 'publish',
			)
		);

		$this->assertSame( 'ok', $check->run()->severity() );
	}
}
