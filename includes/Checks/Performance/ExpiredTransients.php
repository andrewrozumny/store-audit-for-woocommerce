<?php
/**
 * Expired transients check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Performance;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expired transients.
 */
final class ExpiredTransients extends AbstractCheck {

	/**
	 * Data to evaluate (tests) or null to read the live site.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $data Pre-collected data for tests; null reads the live site.
	 */
	public function __construct( ?array $data = null ) {
		$this->data = $data;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'performance_expired_transients';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::PERFORMANCE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Expired transients', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Transients are temporary saved answers. Expired ones should vanish by themselves, but when they pile up they bloat the database and slow it down.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Delete expired transients with a trusted clean-up plugin or WP-CLI (wp transient delete --expired) after a backup.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		return $this->evaluate( $this->data ?? $this->collect() );
	}

	/**
	 * Read the live site.
	 *
	 * @return array<string,mixed>
	 */
	private function collect(): array {
		$db = Db::wpdb();
		// Uses the option_name index (prefix match); the timestamp comparison only touches those rows.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only audit query; WordPress has no API for it.
		$count = Arr::to_int(
			$db->get_var(
				$db->prepare(
					'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
					$db->options,
					$db->esc_like( '_transient_timeout_' ) . '%',
					time()
				)
			)
		);
		// phpcs:enable

		return array( 'count' => $count );
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$count = Arr::get_int( $d, 'count' );
		if ( 0 === $count ) {
			return Result::ok( __( 'There are no expired transients.', 'store-audit-for-woocommerce' ) );
		}
		$summary = sprintf(
			/* translators: %d: number of transients. */
			_n( '%d expired transient is still in the database.', '%d expired transients are still in the database.', $count, 'store-audit-for-woocommerce' ),
			$count
		);
		return $count > 500 ? Result::warning( $summary ) : Result::info( $summary );
	}
}
