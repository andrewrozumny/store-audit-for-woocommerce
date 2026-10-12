<?php
/**
 * Webhooks check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Plugins\DetailList;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

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
	 * @var callable
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? array( $this, 'read_webhooks' );
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
		$rows = ( $this->source )();
		if ( array() === $rows ) {
			return Result::ok( __( 'No webhook is disabled, paused or failing.', 'store-audit-for-woocommerce' ) );
		}

		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = sprintf(
				/* translators: 1: webhook name, 2: status, 3: failed deliveries. */
				_n( '%1$s: status %2$s, %3$d failed delivery', '%1$s: status %2$s, %3$d failed deliveries', (int) $row['failures'], 'store-audit-for-woocommerce' ),
				$row['name'],
				$row['status'],
				$row['failures']
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
		global $wpdb;
		$table = $wpdb->prefix . 'wc_webhooks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT name, status, failure_count FROM %i WHERE status <> 'active' OR failure_count > 0 ORDER BY webhook_id LIMIT 100", $table ), ARRAY_A );
		$out   = array();
		foreach ( (array) $found as $row ) {
			$out[] = array(
				'name'     => (string) $row['name'],
				'status'   => (string) $row['status'],
				'failures' => (int) $row['failure_count'],
			);
		}
		return $out;
	}
}
