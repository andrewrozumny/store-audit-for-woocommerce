<?php
/**
 * Number of active plugins check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Performance;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Number of active plugins.
 */
final class ActivePlugins extends AbstractCheck {

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
		return 'performance_active_plugins';
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
		return __( 'Number of active plugins', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Every active plugin adds code that runs on each page. A very long list usually means a slower, more fragile store and more security surface.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Deactivate and delete plugins you do not need, and replace several small plugins with one that does the job well.', 'store-audit-for-woocommerce' );
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
		$active  = get_option( 'active_plugins', array() );
		$network = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		return array(
			'active'  => is_array( $active ) ? count( $active ) : 0,
			'network' => is_array( $network ) ? count( $network ) : 0,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$active  = Arr::get_int( $d, 'active' );
		$network = Arr::get_int( $d, 'network' );
		$total   = $active + $network;
		$summary = sprintf(
			/* translators: %d: number of plugins. */
			_n( '%d plugin is active.', '%d plugins are active.', $total, 'store-audit-for-woocommerce' ),
			$total
		);
		$details = array();
		if ( $network > 0 ) {
			/* translators: 1: plugins active on this site, 2: network-active plugins. */
			$details[] = sprintf( __( '%1$d on this site and %2$d network-wide.', 'store-audit-for-woocommerce' ), $active, $network );
		}

		if ( $total > 40 ) {
			return Result::warning( $summary . ' ' . __( 'That is a lot and will slow the store down.', 'store-audit-for-woocommerce' ), $details );
		}
		if ( $total > 25 ) {
			return Result::info( $summary . ' ' . __( 'Consider removing the ones you do not need.', 'store-audit-for-woocommerce' ), $details );
		}
		return Result::ok( $summary, $details );
	}
}
