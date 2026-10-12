<?php
/**
 * Check registry.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Checks\CheckInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects checks from all section providers.
 */
final class Registry {

	/**
	 * All registered checks keyed by id.
	 *
	 * @return array<string,CheckInterface>
	 */
	public static function all(): array {
		$checks = array_merge(
			Checks\Environment\Provider::checks(),
			Checks\Plugins\Provider::checks(),
			Checks\WooCommerce\Provider::checks(),
			Checks\Security\Provider::checks(),
			Checks\Performance\Provider::checks()
		);

		$checks = self::filter_checks( $checks );

		$keyed = array();
		if ( ! is_array( $checks ) ) {
			return $keyed;
		}
		foreach ( $checks as $check ) {
			if ( $check instanceof CheckInterface ) {
				$keyed[ $check->id() ] = $check;
			}
		}
		return $keyed;
	}

	/**
	 * Run the checks through the public filter. Third-party code may return anything, so the result is untyped.
	 *
	 * @param array<int,CheckInterface> $checks Checks.
	 */
	private static function filter_checks( array $checks ): mixed {
		/**
		 * Filters the list of audit checks.
		 *
		 * @param list<CheckInterface> $checks Checks.
		 */
		return apply_filters( 'store_audit_checks', array_values( $checks ) );
	}

	/**
	 * Find a check by id.
	 *
	 * @param string $id Check id.
	 */
	public static function get( string $id ): ?CheckInterface {
		return self::all()[ $id ] ?? null;
	}
}
