<?php
/**
 * Inactive plugins check.
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
 * Installed but inactive plugins are unused code that still has to be kept updated.
 */
final class PluginsInactive extends AbstractCheck {

	/**
	 * Plugin inventory.
	 *
	 * @var PluginInventory
	 */
	private PluginInventory $inventory;

	/**
	 * Constructor.
	 *
	 * @param PluginInventory|null $inventory Inventory override for tests.
	 */
	public function __construct( ?PluginInventory $inventory = null ) {
		$this->inventory = $inventory ?? new PluginInventory();
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'plugins_inactive';
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
		return __( 'Inactive plugins', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Plugins that are installed but switched off are not used, yet their files stay on the server. Attackers can target known flaws in them, and nobody notices when they fall out of date.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Delete the plugins you do not use (Plugins, Installed Plugins, Delete). Keep a backup first.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$installed = $this->inventory->installed();
		$active    = $this->inventory->active();
		$names     = array();
		foreach ( array_keys( $installed ) as $file ) {
			if ( ! in_array( $file, $active, true ) ) {
				$names[] = PluginInventory::name_of( $installed, $file );
			}
		}
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );

		if ( array() === $names ) {
			return Result::ok( __( 'Every installed plugin is active.', 'store-audit-for-woocommerce' ) );
		}

		return Result::warning(
			sprintf(
				/* translators: %d: number of inactive plugins. */
				_n( '%d plugin is installed but inactive.', '%d plugins are installed but inactive.', count( $names ), 'store-audit-for-woocommerce' ),
				count( $names )
			),
			DetailList::cap( $names )
		);
	}
}
