<?php
/**
 * Administrators who have not been active for months.
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
 * Administrators who have not been active for months.
 */
final class InactiveAdmins extends AbstractSecurityCheck {

	/**
	 * Months without activity before an account counts as inactive.
	 */
	private const INACTIVE_SECONDS = 15811200;

	/**
	 * Meta keys that store a last-login or last-activity time.
	 */
	private const META_KEYS = array( 'wc_last_active', 'last_login', '_last_login', 'session_tokens' );

	/**
	 * Administrators provider.
	 *
	 * @var callable|null
	 */
	private $admins;

	/**
	 * User meta reader: (user id, key) returns the value.
	 *
	 * @var callable|null
	 */
	private $meta;

	/**
	 * Current time.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Constructor.
	 *
	 * @param callable|null $admins Returns the administrator accounts (tests).
	 * @param callable|null $meta   User meta reader (tests).
	 * @param int|null      $now    Current timestamp (tests).
	 */
	public function __construct( ?callable $admins = null, ?callable $meta = null, ?int $now = null ) {
		$this->admins = $admins;
		$this->meta   = $meta;
		$this->now    = $now ?? time();
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_inactive_admins';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Inactive administrators', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'An administrator account nobody uses any more is rarely watched, so a stolen password there can go unnoticed for a long time. Unused accounts should not keep full access to your store.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Delete or downgrade administrator accounts that have not been used in the last 6 months.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$admins = null !== $this->admins ? (array) call_user_func( $this->admins ) : $this->default_administrators();

		$inactive = array();
		$unknown  = array();
		foreach ( $admins as $admin ) {
			$last = $this->last_activity( $admin['id'] );
			if ( null === $last ) {
				$unknown[] = $admin['login'];
			} elseif ( $this->now - $last > self::INACTIVE_SECONDS ) {
				/* translators: 1: login, 2: date of last activity. */
				$inactive[] = sprintf( __( '%1$s (last active %2$s)', 'store-audit-for-woocommerce' ), $admin['login'], gmdate( 'Y-m-d', $last ) );
			}
		}

		$details = $inactive;
		if ( array() !== $unknown ) {
			/* translators: %s: comma separated logins. */
			$details[] = sprintf( __( 'Unknown activity (no login record): %s', 'store-audit-for-woocommerce' ), implode( ', ', $unknown ) );
		}
		$details = $this->cap_list( $details );

		if ( array() !== $inactive ) {
			return Result::warning(
				/* translators: %d: number of accounts. */
				sprintf( _n( '%d administrator has not been active for over 6 months.', '%d administrators have not been active for over 6 months.', count( $inactive ), 'store-audit-for-woocommerce' ), count( $inactive ) ),
				$details
			);
		}
		if ( array() !== $unknown ) {
			return Result::info(
				/* translators: %d: number of accounts. */
				sprintf( __( 'No recent login record for %d administrator account(s), so inactivity cannot be judged.', 'store-audit-for-woocommerce' ), count( $unknown ) ),
				$details
			);
		}
		return Result::ok( __( 'All administrators were active in the last 6 months.', 'store-audit-for-woocommerce' ) );
	}

	/**
	 * Latest known activity timestamp of a user, or null when there is no record.
	 *
	 * @param int $user_id User id.
	 */
	private function last_activity( int $user_id ): ?int {
		$latest = null;
		foreach ( self::META_KEYS as $key ) {
			$value = null !== $this->meta ? call_user_func( $this->meta, $user_id, $key ) : get_user_meta( $user_id, $key, true );
			foreach ( $this->timestamps( $key, $value ) as $stamp ) {
				if ( null === $latest || $stamp > $latest ) {
					$latest = $stamp;
				}
			}
		}
		return $latest;
	}

	/**
	 * Timestamps contained in one meta value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return list<int>
	 */
	private function timestamps( string $key, mixed $value ): array {
		if ( 'session_tokens' === $key ) {
			$stamps = array();
			if ( is_array( $value ) ) {
				foreach ( $value as $session ) {
					if ( is_array( $session ) && isset( $session['login'] ) && is_numeric( $session['login'] ) ) {
						$stamps[] = (int) $session['login'];
					}
				}
			}
			return $stamps;
		}
		if ( is_numeric( $value ) && (int) $value > 0 ) {
			return array( (int) $value );
		}
		if ( is_string( $value ) && '' !== $value ) {
			$parsed = strtotime( $value );
			return false === $parsed ? array() : array( $parsed );
		}
		return array();
	}
}
