<?php
/**
 * Installed and active plugins, injectable for tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Plugins;

use StoreAudit\Support\ActivePlugins;
use StoreAudit\Support\Arr;
use StoreAudit\Support\NotCheckedException;

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
	 * @var \Closure
	 */
	private \Closure $installed;

	/**
	 * Active plugin files.
	 *
	 * @var \Closure
	 */
	private \Closure $active;

	/**
	 * Constructor.
	 *
	 * @param callable|null $installed Returns array<string,array<string,mixed>> like get_plugins().
	 * @param callable|null $active    Returns list of active plugin files.
	 */
	public function __construct( ?callable $installed = null, ?callable $active = null ) {
		$installed     ??= static function (): array {
			if ( ! function_exists( 'get_plugins' ) ) {
				throw new NotCheckedException( esc_html__( 'the WordPress plugin functions are not loaded.', 'store-audit-for-woocommerce' ) );
			}
			return get_plugins();
		};
		$this->installed = \Closure::fromCallable( $installed );
		$active        ??= static fn(): array => ActivePlugins::files();
		$this->active    = \Closure::fromCallable( $active );
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
