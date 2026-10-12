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
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

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
		$db     = Db::wpdb();
		$values = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes', 'on' );
		$ph     = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		$args   = array_merge( array( $db->options ), $values );
		// Reads only the autoloaded rows; the top-10 sort is the heaviest part.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only audit query; WordPress has no API for it.
		$total = $db->get_row(
			$db->prepare( "SELECT COUNT(*) AS n, SUM(LENGTH(option_value)) AS bytes FROM %i WHERE autoload IN ({$ph})", $args ),
			ARRAY_A
		);
		$top   = $db->get_results(
			$db->prepare( "SELECT option_name AS name, LENGTH(option_value) AS size FROM %i WHERE autoload IN ({$ph}) ORDER BY size DESC LIMIT 10", $args ),
			ARRAY_A
		);
		// phpcs:enable

		$list = array();
		foreach ( Arr::to_array( $top ) as $row ) {
			$row    = Arr::to_string_keyed( $row );
			$list[] = array(
				'name' => Arr::get_string( $row, 'name' ),
				'size' => Arr::get_int( $row, 'size' ),
			);
		}
		$total = Arr::to_string_keyed( $total );
		return array(
			'count' => Arr::get_int( $total, 'n' ),
			'bytes' => Arr::get_int( $total, 'bytes' ),
			'top'   => $list,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$count = Arr::get_int( $d, 'count' );
		$bytes = Arr::get_int( $d, 'bytes' );
		$top   = Arr::get_array( $d, 'top' );

		$details = array();
		foreach ( array_slice( $top, 0, 10 ) as $row ) {
			$row       = Arr::to_string_keyed( $row );
			$details[] = Arr::get_string( $row, 'name' ) . ': ' . $this->format_bytes( Arr::get_int( $row, 'size' ) );
		}
		$summary = sprintf(
			/* translators: 1: number of options, 2: total size. */
			_n( '%1$d option (%2$s) is loaded on every page.', '%1$d options (%2$s) are loaded on every page.', $count, 'store-audit-for-woocommerce' ),
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
