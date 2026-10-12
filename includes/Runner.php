<?php
/**
 * Runs checks and decorates results with check metadata.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\CheckInterface;
use StoreAudit\Support\NotCheckedException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check runner.
 */
final class Runner {

	/**
	 * Seconds a single check may run when the host allows less.
	 */
	private const MIN_TIME_LIMIT = 30;

	/**
	 * The time limit to ask for, or null to leave it alone. Only a limit that is set and shorter than the minimum is
	 * raised; zero (unlimited, as on the command line) and longer limits are never touched, and nothing is lowered.
	 *
	 * @param int $current Current max_execution_time in seconds.
	 */
	public static function time_limit_to_set( int $current ): ?int {
		return $current > 0 && $current < self::MIN_TIME_LIMIT ? self::MIN_TIME_LIMIT : null;
	}

	/**
	 * Whether the check hides its details from exports. Checks that do not extend AbstractCheck never do.
	 *
	 * @param CheckInterface $check Check.
	 */
	private static function is_sensitive( CheckInterface $check ): bool {
		return $check instanceof AbstractCheck && $check->is_sensitive();
	}

	/**
	 * Run one check; never throws.
	 *
	 * @param CheckInterface $check Check.
	 * @return array{id:string,section:string,title:string,severity:string,summary:string,details:list<string>,explanation:string,fix_hint:string,duration_ms:int,sensitive:bool}
	 */
	public function run_one( CheckInterface $check ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$new_limit = self::time_limit_to_set( (int) ini_get( 'max_execution_time' ) );
		if ( null !== $new_limit && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $new_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- Silenced on purpose: the host may disable the function.
		}

		$start = microtime( true );
		try {
			$result = $check->run();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// The full text goes to the PHP error log only; the report never carries paths or raw messages.
				error_log( 'Store Audit: check ' . $check->id() . ' failed: ' . sanitize_text_field( $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only log line.
			}
			$result = Result::error(
				$e instanceof NotCheckedException
					/* translators: %s: reason, for example "the database table wp_wc_orders does not exist.". */
					? sprintf( __( 'Could not check: %s', 'store-audit-for-woocommerce' ), sanitize_text_field( $e->getMessage() ) )
					: __( 'Could not check: an internal error occurred (details in the PHP error log when debugging is on).', 'store-audit-for-woocommerce' )
			);
		}
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		$data     = $result->to_array();
		$fix_hint = '' !== $data['fix_hint'] ? $data['fix_hint'] : $check->fix_hint();

		return array(
			'id'          => $check->id(),
			'section'     => $check->section(),
			'title'       => $check->title(),
			'severity'    => $data['severity'],
			'summary'     => $data['summary'],
			'details'     => $data['details'],
			'explanation' => $check->explanation(),
			'fix_hint'    => $fix_hint,
			'duration_ms' => $duration,
			'sensitive'   => self::is_sensitive( $check ),
		);
	}

	/**
	 * The row for a check that did not run, for example because the audit was stopped half way. It counts as
	 * "not checked" and never as a pass.
	 *
	 * @param CheckInterface $check Check.
	 * @return array{id:string,section:string,title:string,severity:string,summary:string,details:list<string>,explanation:string,fix_hint:string,duration_ms:int,sensitive:bool}
	 */
	public function not_run( CheckInterface $check ): array {
		return array(
			'id'          => $check->id(),
			'section'     => $check->section(),
			'title'       => $check->title(),
			'severity'    => Result::ERROR,
			'summary'     => __( 'This check did not run, so it is not part of the score. Run the audit again.', 'store-audit-for-woocommerce' ),
			'details'     => array(),
			'explanation' => $check->explanation(),
			'fix_hint'    => '',
			'duration_ms' => 0,
			'sensitive'   => false,
		);
	}

	/**
	 * Run every registered check (CLI).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function run_all(): array {
		$results = array();
		foreach ( Registry::all() as $check ) {
			$results[] = $this->run_one( $check );
		}
		return $results;
	}
}
