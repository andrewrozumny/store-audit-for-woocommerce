<?php
/**
 * Slugs of the active plugins.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads which plugins are active on this site, including network-wide ones.
 */
final class ActivePlugins {

	/**
	 * Slugs (folder or file name without ".php") of the active plugins.
	 *
	 * @return list<string>
	 */
	public static function slugs(): array {
		$files = Arr::to_string_list( get_option( 'active_plugins', array() ) );
		if ( is_multisite() ) {
			foreach ( array_keys( Arr::to_array( get_site_option( 'active_sitewide_plugins', array() ) ) ) as $sitewide ) {
				$files[] = (string) $sitewide;
			}
		}
		return self::slugs_of( $files );
	}

	/**
	 * Slugs of plugin basenames such as "akismet/akismet.php" (gives "akismet") or "hello.php" (gives "hello").
	 *
	 * @param string[] $basenames Plugin basenames.
	 * @return list<string>
	 */
	public static function slugs_of( array $basenames ): array {
		$slugs = array();
		foreach ( $basenames as $basename ) {
			$first = explode( '/', $basename )[0];
			$slug  = preg_replace( '/\.php$/', '', $first );

			$slugs[ $slug ?? $first ] = true;
		}
		return array_map( 'strval', array_keys( $slugs ) );
	}
}
