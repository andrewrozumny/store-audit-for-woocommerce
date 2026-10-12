<?php
/**
 * Webhooks check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Support\DetailList;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhooks that are paused, disabled or failing stop other systems from hearing about orders.
 */
final class Webhooks extends AbstractCheck {

	/**
	 * Returns list<array{name:string,status:string,failures:int}> of problem webhooks only.
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
		$this->source = \Closure::fromCallable( $source ?? array( $this, 'read_webhooks' ) );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_webhooks';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::WOOCOMMERCE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Webhooks', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Webhooks send order events to other systems (accounting, shipping, CRM). When one is disabled, paused or failing, those systems silently stop receiving orders.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'In WooCommerce, Settings, Advanced, Webhooks, check the delivery logs, fix the receiving address, then set the status back to Active.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$rows = Arr::to_array( ( $this->source )() );
		if ( array() === $rows ) {
			return Result::ok( __( 'No webhook is disabled, paused or failing.', 'store-audit-for-woocommerce' ) );
		}

		$lines = array();
		foreach ( $rows as $raw_row ) {
			$row     = Arr::to_string_keyed( $raw_row );
			$lines[] = sprintf(
				/* translators: 1: webhook name, 2: status, 3: failed deliveries. */
				_n( '%1$s: status %2$s, %3$d failed delivery', '%1$s: status %2$s, %3$d failed deliveries', Arr::get_int( $row, 'failures' ), 'store-audit-for-woocommerce' ),
				Arr::get_string( $row, 'name' ),
				Arr::get_string( $row, 'status' ),
				Arr::get_int( $row, 'failures' )
			);
		}

		return Result::warning(
			sprintf(
				/* translators: %d: number of webhooks. */
				_n( '%d webhook needs attention.', '%d webhooks need attention.', count( $rows ), 'store-audit-for-woocommerce' ),
				count( $rows )
			),
			DetailList::cap( $lines )
		);
	}

	/**
	 * Read problem webhooks from the WooCommerce table (small table, LIMIT 100).
	 *
	 * @return list<array{name:string,status:string,failures:int}>
	 */
	private function read_webhooks(): array {
		$db    = Db::wpdb();
		$table = $db->prefix . 'wc_webhooks';
		if ( ! Db::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only audit query on a small table; WooCommerce has no API for it.
		$found = $db->get_results( $db->prepare( "SELECT name, status, failure_count FROM %i WHERE status <> 'active' OR failure_count > 0 ORDER BY webhook_id LIMIT 100", $table ), ARRAY_A );
		$out   = array();
		foreach ( Arr::to_array( $found ) as $row ) {
			$row   = Arr::to_string_keyed( $row );
			$out[] = array(
				'name'     => Arr::get_string( $row, 'name' ),
				'status'   => Arr::get_string( $row, 'status' ),
				'failures' => Arr::get_int( $row, 'failure_count' ),
			);
		}
		return $out;
	}
}
