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
	 * Basenames of the plugins active on this site only ("akismet/akismet.php").
	 *
	 * @return list<string>
	 */
	public static function site_files(): array {
		return Arr::to_string_list( get_option( 'active_plugins', array() ) );
	}

	/**
	 * Basenames of the plugins activated for the whole network; empty on a single site.
	 *
	 * @return list<string>
	 */
	public static function network_files(): array {
		if ( ! is_multisite() ) {
			return array();
		}
		$files = array();
		foreach ( array_keys( Arr::to_array( get_site_option( 'active_sitewide_plugins', array() ) ) ) as $sitewide ) {
			$files[] = (string) $sitewide;
		}
		return $files;
	}

	/**
	 * Basenames of every active plugin, site and network, each once. This is the one place that reads the active plugin options.
	 *
	 * @return list<string>
	 */
	public static function files(): array {
		return array_values( array_unique( array_merge( self::site_files(), self::network_files() ) ) );
	}

	/**
	 * Whether a plugin is active on this site or network-wide.
	 *
	 * @param string $basename Plugin basename such as "woocommerce/woocommerce.php".
	 */
	public static function is_active( string $basename ): bool {
		return in_array( $basename, self::files(), true );
	}

	/**
	 * Slugs (folder or file name without ".php") of the active plugins.
	 *
	 * @return list<string>
	 */
	public static function slugs(): array {
		return self::slugs_of( self::files() );
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
