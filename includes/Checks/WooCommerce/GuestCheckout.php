<?php
/**
 * Guest checkout and account creation check.
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
 * Reports how customers can buy: as guests, by registering, or not at all.
 */
final class GuestCheckout extends AbstractCheck {

	/**
	 * Option reader: receives an option name, returns its value.
	 *
	 * @var callable
	 */
	private $option;

	/**
	 * Constructor.
	 *
	 * @param callable|null $option Option reader override for tests.
	 */
	public function __construct( ?callable $option = null ) {
		$this->option = $option ?? static fn( string $name ) => get_option( $name, 'no' );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_guest_checkout';
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
		return __( 'Guest checkout and accounts', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Many shoppers abandon a purchase when forced to create an account. If guest checkout is off and checkout offers no account creation, customers cannot complete an order at all.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'In WooCommerce, Settings, Accounts and Privacy, allow guest checkout or account creation at checkout.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$guest    = 'yes' === ( $this->option )( 'woocommerce_enable_guest_checkout' );
		$checkout = 'yes' === ( $this->option )( 'woocommerce_enable_signup_and_login_from_checkout' );
		$account  = 'yes' === ( $this->option )( 'woocommerce_enable_myaccount_registration' );

		$details = array(
			$guest ? __( 'Guest checkout: allowed', 'store-audit-for-woocommerce' ) : __( 'Guest checkout: not allowed', 'store-audit-for-woocommerce' ),
			$checkout ? __( 'Account creation at checkout: allowed', 'store-audit-for-woocommerce' ) : __( 'Account creation at checkout: not allowed', 'store-audit-for-woocommerce' ),
			$account ? __( 'Registration on the My account page: allowed', 'store-audit-for-woocommerce' ) : __( 'Registration on the My account page: not allowed', 'store-audit-for-woocommerce' ),
		);

		if ( ! $guest && ! $checkout ) {
			return Result::warning(
				__( 'Guest checkout is off and customers cannot create an account at checkout, so new customers may not be able to buy.', 'store-audit-for-woocommerce' ),
				$details
			);
		}

		if ( $guest ) {
			return Result::ok( __( 'Customers can check out as guests.', 'store-audit-for-woocommerce' ), $details );
		}

		return Result::info( __( 'Customers must create an account to check out.', 'store-audit-for-woocommerce' ), $details );
	}
}
