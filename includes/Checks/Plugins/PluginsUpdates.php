<?php
/**
 * Available updates check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Plugins;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\DetailList;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pending plugin and theme updates.
 */
final class PluginsUpdates extends AbstractCheck {

	/**
	 * Returns array{plugins:array<string,array{name:string,current:string,new:string}>,themes:array<string,array{name:string,current:string,new:string}>}.
	 *
	 * @var \Closure
	 */
	private \Closure $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = \Closure::fromCallable( $source ?? array( $this, 'read_updates' ) );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'plugins_updates';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::PLUGINS;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Plugin and theme updates', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Updates often contain security fixes. A store running old versions can be hacked through flaws that are already public, which means lost orders and customer data.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Back up the site, then update on the Dashboard, Updates screen (test big updates on a staging copy first).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$data    = Arr::to_array( ( $this->source )() );
		$plugins = Arr::get_array( $data, 'plugins' );
		$themes  = Arr::get_array( $data, 'themes' );

		$lines = array();
		foreach ( $plugins as $raw_row ) {
			$row     = Arr::to_string_keyed( $raw_row );
			$lines[] = sprintf(
				/* translators: 1: plugin name, 2: installed version, 3: new version. */
				__( '%1$s: %2$s -> %3$s', 'store-audit-for-woocommerce' ),
				Arr::get_string( $row, 'name' ),
				Arr::get_string( $row, 'current' ),
				Arr::get_string( $row, 'new' )
			);
		}
		foreach ( $themes as $raw_row ) {
			$row     = Arr::to_string_keyed( $raw_row );
			$lines[] = sprintf(
				/* translators: 1: theme name, 2: installed version, 3: new version. */
				__( 'Theme %1$s: %2$s -> %3$s', 'store-audit-for-woocommerce' ),
				Arr::get_string( $row, 'name' ),
				Arr::get_string( $row, 'current' ),
				Arr::get_string( $row, 'new' )
			);
		}

		if ( array() === $lines ) {
			return Result::ok( __( 'All plugins and themes are up to date.', 'store-audit-for-woocommerce' ) );
		}

		return Result::warning( self::summary( count( $plugins ), count( $themes ) ), DetailList::cap( $lines ) );
	}

	/**
	 * Summary sentence for the waiting updates.
	 *
	 * @param int $plugin_count Plugin updates waiting.
	 * @param int $theme_count  Theme updates waiting.
	 */
	private static function summary( int $plugin_count, int $theme_count ): string {
		if ( 0 === $theme_count ) {
			return sprintf(
				/* translators: %d: number of plugin updates. */
				_n( '%d plugin update is waiting.', '%d plugin updates are waiting.', $plugin_count, 'store-audit-for-woocommerce' ),
				$plugin_count
			);
		}
		if ( 0 === $plugin_count ) {
			return sprintf(
				/* translators: %d: number of theme updates. */
				_n( '%d theme update is waiting.', '%d theme updates are waiting.', $theme_count, 'store-audit-for-woocommerce' ),
				$theme_count
			);
		}
		return sprintf(
			/* translators: 1: plugin updates, for example "2 plugin updates", 2: theme updates, for example "1 theme update". */
			__( '%1$s and %2$s are waiting.', 'store-audit-for-woocommerce' ),
			sprintf(
				/* translators: %d: number of plugin updates. */
				_n( '%d plugin update', '%d plugin updates', $plugin_count, 'store-audit-for-woocommerce' ),
				$plugin_count
			),
			sprintf(
				/* translators: %d: number of theme updates. */
				_n( '%d theme update', '%d theme updates', $theme_count, 'store-audit-for-woocommerce' ),
				$theme_count
			)
		);
	}

	/**
	 * Read the update data WordPress has already stored (no remote call).
	 *
	 * @return array{plugins:array<string,array{name:string,current:string,new:string}>,themes:array<string,array{name:string,current:string,new:string}>}
	 */
	private function read_updates(): array {
		$out = array(
			'plugins' => array(),
			'themes'  => array(),
		);

		$inventory = new PluginInventory();
		$installed = $inventory->installed();
		$transient = get_site_transient( 'update_plugins' );
		if ( is_object( $transient ) && is_array( $transient->response ?? null ) && array() !== $transient->response ) {
			foreach ( $transient->response as $file => $update ) {
				$file                    = (string) $file;
				$out['plugins'][ $file ] = array(
					'name'    => PluginInventory::name_of( $installed, $file ),
					'current' => isset( $installed[ $file ]['Version'] ) ? Arr::to_string( $installed[ $file ]['Version'] ) : '?',
					'new'     => is_object( $update ) && isset( $update->new_version ) ? Arr::to_string( $update->new_version ) : '?',
				);
			}
		}

		$transient = get_site_transient( 'update_themes' );
		if ( is_object( $transient ) && is_array( $transient->response ?? null ) && array() !== $transient->response ) {
			foreach ( $transient->response as $slug => $update ) {
				$slug                   = (string) $slug;
				$theme                  = wp_get_theme( $slug );
				$out['themes'][ $slug ] = array(
					'name'    => $theme->exists() ? $theme->get( 'Name' ) : $slug,
					'current' => $theme->exists() ? $theme->get( 'Version' ) : '?',
					'new'     => is_array( $update ) && isset( $update['new_version'] ) ? Arr::to_string( $update['new_version'] ) : '?',
				);
			}
		}

		return $out;
	}
}
