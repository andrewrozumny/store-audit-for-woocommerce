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
					esc_html( $id )
				)
			);
			return false;
		}
		if ( isset( $keyed[ $id ] ) ) {
			self::reject(
				sprintf(
					/* translators: %s: check id. */
					__( 'A check with the id "%s" is already registered. The second check was not added.', 'store-audit-for-woocommerce' ),
					esc_html( $id )
				)
			);
			return false;
		}
		if ( ! array_key_exists( $check->section(), Section::weights() ) ) {
			self::reject(
				sprintf(
					/* translators: 1: check id, 2: section id. */
					__( 'The check "%1$s" uses the unknown section "%2$s". The check was not added.', 'store-audit-for-woocommerce' ),
					esc_html( $id ),
					esc_html( $check->section() )
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Tell the developer that a check was dropped.
	 *
	 * @param string $message Already escaped message.
	 */
	private static function reject( string $message ): void {
		_doing_it_wrong( 'store_audit_checks', $message, '0.1.1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Dynamic parts are escaped above.
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
