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
use StoreAudit\Support\Arr;
use StoreAudit\Support\DetailList;

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
	 * Slug of this plugin, never reported as "not hosted on wordpress.org".
	 */
	public const SELF_SLUG = 'store-audit-for-woocommerce';

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
	 * @var \Closure
	 */
	private \Closure $clock;

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
		$this->clock     = \Closure::fromCallable( $clock ?? static fn(): float => microtime( true ) );
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
		return __( 'A plugin that has not been updated for over a year probably has no developer looking after it, so new security flaws stay unfixed. Plugins closed on wordpress.org for a security problem are dangerous to keep.', 'store-audit-for-woocommerce' );
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
		return self::verdict( $this->scan( $this->inventory->installed() ) );
	}

	/**
	 * Look every installed plugin up on wordpress.org, within the plugin and time limits.
	 *
	 * @param array<string,array<string,mixed>> $installed Installed plugins keyed by file.
	 * @return array{closed:list<string>,closed_sec:list<string>,abandoned:list<string>,unchecked:list<string>,unavailable:int,skipped:int}
	 */
	private function scan( array $installed ): array {
		$start  = Arr::to_float( ( $this->clock )() );
		$cutoff = strtotime( '-12 months', $this->now );
		$tally  = array(
			'closed'      => array(),
			'closed_sec'  => array(),
			'abandoned'   => array(),
			'unchecked'   => array(),
			'unavailable' => 0,
			'skipped'     => 0,
		);

		$looked_up = 0;
		foreach ( array_keys( $installed ) as $file ) {
			if ( self::SELF_SLUG === WporgApi::slug_from_file( $file ) ) {
				continue;
			}
			$name = PluginInventory::name_of( $installed, $file );
			$host = self::foreign_update_host( Arr::get_string( $installed[ $file ], 'UpdateURI' ) );
			if ( '' !== $host ) {
				$tally['unchecked'][] = sprintf(
					/* translators: 1: plugin name, 2: host name that serves the updates. */
					__( '%1$s: not checked, updates come from %2$s', 'store-audit-for-woocommerce' ),
					$name,
					$host
				);
				continue;
			}
			if ( $looked_up >= self::MAX_PLUGINS || ( Arr::to_float( ( $this->clock )() ) - $start ) > self::BUDGET_SECONDS ) {
				++$tally['skipped'];
				continue;
			}
			++$looked_up;

			$tally = self::record( $tally, $name, $this->look_up( $installed[ $file ], $file ), $cutoff );
		}
		return $tally;
	}

	/**
	 * What wordpress.org says about one installed plugin.
	 *
	 * @param array<string,mixed> $headers Plugin headers.
	 * @param string              $file    Plugin file.
	 * @return array{status:string,version:string,last_updated:int,reason:string,name:string,security:bool}
	 */
	private function look_up( array $headers, string $file ): array {
		$info = $this->api->plugin( WporgApi::slug_from_file( $file ) );
		if ( in_array( $info['status'], array( WporgApi::FOUND, WporgApi::CLOSED ), true ) && ! self::same_plugin( Arr::get_string( $headers, 'Name' ), $info['name'] ) ) {
			// Someone else's plugin has this slug on wordpress.org, so its dates and its closure say nothing about this one.
			$info['status'] = WporgApi::NOT_FOUND;
		}
		return $info;
	}

	/**
	 * Add one lookup result to the tally.
	 *
	 * @param array{closed:list<string>,closed_sec:list<string>,abandoned:list<string>,unchecked:list<string>,unavailable:int,skipped:int} $tally  Tally so far.
	 * @param string                                                                                                                       $name   Plugin name.
	 * @param array{status:string,version:string,last_updated:int,reason:string,name:string,security:bool}                                 $info   Lookup result.
	 * @param int|false                                                                                                                    $cutoff Unix time before which a plugin counts as abandoned.
	 * @return array{closed:list<string>,closed_sec:list<string>,abandoned:list<string>,unchecked:list<string>,unavailable:int,skipped:int}
	 */
	private static function record( array $tally, string $name, array $info, int|false $cutoff ): array {
		switch ( $info['status'] ) {
			case WporgApi::CLOSED:
				$tally[ $info['security'] ? 'closed_sec' : 'closed' ][] = self::closed_line( $name, $info['reason'] );
				break;
			case WporgApi::FOUND:
				if ( $info['last_updated'] > 0 && $info['last_updated'] < $cutoff ) {
					$tally['abandoned'][] = sprintf(
						/* translators: 1: plugin name, 2: date of last update. */
						__( '%1$s: last updated %2$s', 'store-audit-for-woocommerce' ),
						$name,
						gmdate( 'j M Y', $info['last_updated'] )
					);
				}
				break;
			case WporgApi::NOT_FOUND:
				$tally['unchecked'][] = sprintf(
					/* translators: %s: plugin name. */
					__( '%s: not checked, it is not hosted on wordpress.org', 'store-audit-for-woocommerce' ),
					$name
				);
				break;
			default:
				++$tally['unavailable'];
		}
		return $tally;
	}

	/**
	 * Detail line for a plugin closed on wordpress.org.
	 *
	 * @param string $name   Plugin name.
	 * @param string $reason Closure reason, may be empty.
	 */
	private static function closed_line( string $name, string $reason ): string {
		if ( '' === $reason ) {
			return sprintf(
				/* translators: %s: plugin name. */
				__( '%s: closed on wordpress.org', 'store-audit-for-woocommerce' ),
				$name
			);
		}
		return sprintf(
			/* translators: 1: plugin name, 2: reason text. */
			__( '%1$s: closed on wordpress.org (%2$s)', 'store-audit-for-woocommerce' ),
			$name,
			$reason
		);
	}

	/**
	 * Result from the tally: security closures are critical, other closures and old plugins warnings.
	 *
	 * @param array{closed:list<string>,closed_sec:list<string>,abandoned:list<string>,unchecked:list<string>,unavailable:int,skipped:int} $tally Tally of the scan.
	 */
	private static function verdict( array $tally ): Result {
		$closed_sec = $tally['closed_sec'];
		$closed     = $tally['closed'];
		$abandoned  = $tally['abandoned'];
		$notes      = self::notes( $tally );

		if ( array() !== $closed_sec ) {
			return Result::critical(
				sprintf(
					/* translators: %d: number of plugins. */
					_n( '%d plugin was closed on wordpress.org because of a security problem.', '%d plugins were closed on wordpress.org because of a security problem.', count( $closed_sec ), 'store-audit-for-woocommerce' ),
					count( $closed_sec )
				),
				DetailList::cap( array_merge( $closed_sec, $closed, $abandoned, $notes ) )
			);
		}

		if ( array() !== $closed ) {
			return Result::warning(
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

	/**
	 * Notes about plugins that could not be checked, including the time-limit and unreachable counts.
	 *
	 * @param array{closed:list<string>,closed_sec:list<string>,abandoned:list<string>,unchecked:list<string>,unavailable:int,skipped:int} $tally Tally of the scan.
	 * @return list<string>
	 */
	private static function notes( array $tally ): array {
		$notes = $tally['unchecked'];
		if ( $tally['skipped'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of plugins. */
				_n( '%d plugin not checked (time limit)', '%d plugins not checked (time limit)', $tally['skipped'], 'store-audit-for-woocommerce' ),
				$tally['skipped']
			);
		}
		if ( $tally['unavailable'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of plugins. */
				_n( '%d plugin not checked (wordpress.org did not answer)', '%d plugins not checked (wordpress.org did not answer)', $tally['unavailable'], 'store-audit-for-woocommerce' ),
				$tally['unavailable']
			);
		}
		return $notes;
	}

	/**
	 * Host that serves the updates of a plugin whose "Update URI" header points away from wordpress.org, else an
	 * empty string. A value that is not an address ("false" switches updates off) is returned as written.
	 *
	 * @param string $update_uri Value of the Update URI header.
	 */
	private static function foreign_update_host( string $update_uri ): string {
		$update_uri = trim( $update_uri );
		if ( '' === $update_uri ) {
			return '';
		}
		$host = wp_parse_url( $update_uri, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return $update_uri;
		}
		$host = strtolower( $host );
		return ( 'wordpress.org' === $host || 'w.org' === $host || str_ends_with( $host, '.wordpress.org' ) || str_ends_with( $host, '.w.org' ) ) ? '' : $host;
	}

	/**
	 * Whether the name in the plugin header and the name wordpress.org gives the slug are the same plugin: equal
	 * ignoring case and punctuation, or one contains the other. An unknown name counts as a match.
	 *
	 * @param string $header_name Name header of the installed plugin.
	 * @param string $api_name    Name wordpress.org reports.
	 */
	private static function same_plugin( string $header_name, string $api_name ): bool {
		$local  = self::normalise( $header_name );
		$remote = self::normalise( $api_name );
		if ( '' === $local || '' === $remote ) {
			return true;
		}
		return str_contains( $local, $remote ) || str_contains( $remote, $local );
	}

	/**
	 * Lower-case letters and digits only.
	 *
	 * @param string $name Name.
	 */
	private static function normalise( string $name ): string {
		return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) );
	}
}
