<?php
/**
 * Protection against bots and fake payments.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Protection against bots and fake payments.
 */
final class Antifraud extends AbstractSecurityCheck {

	/**
	 * Plugins that give some protection against bots, fake orders or password guessing.
	 */
	private const KNOWN = array(
		'woocommerce-anti-fraud',
		'wordfence',
		'limit-login-attempts-reloaded',
		'loginizer',
		'advanced-google-recaptcha',
		'google-captcha',
		'simple-cloudflare-turnstile',
		'recaptcha-for-woocommerce',
		'cleantalk-spam-protect',
		'hcaptcha-for-forms-and-more',
		'solid-security',
		'better-wp-security',
		'all-in-one-wp-security-and-firewall',
		'wp-cerber',
		'wp-defender',
		'sucuri-scanner',
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
		return __( 'Card testing bots can run thousands of fake payments through a store. The merchant pays the fees, the payment account gets flagged, and real customers can be blocked. A captcha, login limiter or anti-fraud plugin slows them down.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Install and configure a captcha or login-limit plugin, and turn on fraud tools in your payment provider.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$plugins = null !== $this->active ? (array) call_user_func( $this->active ) : $this->active_plugins();
		$slugs   = array();
		foreach ( $plugins as $basename ) {
			$slug = explode( '/', (string) $basename )[0];
			$slugs[ preg_replace( '/\.php$/', '', $slug ) ?? $slug ] = true;
		}

		$known = apply_filters( 'store_audit_antifraud_plugins', self::KNOWN );
		$known = is_array( $known ) ? array_map( 'strval', $known ) : self::KNOWN;
		$hits  = array_values( array_filter( $known, static fn ( string $slug ): bool => isset( $slugs[ $slug ] ) ) );

		if ( array() !== $hits ) {
			return Result::ok(
				/* translators: %s: plugin names. */
				sprintf( __( 'A protection plugin is active: %s. This only checks that it is installed, not how it is set up.', 'store-audit-for-woocommerce' ), implode( ', ', $hits ) ),
				$hits
			);
		}
		if ( isset( $slugs['woocommerce-gateway-stripe'] ) ) {
			return Result::info( __( 'Only Stripe is active: its built-in Radar rules give partial protection, but no captcha or login limiter was found.', 'store-audit-for-woocommerce' ) );
		}
		return Result::warning( __( 'No known anti-fraud, captcha or login-limit plugin is active, so card testing bots and password guessing meet no resistance.', 'store-audit-for-woocommerce' ) );
	}

	/**
	 * Active plugin basenames, including network-wide ones.
	 *
	 * @return list<string>
	 */
	private function active_plugins(): array {
		$active = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
		}
		return array_values( $active );
	}
}
