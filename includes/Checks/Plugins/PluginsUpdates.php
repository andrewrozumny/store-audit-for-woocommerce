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
	 * @var callable
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? array( $this, 'read_updates' );
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
		$data    = ( $this->source )();
		$plugins = $data['plugins'];
		$themes  = $data['themes'];

		$lines = array();
		foreach ( $plugins as $row ) {
			$lines[] = sprintf(
				/* translators: 1: plugin name, 2: installed version, 3: new version. */
				__( '%1$s: %2$s -> %3$s', 'store-audit-for-woocommerce' ),
				$row['name'],
				$row['current'],
				$row['new']
			);
		}
		foreach ( $themes as $row ) {
			$lines[] = sprintf(
				/* translators: 1: theme name, 2: installed version, 3: new version. */
				__( 'Theme %1$s: %2$s -> %3$s', 'store-audit-for-woocommerce' ),
				$row['name'],
				$row['current'],
				$row['new']
			);
		}

		if ( array() === $lines ) {
			return Result::ok( __( 'All plugins and themes are up to date.', 'store-audit-for-woocommerce' ) );
		}

		return Result::warning(
			sprintf(
				/* translators: 1: number of plugin updates, 2: number of theme updates. */
				__( '%1$d plugin updates and %2$d theme updates are waiting.', 'store-audit-for-woocommerce' ),
				count( $plugins ),
				count( $themes )
			),
			DetailList::cap( $lines )
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
		if ( is_object( $transient ) && ! empty( $transient->response ) && is_array( $transient->response ) ) {
			foreach ( $transient->response as $file => $update ) {
				$file                    = (string) $file;
				$out['plugins'][ $file ] = array(
					'name'    => PluginInventory::name_of( $installed, $file ),
					'current' => isset( $installed[ $file ]['Version'] ) ? (string) $installed[ $file ]['Version'] : '?',
					'new'     => is_object( $update ) && isset( $update->new_version ) ? (string) $update->new_version : '?',
				);
			}
		}

		$transient = get_site_transient( 'update_themes' );
		if ( is_object( $transient ) && ! empty( $transient->response ) && is_array( $transient->response ) ) {
			foreach ( $transient->response as $slug => $update ) {
				$slug                   = (string) $slug;
				$theme                  = wp_get_theme( $slug );
				$out['themes'][ $slug ] = array(
					'name'    => $theme->exists() ? (string) $theme->get( 'Name' ) : $slug,
					'current' => $theme->exists() ? (string) $theme->get( 'Version' ) : '?',
					'new'     => is_array( $update ) && isset( $update['new_version'] ) ? (string) $update['new_version'] : '?',
				);
			}
		}

		return $out;
	}
}
