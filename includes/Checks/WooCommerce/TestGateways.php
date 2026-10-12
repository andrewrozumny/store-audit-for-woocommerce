<?php
/**
 * Payment gateways in test mode check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Plugins\DetailList;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test or sandbox payment gateways must not be enabled on a live store.
 */
final class TestGateways extends AbstractCheck {

	/**
	 * Setting keys that mean "test mode" when set to yes, 1 or true.
	 */
	private const FLAG_KEYS = array( 'testmode', 'test_mode', 'sandbox', 'is_sandbox', 'enable_sandbox', 'sandbox_on', 'use_sandbox' );

	/**
	 * Offline gateways.
	 */
	private const OFFLINE = array( 'bacs', 'cheque', 'cod' );

	/**
	 * Gateway source: returns list of array{id:string,title:string,enabled:bool,test:bool}.
	 *
	 * @var callable
	 */
	private $source;

	/**
	 * Production flag override.
	 *
	 * @var bool|null
	 */
	private ?bool $production;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source     Gateway source override for tests.
	 * @param bool|null     $production Whether the site is production, override for tests.
	 */
	public function __construct( ?callable $source = null, ?bool $production = null ) {
		$this->source     = $source ?? array( $this, 'read_gateways' );
		$this->production = $production;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_test_gateways';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::WOOCOMMERCE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Payment gateways', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'A payment method left in test (sandbox) mode accepts orders without charging real money. Customers think they paid, and you ship goods for free. With no payment method at all, nobody can pay.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'In WooCommerce, Settings, Payments, switch the gateway to live mode and enter the live keys, or enable a payment method.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$all     = ( $this->source )();
		$enabled = array_values( array_filter( $all, static fn( array $g ): bool => $g['enabled'] ) );

		if ( array() === $enabled ) {
			return Result::critical( __( 'No payment method is enabled, so customers cannot pay.', 'store-audit-for-woocommerce' ) );
		}

		$test = array();
		foreach ( $enabled as $gateway ) {
			if ( $gateway['test'] ) {
				$test[] = $gateway['title'];
			}
		}

		if ( array() !== $test ) {
			$production = $this->production ?? $this->is_production();
			$summary    = sprintf(
				/* translators: %s: gateway names. */
				__( 'Test mode is on for: %s. Real customers\' payments will not be charged.', 'store-audit-for-woocommerce' ),
				implode( ', ', $test )
			);
			return $production ? Result::critical( $summary, $test ) : Result::warning( $summary, $test );
		}

		$online = array_filter( $enabled, static fn( array $g ): bool => ! in_array( $g['id'], self::OFFLINE, true ) );
		if ( array() === $online ) {
			return Result::info(
				__( 'Only offline payment methods (bank transfer, cheque or cash on delivery) are enabled.', 'store-audit-for-woocommerce' ),
				array_map( static fn( array $g ): string => $g['title'], $enabled )
			);
		}

		return Result::ok(
			sprintf(
				/* translators: %d: number of enabled payment methods. */
				_n( '%d payment method is enabled and none is in test mode.', '%d payment methods are enabled and none is in test mode.', count( $enabled ), 'store-audit-for-woocommerce' ),
				count( $enabled )
			),
			array_map( static fn( array $g ): string => $g['title'], $enabled )
		);
	}

	/**
	 * Whether a settings array shows test or sandbox mode.
	 *
	 * @param array<mixed> $settings Gateway settings.
	 */
	public static function settings_in_test_mode( array $settings ): bool {
		foreach ( self::FLAG_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) && self::truthy( $settings[ $key ] ) ) {
				return true;
			}
		}
		if ( isset( $settings['environment'] ) && is_string( $settings['environment'] ) && in_array( strtolower( $settings['environment'] ), array( 'sandbox', 'test' ), true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether a stored flag is on.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function truthy( $value ): bool {
		return true === $value || 1 === $value || in_array( $value, array( 'yes', '1', 'true', 'on' ), true );
	}

	/**
	 * Read enabled gateways from WooCommerce.
	 *
	 * @return list<array{id:string,title:string,enabled:bool,test:bool}>
	 */
	private function read_gateways(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}
		$out = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			$enabled = 'yes' === $gateway->enabled;
			$id      = (string) $gateway->id;
			$test    = false;
			if ( $enabled ) {
				$settings = is_array( $gateway->settings ) ? $gateway->settings : array();
				$test     = self::settings_in_test_mode( $settings ) || $this->known_plugin_test_mode( $id );
			}
			$title = wp_strip_all_tags( (string) $gateway->get_method_title() );
			$out[] = array(
				'id'      => $id,
				'title'   => '' !== $title ? $title : $id,
				'enabled' => $enabled,
				'test'    => $test,
			);
		}
		return $out;
	}

	/**
	 * Test-mode flags that live outside the gateway's own settings.
	 *
	 * @param string $id Gateway id.
	 */
	private function known_plugin_test_mode( string $id ): bool {
		if ( 0 === strpos( $id, 'ppcp' ) ) {
			foreach ( array( 'woocommerce-ppcp-settings', 'woocommerce-ppcp-data-common' ) as $option ) {
				$value = get_option( $option, array() );
				if ( is_array( $value ) && ( self::settings_in_test_mode( $value ) || ! empty( $value['sandbox_on'] ) ) ) {
					return true;
				}
			}
		}
		if ( 0 === strpos( $id, 'square' ) ) {
			$value = get_option( 'wc_square_settings', array() );
			if ( is_array( $value ) && self::settings_in_test_mode( $value ) ) {
				return true;
			}
		}
		if ( 0 === strpos( $id, 'woocommerce_payments' ) ) {
			if ( is_callable( array( 'WC_Payments', 'mode' ) ) ) {
				$mode = call_user_func( array( 'WC_Payments', 'mode' ) );
				if ( is_object( $mode ) && is_callable( array( $mode, 'is_test' ) ) && call_user_func( array( $mode, 'is_test' ) ) ) {
					return true;
				}
			}
			if ( self::truthy( get_option( 'wcpay_dev_mode', 'no' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
