<?php
/**
 * Pages tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\Pages;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Pages.
 *
 * @covers \StoreAudit\Checks\WooCommerce\Pages
 */
final class PagesTest extends TestCase {

	/**
	 * Pages source.
	 *
	 * @param array<string,string> $statuses Key => status ('' missing, 'none' unassigned).
	 * @return callable
	 */
	private function pages( array $statuses ): callable {
		return static function () use ( $statuses ): array {
			$out = array();
			foreach ( array( 'cart', 'checkout', 'myaccount', 'shop' ) as $i => $key ) {
				$status      = $statuses[ $key ] ?? 'publish';
				$out[ $key ] = array(
					'id'     => 'none' === $status ? -1 : $i + 10,
					'status' => 'none' === $status ? '' : $status,
				);
			}
			return $out;
		};
	}

	/**
	 * All published.
	 */
	public function test_all_published_is_ok(): void {
		$this->assertSame( 'ok', ( new Pages( $this->pages( array() ) ) )->run()->severity() );
	}

	/**
	 * Cart missing.
	 */
	public function test_missing_cart_is_critical(): void {
		$result = ( new Pages( $this->pages( array( 'cart' => 'none' ) ) ) )->run();

		$this->assertSame( 'critical', $result->severity() );
		$this->assertStringContainsString( 'Cart page', $result->details()[0] );
	}

	/**
	 * Checkout draft.
	 */
	public function test_draft_checkout_is_critical(): void {
		$result = ( new Pages( $this->pages( array( 'checkout' => 'draft' ) ) ) )->run();

		$this->assertSame( 'critical', $result->severity() );
		$this->assertStringContainsString( 'draft', $result->details()[0] );
	}

	/**
	 * Shop trashed.
	 */
	public function test_shop_problem_is_warning(): void {
		$this->assertSame(
			'warning',
			( new Pages(
				$this->pages(
					array(
						'shop'      => 'trash',
						'myaccount' => 'none',
					)
				)
			) )->run()->severity()
		);
	}

	/**
	 * Assigned page gone.
	 */
	public function test_deleted_page_is_reported(): void {
		$check = new Pages(
			static fn(): array => array(
				'cart'      => array(
					'id'     => 5,
					'status' => '',
				),
				'checkout'  => array(
					'id'     => 6,
					'status' => 'publish',
				),
				'myaccount' => array(
					'id'     => 7,
					'status' => 'publish',
				),
				'shop'      => array(
					'id'     => 8,
					'status' => 'publish',
				),
			)
		);

		$this->assertStringContainsString( 'does not exist', $check->run()->details()[0] );
	}
}
