<?php
/**
 * TestGateways tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\TestGateways;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for TestGateways.
 *
 * @covers \StoreAudit\Checks\WooCommerce\TestGateways
 */
final class TestGatewaysTest extends TestCase {

	/**
	 * Gateway row helper.
	 *
	 * @param string $id      Id.
	 * @param bool   $enabled Enabled.
	 * @param bool   $test    Test mode.
	 * @return array{id:string,title:string,enabled:bool,test:bool}
	 */
	private function gw( string $id, bool $enabled, bool $test = false ): array {
		return array(
			'id'      => $id,
			'title'   => strtoupper( $id ),
			'enabled' => $enabled,
			'test'    => $test,
		);
	}

	/**
	 * No gateways enabled.
	 */
	public function test_no_enabled_gateway_is_critical(): void {
		$check = new TestGateways( fn(): array => array( $this->gw( 'stripe', false ) ), true );

		$this->assertSame( 'critical', $check->run()->severity() );
	}

	/**
	 * Test mode on production.
	 */
	public function test_test_mode_on_production_is_critical(): void {
		$check = new TestGateways( fn(): array => array( $this->gw( 'stripe', true, true ) ), true );

		$result = $check->run();

		$this->assertSame( 'critical', $result->severity() );
		$this->assertSame( array( 'STRIPE' ), $result->details() );
	}

	/**
	 * Test mode outside production.
	 */
	public function test_test_mode_off_production_is_warning(): void {
		$check = new TestGateways( fn(): array => array( $this->gw( 'stripe', true, true ) ), false );

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * Offline only.
	 */
	public function test_only_offline_is_info(): void {
		$check = new TestGateways( fn(): array => array( $this->gw( 'bacs', true ), $this->gw( 'cod', true ) ), true );

		$this->assertSame( 'info', $check->run()->severity() );
	}

	/**
	 * Live gateway.
	 */
	public function test_live_gateway_is_ok(): void {
		$check = new TestGateways( fn(): array => array( $this->gw( 'bacs', true ), $this->gw( 'stripe', true ) ), true );

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Generic test-mode keys.
	 */
	public function test_settings_detection(): void {
		$this->assertTrue( TestGateways::settings_in_test_mode( array( 'testmode' => 'yes' ) ) );
		$this->assertTrue( TestGateways::settings_in_test_mode( array( 'sandbox' => '1' ) ) );
		$this->assertTrue( TestGateways::settings_in_test_mode( array( 'environment' => 'sandbox' ) ) );
		$this->assertTrue( TestGateways::settings_in_test_mode( array( 'is_sandbox' => true ) ) );
		$this->assertFalse(
			TestGateways::settings_in_test_mode(
				array(
					'testmode'    => 'no',
					'environment' => 'production',
				)
			)
		);
		$this->assertFalse( TestGateways::settings_in_test_mode( array() ) );
	}
}
