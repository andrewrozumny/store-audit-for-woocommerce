<?php
/**
 * Taxes tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use Brain\Monkey\Functions;
use StoreAudit\Checks\WooCommerce\Taxes;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Taxes.
 *
 * @covers \StoreAudit\Checks\WooCommerce\Taxes
 */
final class TaxesTest extends TestCase {

	/**
	 * Taxes off.
	 */
	public function test_disabled_is_info(): void {
		$check = new Taxes(
			static fn(): array => array(
				'enabled' => false,
				'rates'   => 0,
			)
		);

		$this->assertSame( 'info', $check->run()->severity() );
	}

	/**
	 * Taxes on, no rates and no known tax service: not proven wrong, so info with the honest wording.
	 */
	public function test_enabled_without_rates_is_info(): void {
		$check = new Taxes(
			static fn(): array => array(
				'enabled' => true,
				'rates'   => 0,
				'auto'    => '',
			)
		);

		$result = $check->run();

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'No manual tax rates are set. If an automatic tax service calculates your tax, this is fine; otherwise no tax is added.', $result->summary() );
	}

	/**
	 * An automatic tax service makes an empty rate table fine.
	 */
	public function test_automatic_tax_service_is_ok(): void {
		$check = new Taxes(
			static fn(): array => array(
				'enabled' => true,
				'rates'   => 0,
				'auto'    => 'TaxJar',
			)
		);

		$result = $check->run();

		$this->assertSame( 'ok', $result->severity() );
		$this->assertContains( 'Taxes are calculated automatically by TaxJar.', $result->details() );
	}

	/**
	 * The live reader finds WooCommerce Tax through its option and Stripe Tax through its plugin.
	 */
	public function test_live_reader_detects_the_service(): void {
		$db         = \Mockery::mock( 'wpdb' );
		$db->prefix = 'wp_';
		$db->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $text ): string => $text );
		$db->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $query ): string => $query );
		$db->shouldReceive( 'get_var' )->andReturnUsing( static fn( string $query ) => str_contains( $query, 'SHOW TABLES' ) ? 'wp_woocommerce_tax_rates' : 0 );
		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.
		Functions\when( 'wc_tax_enabled' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $fallback ) => 'active_plugins' === $name ? array( 'stripe-tax-for-woocommerce/stripe-tax.php' ) : $fallback
		);
		$result = ( new Taxes() )->run();
		unset( $GLOBALS['wpdb'] );

		$this->assertSame( 'ok', $result->severity() );
		$this->assertContains( 'Taxes are calculated automatically by Stripe Tax.', $result->details() );

		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $fallback ) => 'wc_connect_taxes_enabled' === $name ? 'yes' : $fallback
		);
		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.
		$result          = ( new Taxes() )->run();
		unset( $GLOBALS['wpdb'] );

		$this->assertContains( 'Taxes are calculated automatically by WooCommerce Tax.', $result->details() );
	}

	/**
	 * Taxes on with rates.
	 */
	public function test_enabled_with_rates_is_ok(): void {
		$check = new Taxes(
			static fn(): array => array(
				'enabled' => true,
				'rates'   => 3,
			)
		);

		$this->assertSame( 'ok', $check->run()->severity() );
	}
}
