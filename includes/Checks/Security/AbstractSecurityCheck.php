<?php
/**
 * Base class for security checks.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Plugins\DetailList;
use StoreAudit\Checks\Section;
use StoreAudit\Support\Arr;

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
		return DetailList::cap( $items, $max );
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
			if ( $user instanceof \WP_User ) {
				$list[] = $this->normalise_user( $user );
			}
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
			'id'           => $user->ID,
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
			'nicename'     => $user->user_nicename,
			'email'        => $user->user_email,
			'roles'        => array_values( $user->roles ),
		);
	}

	/**
	 * Administrator rows from an injected source, checked and completed with empty defaults.
	 *
	 * @param mixed $rows Rows returned by the source.
	 * @return list<array{id:int,login:string,display_name:string,nicename:string,email:string,roles:list<string>}>
	 */
	protected function normalise_admins( mixed $rows ): array {
		$list = array();
		foreach ( Arr::to_array( $rows ) as $row ) {
			$list[] = $this->normalise_admin_row( $row );
		}
		return $list;
	}

	/**
	 * One user row, checked and completed with empty defaults.
	 *
	 * @param mixed $row Row.
	 * @return array{id:int,login:string,display_name:string,nicename:string,email:string,roles:list<string>}
	 */
	protected function normalise_admin_row( mixed $row ): array {
		$row = Arr::to_string_keyed( $row );
		return array(
			'id'           => Arr::get_int( $row, 'id' ),
			'login'        => Arr::get_string( $row, 'login' ),
			'display_name' => Arr::get_string( $row, 'display_name' ),
			'nicename'     => Arr::get_string( $row, 'nicename' ),
			'email'        => Arr::get_string( $row, 'email' ),
			'roles'        => Arr::to_string_list( Arr::get_array( $row, 'roles' ) ),
		);
	}
}
