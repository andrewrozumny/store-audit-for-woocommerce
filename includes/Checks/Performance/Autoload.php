<?php
/**
 * Autoloaded options check.
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
 * Autoloaded options.
 */
final class Autoload extends AbstractCheck {

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
		return 'performance_autoload';
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
		return __( 'Autoloaded options', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'WordPress loads every autoloaded option on every single page view, including the checkout. Too much data here slows the whole store down.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Find the biggest options below, and ask the plugin owner or a developer to stop them from autoloading or to remove leftovers of old plugins.', 'store-audit-for-woocommerce' );
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
		$values = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes', 'on' );
		$ph     = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		// Reads only the autoloaded rows; the top-10 sort is the heaviest part.
		$total = $wpdb->get_row(
			$wpdb->prepare( "SELECT COUNT(*) AS n, SUM(LENGTH(option_value)) AS bytes FROM {$wpdb->options} WHERE autoload IN ({$ph})", $values ),
			ARRAY_A
		);
		$top   = $wpdb->get_results(
			$wpdb->prepare( "SELECT option_name AS name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload IN ({$ph}) ORDER BY size DESC LIMIT 10", $values ),
			ARRAY_A
		);
		// phpcs:enable

		$list = array();
		foreach ( is_array( $top ) ? $top : array() as $row ) {
			$list[] = array(
				'name' => (string) $row['name'],
				'size' => (int) $row['size'],
			);
		}
		return array(
			'count' => is_array( $total ) ? (int) $total['n'] : 0,
			'bytes' => is_array( $total ) ? (int) $total['bytes'] : 0,
			'top'   => $list,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$count = (int) ( $d['count'] ?? 0 );
		$bytes = (int) ( $d['bytes'] ?? 0 );
		$top   = is_array( $d['top'] ?? null ) ? $d['top'] : array();

		$details = array();
		foreach ( array_slice( $top, 0, 10 ) as $row ) {
			$details[] = (string) $row['name'] . ': ' . $this->format_bytes( (int) $row['size'] );
		}
		$summary = sprintf(
			/* translators: 1: number of options, 2: total size. */
			__( '%1$d options (%2$s) are loaded on every page.', 'store-audit-for-woocommerce' ),
			$count,
			$this->format_bytes( $bytes )
		);

		if ( $bytes > 3145728 ) {
			return Result::critical( $summary . ' ' . __( 'That is far too much and slows every page.', 'store-audit-for-woocommerce' ), $details );
		}
		if ( $bytes > 1048576 ) {
			return Result::warning( $summary . ' ' . __( 'More than 1 MB slows pages down.', 'store-audit-for-woocommerce' ), $details );
		}
		return Result::ok( $summary, $details );
	}
}
