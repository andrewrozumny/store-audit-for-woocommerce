<?php
/**
 * Check registry.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Checks\CheckInterface;
use StoreAudit\Checks\Section;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects checks from all section providers and from the `store_audit_checks` filter, and keeps only valid ones.
 */
final class Registry {

	/**
	 * All registered checks keyed by id, in the order they run.
	 *
	 * @return array<string,CheckInterface> Valid checks only; an empty array when the filter returns something that is not a list.
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
			if ( $check instanceof CheckInterface && self::accept( $check, $keyed ) ) {
				$keyed[ $check->id() ] = $check;
			}
		}
		return $keyed;
	}

	/**
	 * Whether a check may be registered. A check with an odd or repeated id, or in a section without a score
	 * weight, is dropped and the developer is told, so a typo shows up in debug mode instead of as a silent gap.
	 *
	 * @param CheckInterface               $check Check.
	 * @param array<string,CheckInterface> $keyed Checks accepted so far.
	 */
	private static function accept( CheckInterface $check, array $keyed ): bool {
		$id = $check->id();
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $id ) ) {
			self::reject(
				sprintf(
					/* translators: %s: check id. */
					__( 'The check id "%s" is not valid. Use lower-case letters a-z, digits 0-9 and underscores only. The check was not added.', 'store-audit-for-woocommerce' ),
					$id
				)
			);
			return false;
		}
		if ( isset( $keyed[ $id ] ) ) {
			self::reject(
				sprintf(
					/* translators: %s: check id. */
					__( 'A check with the id "%s" is already registered. The second check was not added.', 'store-audit-for-woocommerce' ),
					$id
				)
			);
			return false;
		}
		if ( ! array_key_exists( $check->section(), Section::weights() ) ) {
			self::reject(
				sprintf(
					/* translators: 1: check id, 2: section id. */
					__( 'The check "%1$s" uses the unknown section "%2$s". The check was not added.', 'store-audit-for-woocommerce' ),
					$id,
					$check->section()
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Tell the developer that a check was dropped.
	 *
	 * @param string $message Plain-text message; escaped here.
	 */
	private static function reject( string $message ): void {
		_doing_it_wrong( 'store_audit_checks', esc_html( $message ), '0.1.1' );
	}

	/**
	 * Run the checks through the public filter. Third-party code may return anything, so the result is untyped.
	 *
	 * @param array<int,CheckInterface> $checks Checks.
	 */
	private static function filter_checks( array $checks ): mixed {
		/**
		 * Filters the list of audit checks. Add a check by appending an object that implements CheckInterface.
		 * An item that is not such an object is skipped silently; a check with an invalid or repeated id, or in an
		 * unknown section, is skipped and reported with _doing_it_wrong().
		 *
		 * @since 0.1.0
		 *
		 * @param list<CheckInterface> $checks The registered checks, in the order they run.
		 * @return list<CheckInterface> The checks to run.
		 */
		return apply_filters( 'store_audit_checks', array_values( $checks ) );
	}

	/**
	 * Find a check by id.
	 *
	 * @param string $id Check id.
	 * @return CheckInterface|null The check, or null when no check has that id.
	 */
	public static function get( string $id ): ?CheckInterface {
		return self::all()[ $id ] ?? null;
	}
}
