<?php
/**
 * Installed and active plugins, injectable for tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Plugins;

use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the plugin list from WordPress.
 */
final class PluginInventory {

	/**
	 * Installed plugins keyed by file.
	 *
	 * @var callable
	 */
	private $installed;

	/**
	 * Active plugin files.
	 *
	 * @var callable
	 */
	private $active;

	/**
	 * Constructor.
	 *
	 * @param callable|null $installed Returns array<string,array<string,mixed>> like get_plugins().
	 * @param callable|null $active    Returns list of active plugin files.
	 */
	public function __construct( ?callable $installed = null, ?callable $active = null ) {
		$this->installed = $installed ?? static function (): array {
			if ( ! function_exists( 'get_plugins' ) ) {
				throw new \RuntimeException( 'The WordPress plugin functions are not loaded.' );
			}
			return get_plugins();
		};
		$this->active    = $active ?? static function (): array {
			$files = Arr::to_string_list( get_option( 'active_plugins', array() ) );
			if ( is_multisite() ) {
				foreach ( array_keys( Arr::to_array( get_site_option( 'active_sitewide_plugins', array() ) ) ) as $sitewide ) {
					$files[] = (string) $sitewide;
				}
			}
			return array_values( array_unique( $files ) );
		};
	}

	/**
	 * Installed plugins keyed by file.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function installed(): array {
		$plugins = array();
		foreach ( Arr::to_array( ( $this->installed )() ) as $file => $data ) {
			if ( is_string( $file ) && is_array( $data ) ) {
				$plugins[ $file ] = Arr::to_string_keyed( $data );
			}
		}
		return $plugins;
	}

	/**
	 * Active plugin files.
	 *
	 * @return list<string>
	 */
	public function active(): array {
		return Arr::to_string_list( ( $this->active )() );
	}

	/**
	 * Display name of a plugin file.
	 *
	 * @param array<string,array<string,mixed>> $installed Installed plugins.
	 * @param string                            $file      Plugin file.
	 */
	public static function name_of( array $installed, string $file ): string {
		$name = $installed[ $file ]['Name'] ?? '';
		return is_string( $name ) && '' !== $name ? $name : $file;
	}
}
