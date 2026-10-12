<?php
/**
 * Runs checks and decorates results with check metadata.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Checks\CheckInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check runner.
 */
final class Runner {

	/**
	 * Run one check; never throws.
	 *
	 * @param CheckInterface $check Check.
	 * @return array{id:string,section:string,title:string,severity:string,summary:string,details:list<string>,explanation:string,fix_hint:string,duration_ms:int}
	 */
	public function run_one( CheckInterface $check ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- Silenced on purpose: the host may disable the function.
		}

		$start = microtime( true );
		try {
			$result = $check->run();
		} catch ( \Throwable $e ) {
			$message = sanitize_text_field( $e->getMessage() );
			$result  = Result::error(
				/* translators: %s: error message. */
				sprintf( __( 'Could not check: %s', 'store-audit-for-woocommerce' ), $message )
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
		);
	}

	/**
	 * The row for a check that did not run, for example because the audit was stopped half way. It counts as
	 * "not checked" and never as a pass.
	 *
	 * @param CheckInterface $check Check.
	 * @return array{id:string,section:string,title:string,severity:string,summary:string,details:list<string>,explanation:string,fix_hint:string,duration_ms:int}
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
