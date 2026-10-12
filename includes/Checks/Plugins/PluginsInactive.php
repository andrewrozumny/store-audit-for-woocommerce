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
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installed but inactive plugins are unused code that still has to be kept updated.
 */
final class PluginsInactive extends AbstractCheck {

	/**
	 * Networks with more sites than this are not walked.
	 */
	private const MAX_SITES = 500;

	/**
	 * Sites read per query.
	 */
	private const BATCH = 100;

	/**
	 * Plugin inventory.
	 *
	 * @var PluginInventory
	 */
	private PluginInventory $inventory;

	/**
	 * Network reader: null on a single site, else array{too_large:bool,active:list<string>} with the plugins active
	 * on any site of the network.
	 *
	 * @var callable
	 */
	private $network;

	/**
	 * Constructor.
	 *
	 * @param PluginInventory|null $inventory Inventory override for tests.
	 * @param callable|null        $network   Network reader override for tests.
	 */
	public function __construct( ?PluginInventory $inventory = null, ?callable $network = null ) {
		$this->inventory = $inventory ?? new PluginInventory();
		$this->network   = $network ?? array( $this, 'read_network' );
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
		$network   = ( $this->network )();
		$too_large = false;
		if ( is_array( $network ) ) {
			$too_large = Arr::get_bool( $network, 'too_large' );
			$active    = array_merge( $active, Arr::to_string_list( Arr::get_array( $network, 'active' ) ) );
		}
		$names = array();
		foreach ( array_keys( $installed ) as $file ) {
			if ( ! in_array( $file, $active, true ) ) {
				$names[] = PluginInventory::name_of( $installed, $file );
			}
		}
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );

		if ( array() === $names ) {
			return Result::ok( __( 'Every installed plugin is active.', 'store-audit-for-woocommerce' ) );
		}

		$summary = sprintf(
			/* translators: %d: number of inactive plugins. */
			_n( '%d plugin is installed but inactive.', '%d plugins are installed but inactive.', count( $names ), 'store-audit-for-woocommerce' ),
			count( $names )
		);
		if ( $too_large ) {
			return Result::info(
				$summary . ' ' . __( 'On a large network this check only looks at this site.', 'store-audit-for-woocommerce' ),
				DetailList::cap( $names ),
				__( 'Ask the network admin which of these plugins other sites use before removing anything.', 'store-audit-for-woocommerce' )
			);
		}
		return Result::warning(
			$summary,
			DetailList::cap( $names ),
			is_array( $network ) ? __( 'Ask the network admin before deleting: another site of the network may still use a plugin.', 'store-audit-for-woocommerce' ) : ''
		);
	}

	/**
	 * Plugins active on any site of the network, read in batches of 100 sites. Null on a single site.
	 *
	 * @return array{too_large:bool,active:list<string>}|null
	 */
	private function read_network(): ?array {
		if ( ! is_multisite() ) {
			return null;
		}
		$total = Arr::to_int(
			get_sites(
				array(
					'count'  => true,
					'number' => 0,
				)
			)
		);
		if ( $total > self::MAX_SITES ) {
			return array(
				'too_large' => true,
				'active'    => array(),
			);
		}

		$active = array();
		for ( $offset = 0; $offset < $total; $offset += self::BATCH ) {
			$ids = get_sites(
				array(
					'number' => self::BATCH,
					'offset' => $offset,
					'fields' => 'ids',
				)
			);
			foreach ( Arr::to_array( $ids ) as $id ) {
				foreach ( Arr::to_string_list( get_blog_option( Arr::to_int( $id ), 'active_plugins', array() ) ) as $file ) {
					$active[ $file ] = true;
				}
			}
		}
		return array(
			'too_large' => false,
			'active'    => array_map( 'strval', array_keys( $active ) ),
		);
	}
}
