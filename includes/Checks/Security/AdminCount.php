<?php
/**
 * Number of administrator accounts.
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
 * Number of administrator accounts.
 */
final class AdminCount extends AbstractSecurityCheck {

	/**
	 * Administrators provider.
	 *
	 * @var callable|null
	 */
	private $admins;

	/**
	 * Constructor.
	 *
	 * @param callable|null $admins Returns the administrator accounts (tests).
	 */
	public function __construct( ?callable $admins = null ) {
		$this->admins = $admins;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_admin_count';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Number of administrators', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Every administrator account is a key to the whole store. The more of them exist, the more chances there are for a weak password or a hacked mailbox to expose customer data and payments.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Remove administrator rights from accounts that do not need them (use Shop manager or Editor instead).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$admins = null !== $this->admins ? (array) call_user_func( $this->admins ) : $this->default_administrators();
		$count  = count( $admins );

		if ( $count <= 3 ) {
			return Result::ok(
				/* translators: %d: number of administrators. */
				sprintf( _n( '%d administrator account.', '%d administrator accounts.', $count, 'store-audit-for-woocommerce' ), $count )
			);
		}

		$lines = array();
		foreach ( $admins as $admin ) {
			$at     = strrpos( $admin['email'], '@' );
			$domain = false === $at ? '' : substr( $admin['email'], $at + 1 );
			/* translators: 1: login, 2: email domain. */
			$lines[] = sprintf( __( '%1$s (email at %2$s)', 'store-audit-for-woocommerce' ), $admin['login'], '' === $domain ? '?' : $domain );
		}
		return Result::warning(
			/* translators: %d: number of administrators. */
			sprintf( __( '%d administrator accounts, more than the 3 a typical store needs.', 'store-audit-for-woocommerce' ), $count ),
			$this->cap_list( $lines )
		);
	}
}
