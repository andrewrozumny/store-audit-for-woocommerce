<?php
/**
 * Stale orders check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Plugins\DetailList;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pending and failed orders older than 30 days are abandoned checkouts.
 */
final class StaleOrders extends AbstractCheck {

	/**
	 * Returns array{pending:int,failed:int}.
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
		$this->source = $source ?? array( $this, 'read_orders' );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_stale_orders';
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
		return __( 'Abandoned orders', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Orders that stayed pending or failed for more than 30 days are abandoned checkouts. Some are customers you could win back; a huge pile also slows down the order list and database.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Follow up with these customers, then cancel or delete the old orders (WooCommerce, Orders, filter by status).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$data    = Arr::to_array( ( $this->source )() );
		$pending = Arr::get_int( $data, 'pending' );
		$failed  = Arr::get_int( $data, 'failed' );
		$total   = $pending + $failed;

		if ( 0 === $total ) {
			return Result::ok( __( 'No pending or failed orders older than 30 days.', 'store-audit-for-woocommerce' ) );
		}

		$summary = sprintf(
			/* translators: 1: total orders, 2: pending orders, 3: failed orders. */
			_n( '%1$d order is older than 30 days (%2$d pending, %3$d failed).', '%1$d orders are older than 30 days (%2$d pending, %3$d failed).', $total, 'store-audit-for-woocommerce' ),
			$total,
			$pending,
			$failed
		);
		$details = array(
			sprintf(
				/* translators: %d: number of orders. */
				__( 'Pending for over 30 days: %d', 'store-audit-for-woocommerce' ),
				$pending
			),
			sprintf(
				/* translators: %d: number of orders. */
				__( 'Failed for over 30 days: %d', 'store-audit-for-woocommerce' ),
				$failed
			),
		);

		return $total > 500 || $pending > 500 || $failed > 500
			? Result::warning( $summary, $details )
			: Result::info( $summary, $details );
	}

	/**
	 * Count old orders by status (paginate with limit 1 to get the total cheaply).
	 *
	 * @return array{pending:int,failed:int}
	 */
	private function read_orders(): array {
		$cutoff = time() - ( 30 * 86400 );
		$out    = array(
			'pending' => 0,
			'failed'  => 0,
		);
		foreach ( array_keys( $out ) as $status ) {
			$result         = wc_get_orders(
				array(
					'status'       => $status,
					'date_created' => '<' . $cutoff,
					'limit'        => 1,
					'paginate'     => true,
					'return'       => 'ids',
				)
			);
			$out[ $status ] = is_object( $result ) && isset( $result->total ) ? Arr::to_int( $result->total ) : 0;
		}
		return $out;
	}
}
