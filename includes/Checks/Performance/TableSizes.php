<?php
/**
 * Database table sizes check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Performance;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database table sizes.
 */
final class TableSizes extends AbstractCheck {

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
		return 'performance_table_sizes';
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
		return __( 'Database table sizes', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Huge options or postmeta tables make every page and admin screen slower, and make backups and hosting more expensive.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Clean up old data (expired transients, orphaned meta, old logs) with a trusted database clean-up tool, after a backup.', 'store-audit-for-woocommerce' );
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
		global $wpdb;
		$names = array( 'options', 'postmeta', 'posts', 'wc_orders', 'wc_orders_meta' );
		$full  = array();
		foreach ( $names as $name ) {
			$full[ $wpdb->prefix . $name ] = $name;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ph   = implode( ',', array_fill( 0, count( $full ), '%s' ) );
		$args = array_merge( array( DB_NAME ), array_keys( $full ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT TABLE_NAME AS name, (DATA_LENGTH + INDEX_LENGTH) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ({$ph})", $args ),
			ARRAY_A
		);
		// phpcs:enable

		$sizes = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = $full[ (string) $row['name'] ] ?? null;
			if ( null !== $key ) {
				$sizes[ $key ] = (int) $row['bytes'];
			}
		}
		return array( 'sizes' => $sizes );
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$sizes = is_array( $d['sizes'] ?? null ) ? $d['sizes'] : array();
		if ( array() === $sizes ) {
			return Result::info( __( 'Could not read the table sizes from the database.', 'store-audit-for-woocommerce' ) );
		}
		arsort( $sizes );
		$details = array();
		foreach ( $sizes as $name => $bytes ) {
			$details[] = (string) $name . ': ' . $this->format_bytes( (int) $bytes );
		}
		$largest = (string) array_key_first( $sizes );
		$summary = sprintf(
			/* translators: 1: table name, 2: size. */
			__( 'The largest table checked is %1$s (%2$s).', 'store-audit-for-woocommerce' ),
			$largest,
			$this->format_bytes( (int) $sizes[ $largest ] )
		);

		$postmeta = (int) ( $sizes['postmeta'] ?? 0 );
		$options  = (int) ( $sizes['options'] ?? 0 );
		if ( $postmeta > 1073741824 || $options > 104857600 ) {
			$which = $postmeta > 1073741824 ? 'postmeta' : 'options';
			return Result::warning(
				sprintf(
					/* translators: %s: table name. */
					__( 'The %s table is very large, which slows the store down.', 'store-audit-for-woocommerce' ),
					$which
				) . ' ' . $summary,
				$details
			);
		}
		return Result::info( $summary, $details );
	}
}
