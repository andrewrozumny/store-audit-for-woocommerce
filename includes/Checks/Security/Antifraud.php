<?php
/**
 * Protection against bots and fake payments.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Result;
use StoreAudit\Support\ActivePlugins;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Protection against bots and fake payments.
 */
final class Antifraud extends AbstractSecurityCheck {

	/**
	 * Plugins that limit password guessing on the login form. Scanners such as Sucuri Scanner are not in the list:
	 * they report problems but stop nothing.
	 */
	private const LOGIN = array(
		'wordfence',
		'limit-login-attempts-reloaded',
		'loginizer',
		'solid-security',
		'better-wp-security',
		'ithemes-security-pro',
		'all-in-one-wp-security-and-firewall',
		'wp-cerber',
		'wp-defender',
		'defender-security',
		'google-captcha',
		'cleantalk-spam-protect',
	);

	/**
	 * Plugins that put a captcha or fraud scoring in front of the checkout.
	 */
	private const CHECKOUT = array(
		'woocommerce-anti-fraud',
		'advanced-google-recaptcha',
		'simple-cloudflare-turnstile',
		'recaptcha-for-woocommerce',
		'hcaptcha-for-forms-and-more',
	);

	/**
	 * Payment gateways with built-in fraud screening (Stripe Radar, WooPayments, PayPal).
	 */
	private const GATEWAYS = array(
		'woocommerce-payments',
		'woocommerce-gateway-stripe',
		'woocommerce-paypal-payments',
	);

	/**
	 * Active plugin basenames provider (tests).
	 *
	 * @var callable|null
	 */
	private $active;

	/**
	 * Constructor.
	 *
	 * @param callable|null $active Returns the list of active plugin basenames (tests).
	 */
	public function __construct( ?callable $active = null ) {
		$this->active = $active;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_antifraud';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Bot and card-testing protection', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Card testing bots can run thousands of fake payments through a store. The merchant pays the fees, the payment account gets flagged, and real customers can be blocked. A captcha on checkout, fraud rules at the payment provider and a login limiter slow them down.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Add a captcha on checkout and a login limiter, and turn on fraud rules at your payment provider.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$slugs = null !== $this->active ? ActivePlugins::slugs_of( Arr::to_string_list( call_user_func( $this->active ) ) ) : ActivePlugins::slugs();

		$login    = array();
		$checkout = array();
		foreach ( $this->known_slugs() as $slug ) {
			if ( ! in_array( $slug, $slugs, true ) ) {
				continue;
			}
			// A plugin added through the filter is trusted for both jobs.
			$is_login    = in_array( $slug, self::LOGIN, true );
			$is_checkout = in_array( $slug, self::CHECKOUT, true );
			if ( $is_login || ! $is_checkout ) {
				$login[] = $slug;
			}
			if ( $is_checkout || ! $is_login ) {
				$checkout[] = $slug;
			}
		}
		foreach ( self::GATEWAYS as $gateway ) {
			if ( in_array( $gateway, $slugs, true ) ) {
				$checkout[] = $gateway;
			}
		}

		$none    = __( 'none found', 'store-audit-for-woocommerce' );
		$details = array(
			/* translators: %s: plugin names. */
			sprintf( __( 'Login brute force protection: %s', 'store-audit-for-woocommerce' ), array() === $login ? $none : implode( ', ', $login ) ),
			/* translators: %s: plugin names. */
			sprintf( __( 'Checkout card-testing protection: %s', 'store-audit-for-woocommerce' ), array() === $checkout ? $none : implode( ', ', $checkout ) ),
		);

		if ( array() !== $login && array() !== $checkout ) {
			return Result::ok( __( 'Login and checkout both have protection plugins. This only checks that they are installed, not how they are set up.', 'store-audit-for-woocommerce' ), $details );
		}
		if ( array() !== $login ) {
			return Result::info( __( 'Password guessing is limited, but nothing protects the checkout against card testing bots. Add a captcha on checkout or turn on fraud rules at your payment provider.', 'store-audit-for-woocommerce' ), $details );
		}
		if ( array() !== $checkout ) {
			return Result::info( __( 'The checkout has protection against card testing, but nothing limits password guessing on the login form. Add a login limiter or a captcha on login.', 'store-audit-for-woocommerce' ), $details );
		}
		return Result::warning( __( 'No known login limiter, captcha or fraud tool is active, so card testing bots and password guessing meet no resistance.', 'store-audit-for-woocommerce' ), $details );
	}

	/**
	 * Plugin slugs that count as protection, after the public filter. Third-party code may return anything.
	 *
	 * @return list<string>
	 */
	private function known_slugs(): array {
		$known = $this->filtered_slugs();
		return is_array( $known ) ? array_values( array_map( static fn( mixed $slug ): string => Arr::to_string( $slug ), $known ) ) : self::known();
	}

	/**
	 * Built-in slugs of login and checkout protection plugins.
	 *
	 * @return list<string>
	 */
	private static function known(): array {
		return array_merge( self::LOGIN, self::CHECKOUT );
	}

	/**
	 * Run the slugs through the public filter. The result is untyped on purpose.
	 */
	private function filtered_slugs(): mixed {
		return apply_filters( 'store_audit_antifraud_plugins', self::known() );
	}
}
