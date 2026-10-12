<?php
/**
 * Abandoned plugins check.
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
 * Plugins nobody maintains any more, or that wordpress.org closed.
 */
final class PluginsAbandoned extends AbstractCheck {

	/**
	 * Maximum plugins looked up per run.
	 */
	public const MAX_PLUGINS = 40;

	/**
	 * Time budget in seconds for the whole check.
	 */
	public const BUDGET_SECONDS = 15.0;

	/**
	 * Plugin inventory.
	 *
	 * @var PluginInventory
	 */
	private PluginInventory $inventory;

	/**
	 * Wordpress.org client.
	 *
	 * @var WporgApi
	 */
	private WporgApi $api;

	/**
	 * Clock returning unix time with microseconds.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Current unix time (for age comparison).
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Constructor.
	 *
	 * @param PluginInventory|null $inventory Inventory override for tests.
	 * @param WporgApi|null        $api       API client override for tests.
	 * @param callable|null        $clock     Monotonic seconds clock override for tests.
	 * @param int|null             $now       Current unix time override for tests.
	 */
	public function __construct( ?PluginInventory $inventory = null, ?WporgApi $api = null, ?callable $clock = null, ?int $now = null ) {
		$this->inventory = $inventory ?? new PluginInventory();
		$this->api       = $api ?? new WporgApi();
		$this->clock     = $clock ?? static fn(): float => microtime( true );
		$this->now       = $now ?? time();
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'plugins_abandoned';
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
		return __( 'Abandoned plugins', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'A plugin that has not been updated for over a year probably has no developer looking after it, so new security flaws stay unfixed. Plugins closed on wordpress.org are often closed because of a security problem.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Replace abandoned plugins with maintained alternatives, and remove closed ones right away.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$installed = $this->inventory->installed();
		$start     = ( $this->clock )();
		$cutoff    = strtotime( '-12 months', $this->now );

		$closed      = array();
		$abandoned   = array();
		$unchecked   = array();
		$unavailable = 0;
		$skipped     = 0;
		$looked_up   = 0;

		foreach ( array_keys( $installed ) as $file ) {
			$name = PluginInventory::name_of( $installed, $file );
			if ( $looked_up >= self::MAX_PLUGINS || ( ( $this->clock )() - $start ) > self::BUDGET_SECONDS ) {
				++$skipped;
				continue;
			}
			++$looked_up;

			$info = $this->api->plugin( WporgApi::slug_from_file( $file ) );
			switch ( $info['status'] ) {
				case WporgApi::CLOSED:
					$closed[] = '' !== $info['reason']
						? sprintf(
							/* translators: 1: plugin name, 2: reason text. */
							__( '%1$s: closed on wordpress.org (%2$s)', 'store-audit-for-woocommerce' ),
							$name,
							$info['reason']
						)
						: sprintf(
							/* translators: %s: plugin name. */
							__( '%s: closed on wordpress.org', 'store-audit-for-woocommerce' ),
							$name
						);
					break;
				case WporgApi::FOUND:
					if ( $info['last_updated'] > 0 && $info['last_updated'] < $cutoff ) {
						$abandoned[] = sprintf(
							/* translators: 1: plugin name, 2: date of last update. */
							__( '%1$s: last updated %2$s', 'store-audit-for-woocommerce' ),
							$name,
							gmdate( 'j M Y', $info['last_updated'] )
						);
					}
					break;
				case WporgApi::NOT_FOUND:
					$unchecked[] = sprintf(
						/* translators: %s: plugin name. */
						__( '%s: not checked: not hosted on wordpress.org', 'store-audit-for-woocommerce' ),
						$name
					);
					break;
				default:
					++$unavailable;
			}
		}

		$notes = $unchecked;
		if ( $skipped > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of plugins. */
				_n( '%d plugin not checked (time limit)', '%d plugins not checked (time limit)', $skipped, 'store-audit-for-woocommerce' ),
				$skipped
			);
		}
		if ( $unavailable > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of plugins. */
				_n( '%d plugin not checked (wordpress.org did not answer)', '%d plugins not checked (wordpress.org did not answer)', $unavailable, 'store-audit-for-woocommerce' ),
				$unavailable
			);
		}

		if ( array() !== $closed ) {
			return Result::critical(
				sprintf(
					/* translators: %d: number of plugins. */
					_n( '%d plugin was closed on wordpress.org.', '%d plugins were closed on wordpress.org.', count( $closed ), 'store-audit-for-woocommerce' ),
					count( $closed )
				),
				DetailList::cap( array_merge( $closed, $abandoned, $notes ) )
			);
		}

		if ( array() !== $abandoned ) {
			return Result::warning(
				sprintf(
					/* translators: %d: number of plugins. */
					_n( '%d plugin has not been updated for over a year.', '%d plugins have not been updated for over a year.', count( $abandoned ), 'store-audit-for-woocommerce' ),
					count( $abandoned )
				),
				DetailList::cap( array_merge( $abandoned, $notes ) )
			);
		}

		if ( array() !== $notes ) {
			return Result::info(
				__( 'No abandoned plugins found, but some plugins could not be checked.', 'store-audit-for-woocommerce' ),
				DetailList::cap( $notes )
			);
		}

		return Result::ok( __( 'All plugins were updated within the last year.', 'store-audit-for-woocommerce' ) );
	}
}
