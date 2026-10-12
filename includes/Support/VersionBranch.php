<?php
/**
 * Release branch of a version number.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reduces a version to its release branch, so a minor update can be told from a new release.
 */
final class VersionBranch {

	/**
	 * Major and minor part of a version: "6.8.2" gives "6.8", "7" gives "7.0".
	 *
	 * @param string $version Version number.
	 */
	public static function of( string $version ): string {
		$parts = explode( '.', $version );
		return $parts[0] . '.' . ( $parts[1] ?? '0' );
	}
}
