<?php
/**
 * Base class for security checks.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for the Security section.
 */
abstract class AbstractSecurityCheck extends AbstractCheck {

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::SECURITY;
	}

	/**
	 * Cap a list at $max lines and append "and N more".
	 *
	 * @param string[] $items Items.
	 * @param int      $max   Maximum lines.
	 * @return list<string>
	 */
	protected function cap_list( array $items, int $max = 20 ): array {
		$items = array_values( $items );
		if ( count( $items ) <= $max ) {
			return $items;
		}
		$more  = count( $items ) - $max;
		$items = array_slice( $items, 0, $max );
		/* translators: %d: number of items not shown. */
		$items[] = sprintf( __( 'and %d more', 'store-audit-for-woocommerce' ), $more );
		return $items;
	}

	/**
	 * Administrator accounts as plain arrays.
	 *
	 * @return list<array{id:int,login:string,display_name:string,nicename:string,email:string,roles:list<string>}>
	 */
	protected function default_administrators(): array {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 200,
			)
		);
		$list  = array();
		foreach ( $users as $user ) {
			$list[] = $this->normalise_user( $user );
		}
		return $list;
	}

	/**
	 * Look a user up by login.
	 *
	 * @param string $login Login.
	 * @return array{id:int,login:string,display_name:string,nicename:string,email:string,roles:list<string>}|null
	 */
	protected function default_user_by_login( string $login ): ?array {
		$user = get_user_by( 'login', $login );
		return $user instanceof \WP_User ? $this->normalise_user( $user ) : null;
	}

	/**
	 * Convert a WP_User into a plain array.
	 *
	 * @param \WP_User $user User.
	 * @return array{id:int,login:string,display_name:string,nicename:string,email:string,roles:list<string>}
	 */
	private function normalise_user( \WP_User $user ): array {
		return array(
			'id'           => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'display_name' => (string) $user->display_name,
			'nicename'     => (string) $user->user_nicename,
			'email'        => (string) $user->user_email,
			'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
		);
	}
}
