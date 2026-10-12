<?php
/**
 * Score calculation.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Checks\Section;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes section and overall scores from results.
 */
final class Score {

	private const PENALTY = array(
		Result::CRITICAL => 25,
		Result::WARNING  => 10,
		Result::INFO     => 2,
		Result::OK       => 0,
		Result::ERROR    => 0,
	);

	/**
	 * Highest section score while the section holds a critical finding.
	 */
	public const SECTION_CRITICAL_CAP = 40;

	/**
	 * Highest overall score while the report holds a critical finding.
	 */
	public const OVERALL_CRITICAL_CAP = 49;

	/**
	 * Calculate scores.
	 *
	 * A section score is null (incomplete) when more than half of its checks did not run. The overall score is
	 * flagged incomplete when any section is, and always carries how many checks ran out of how many.
	 *
	 * @param list<array<string,mixed>> $results Results as returned by Runner (need `section` and `severity`).
	 * @return array{overall:int,checked:int,total:int,incomplete:bool,sections:array<string,array{score:int|null,counts:array<string,int>,checked:int,total:int,incomplete:bool}>}
	 */
	public static function calculate( array $results ): array {
		$weights = Section::weights();
		$empty   = array_fill_keys( Result::severities(), 0 );
		$counts  = array();

		foreach ( $results as $row ) {
			$section  = Arr::get_string( $row, 'section' );
			$severity = Arr::get_string( $row, 'severity' );
			if ( ! isset( $weights[ $section ] ) || ! isset( self::PENALTY[ $severity ] ) ) {
				continue;
			}
			if ( ! isset( $counts[ $section ] ) ) {
				$counts[ $section ] = $empty;
			}
			++$counts[ $section ][ $severity ];
		}

		$sections   = array();
		$weighted   = 0;
		$total_w    = 0;
		$all_checks = 0;
		$all_ran    = 0;
		$critical   = 0;
		$incomplete = false;
		foreach ( array_keys( $weights ) as $section ) {
			if ( ! isset( $counts[ $section ] ) ) {
				continue;
			}
			$section_counts = $counts[ $section ];
			$total          = array_sum( $section_counts );
			$checked        = $total - $section_counts[ Result::ERROR ];
			$all_checks    += $total;
			$all_ran       += $checked;
			$critical      += $section_counts[ Result::CRITICAL ];

			$section_incomplete = $checked <= 0 || ( $total - $checked ) * 2 > $total;
			$score              = null;
			if ( ! $section_incomplete ) {
				$score = 100;
				foreach ( self::PENALTY as $severity => $penalty ) {
					$score -= $penalty * $section_counts[ $severity ];
				}
				$score = max( 0, $score );
				if ( $section_counts[ Result::CRITICAL ] > 0 ) {
					$score = min( $score, self::SECTION_CRITICAL_CAP );
				}
				$weighted += $score * $weights[ $section ];
				$total_w  += $weights[ $section ];
			} else {
				$incomplete = true;
			}
			$sections[ $section ] = array(
				'score'      => $score,
				'counts'     => $section_counts,
				'checked'    => $checked,
				'total'      => $total,
				'incomplete' => $section_incomplete,
			);
		}

		$overall = $total_w > 0 ? (int) round( $weighted / $total_w ) : 0;
		if ( $critical > 0 ) {
			$overall = min( $overall, self::OVERALL_CRITICAL_CAP );
		}
		if ( 0 === $total_w && $all_checks > 0 ) {
			$incomplete = true;
		}

		return array(
			'overall'    => $overall,
			'checked'    => $all_ran,
			'total'      => $all_checks,
			'incomplete' => $incomplete,
			'sections'   => $sections,
		);
	}

	/**
	 * Text that marks an incomplete score, for example "Incomplete: 20 of 33 checks ran".
	 *
	 * @param int $checked Checks that ran.
	 * @param int $total   Checks in the report.
	 */
	public static function incomplete_note( int $checked, int $total ): string {
		return sprintf(
			/* translators: 1: number of checks that ran, 2: number of checks in the report. */
			__( 'Incomplete: %1$d of %2$d checks ran', 'store-audit-for-woocommerce' ),
			$checked,
			$total
		);
	}
}
