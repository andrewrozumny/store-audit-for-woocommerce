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
	 * @var \Closure|null
	 */
	private ?\Closure $admins;

	/**
	 * Login lookup.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $lookup;

	/**
	 * Constructor.
	 *
	 * @param callable|null $admins Returns the administrator accounts (tests).
	 * @param callable|null $lookup Returns one account by login or null (tests).
	 */
	public function __construct( ?callable $admins = null, ?callable $lookup = null ) {
		$this->admins = null === $admins ? null : \Closure::fromCallable( $admins );
		$this->lookup = null === $lookup ? null : \Closure::fromCallable( $lookup );
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
		return __( 'Create a new administrator with a unique login, move your content to it, and delete the old "admin" user. Show a nickname publicly instead of the login.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$admins   = null !== $this->admins ? $this->normalise_admins( call_user_func( $this->admins ) ) : $this->default_administrators();
		$guess    = $this->guessable_logins();
		$critical = $guess['critical'];
		$warnings = $guess['warnings'];
		$public   = self::public_logins( $admins );
		$exposed  = $public['exposed'];
		$archives = $public['archives'];

		$details = $this->cap_list( self::detail_lines( $critical, $warnings, $exposed, $archives ) );

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
				$details,
				__( 'In Users > Profile, set a nickname and choose it under "Display name publicly as", so the login stays private.', 'store-audit-for-woocommerce' )
			);
		}
		if ( array() !== $archives ) {
			return Result::info(
				/* translators: %d: number of administrators. */
				sprintf( _n( 'The author archive address of %d administrator shows the login.', 'The author archive addresses of %d administrators show the login.', count( $archives ), 'store-audit-for-woocommerce' ), count( $archives ) ),
				$details,
				__( 'Change the user slug with a plugin that edits user slugs, or turn off author archives.', 'store-audit-for-woocommerce' )
			);
		}
		return Result::ok( __( 'No guessable administrator logins found.', 'store-audit-for-woocommerce' ) );
	}

	/**
	 * Accounts that use the login "admin" or "administrator": administrators (critical) and other users (warnings).
	 *
	 * @return array{critical:list<string>,warnings:list<string>}
	 */
	private function guessable_logins(): array {
		$critical = array();
		$warnings = array();
		foreach ( array( 'admin', 'administrator' ) as $login ) {
			$found = null !== $this->lookup ? call_user_func( $this->lookup, $login ) : $this->default_user_by_login( $login );
			if ( ! is_array( $found ) ) {
				continue;
			}
			$user = $this->normalise_admin_row( $found );
			if ( in_array( 'administrator', $user['roles'], true ) ) {
				$critical[] = $user['login'];
			} else {
				$warnings[] = $user['login'];
			}
		}
		return array(
			'critical' => $critical,
			'warnings' => $warnings,
		);
	}

	/**
	 * Administrators whose login is shown as the public author name, and those whose author archive address is the login.
	 *
	 * @param list<array{id:int,login:string,display_name:string,nicename:string,email:string,roles:list<string>}> $admins Administrators.
	 * @return array{exposed:list<string>,archives:list<string>}
	 */
	private static function public_logins( array $admins ): array {
		$exposed  = array();
		$archives = array();
		foreach ( $admins as $admin ) {
			$login = strtolower( $admin['login'] );
			if ( '' === $login ) {
				continue;
			}
			if ( strtolower( $admin['display_name'] ) === $login ) {
				$exposed[] = $admin['login'];
			}
			if ( strtolower( $admin['nicename'] ) === $login ) {
				$archives[] = $admin['login'];
			}
		}
		return array(
			'exposed'  => $exposed,
			'archives' => $archives,
		);
	}

	/**
	 * Detail lines, one per finding.
	 *
	 * @param string[] $critical Logins of administrators named "admin" or "administrator".
	 * @param string[] $warnings Logins of other users named "admin" or "administrator".
	 * @param string[] $exposed  Logins shown as the public author name.
	 * @param string[] $archives Logins that are also the author archive address.
	 * @return list<string>
	 */
	private static function detail_lines( array $critical, array $warnings, array $exposed, array $archives ): array {
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
		foreach ( $archives as $login ) {
			/* translators: %s: login name. */
			$details[] = sprintf( __( 'The author archive address of "%s" shows the login. To hide it, change the user slug with a plugin that edits user slugs, or turn off author archives.', 'store-audit-for-woocommerce' ), $login );
		}
		return $details;
	}

	/**
	 * Details name accounts, files or keys, so exports do not carry them.
	 */
	public function is_sensitive(): bool {
		return true;
	}
}
