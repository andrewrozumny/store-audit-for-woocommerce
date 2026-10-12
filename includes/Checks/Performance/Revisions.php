<?php
/**
 * Post revisions check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Performance;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use WP_Post;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post revisions.
 */
final class Revisions extends AbstractCheck {

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
		return 'performance_revisions';
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
		return __( 'Post revisions', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'WordPress saves a copy of a page or product every time you save it. Thousands of old copies grow the database for no benefit.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Limit revisions by adding define( \'WP_POST_REVISIONS\', 5 ); to wp-config.php and delete old ones with a clean-up tool.', 'store-audit-for-woocommerce' );
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only audit query; WordPress has no API for it.
		$count = Arr::to_int( $db->get_var( $db->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'revision'", $db->posts ) ) );
		// phpcs:enable

		$limit = wp_revisions_to_keep( new WP_Post( (object) array( 'post_type' => 'post' ) ) );
		if ( $limit < 0 ) {
			$text = __( 'unlimited', 'store-audit-for-woocommerce' );
		} elseif ( 0 === $limit ) {
			$text = __( 'off', 'store-audit-for-woocommerce' );
		} else {
			$text = (string) $limit;
		}
		return array(
			'count' => $count,
			'limit' => $text,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$count   = Arr::get_int( $d, 'count' );
		$limit   = Arr::get_string( $d, 'limit' );
		$details = array(
			/* translators: %s: revisions limit. */
			sprintf( __( 'WP_POST_REVISIONS: %s', 'store-audit-for-woocommerce' ), $limit ),
		);
		$summary = sprintf(
			/* translators: %d: number of revisions. */
			_n( '%d saved revision.', '%d saved revisions.', $count, 'store-audit-for-woocommerce' ),
			$count
		);

		if ( $count > 5000 ) {
			return Result::warning( $summary . ' ' . __( 'That is a lot of old copies.', 'store-audit-for-woocommerce' ), $details );
		}
		return Result::ok( $summary, $details );
	}
}
