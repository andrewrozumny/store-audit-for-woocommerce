<?php
/**
 * Maximum execution time check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Environment;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\EnvSnapshot;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum execution time.
 */
final class MaxExecutionTime extends AbstractCheck {

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
		return 'environment_max_execution_time';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::ENVIRONMENT;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Maximum execution time', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'This is how long PHP may spend on one request. If it is too short, imports, backups and heavy checkouts are cut off half way.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Ask your host to set max_execution_time to at least 60 seconds.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		return $this->evaluate( $this->data ?? $this->collect() );
	}

	/**
	 * Read the value the site had when the plugin loaded, before the audit raised the time limit for its own work.
	 *
	 * @return array<string,mixed>
	 */
	private function collect(): array {
		return array(
			'seconds' => EnvSnapshot::get()['max_execution_time'],
			'cli'     => 'cli' === PHP_SAPI,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$seconds = Arr::get_int( $d, 'seconds' );

		if ( 0 === $seconds ) {
			$summary = Arr::get_bool( $d, 'cli' )
				? __( 'No time limit is set (this audit runs from the command line, which is always unlimited, so the web limit was not measured).', 'store-audit-for-woocommerce' )
				: __( 'No time limit is set.', 'store-audit-for-woocommerce' );
			return Result::ok( $summary );
		}

		if ( $seconds < 30 ) {
			return Result::warning(
				/* translators: %d: seconds. */
				sprintf( _n( 'PHP stops requests after %d second, which is short for a store.', 'PHP stops requests after %d seconds, which is short for a store.', $seconds, 'store-audit-for-woocommerce' ), $seconds )
			);
		}

		return Result::ok(
			/* translators: %d: seconds. */
			sprintf( _n( 'PHP allows requests to run for %d second.', 'PHP allows requests to run for %d seconds.', $seconds, 'store-audit-for-woocommerce' ), $seconds )
		);
	}
}
