<?php
/**
 * Guessable administrator logins.
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
 * Guessable administrator logins.
 */
final class AdminUsername extends AbstractSecurityCheck {

	/**
	 * Administrators provider.
	 *
	 * @var callable|null
	 */
	private $admins;

	/**
	 * Login lookup.
	 *
	 * @var callable|null
	 */
	private $lookup;

	/**
	 * Constructor.
	 *
	 * @param callable|null $admins Returns the administrator accounts (tests).
	 * @param callable|null $lookup Returns one account by login or null (tests).
	 */
	public function __construct( ?callable $admins = null, ?callable $lookup = null ) {
		$this->admins = $admins;
		$this->lookup = $lookup;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_admin_username';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Guessable admin username', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Attackers try the login "admin" first, and a login that is also shown publicly as the author name gives them half of the password for free. This makes break-ins much easier.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Create a new administrator with a unique login, move your content to it, and delete the old "admin" user.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$admins = null !== $this->admins ? (array) call_user_func( $this->admins ) : $this->default_administrators();

		$critical = array();
		$warnings = array();
		foreach ( array( 'admin', 'administrator' ) as $login ) {
			$user = null !== $this->lookup ? call_user_func( $this->lookup, $login ) : $this->default_user_by_login( $login );
			if ( ! is_array( $user ) ) {
				continue;
			}
			if ( in_array( 'administrator', $user['roles'], true ) ) {
				$critical[] = $user['login'];
			} else {
				$warnings[] = $user['login'];
			}
		}

		$exposed = array();
		foreach ( $admins as $admin ) {
			$login = strtolower( $admin['login'] );
			if ( '' !== $login && ( strtolower( $admin['display_name'] ) === $login || strtolower( $admin['nicename'] ) === $login ) ) {
				$exposed[] = $admin['login'];
			}
		}

		$details = array();
		foreach ( $critical as $login ) {
			/* translators: %s: login name. */
			$details[] = sprintf( __( 'Administrator with the login "%s"', 'store-audit-for-woocommerce' ), $login );
		}
		foreach ( $warnings as $login ) {
			/* translators: %s: login name. */
			$details[] = sprintf( __( 'User with the login "%s" (not an administrator)', 'store-audit-for-woocommerce' ), $login );
		}
		foreach ( $exposed as $login ) {
			/* translators: %s: login name. */
			$details[] = sprintf( __( 'Login "%s" is also shown publicly as the author name', 'store-audit-for-woocommerce' ), $login );
		}
		$details = $this->cap_list( $details );

		if ( array() !== $critical ) {
			return Result::critical( __( 'An administrator account uses the login "admin" or "administrator", the first one attackers try.', 'store-audit-for-woocommerce' ), $details );
		}
		if ( array() !== $warnings ) {
			return Result::warning( __( 'A user account uses the login "admin" or "administrator", the first one attackers try.', 'store-audit-for-woocommerce' ), $details );
		}
		if ( array() !== $exposed ) {
			return Result::warning(
				/* translators: %d: number of administrators. */
				sprintf( _n( '%d administrator login is shown publicly as the author name.', '%d administrator logins are shown publicly as the author name.', count( $exposed ), 'store-audit-for-woocommerce' ), count( $exposed ) ),
				$details
			);
		}
		return Result::ok( __( 'No guessable administrator logins found.', 'store-audit-for-woocommerce' ) );
	}
}
