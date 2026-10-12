<?php
/**
 * Score calculation.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Checks\Section;

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
	 * Calculate scores.
	 *
	 * @param list<array<string,mixed>> $results Results as returned by Runner (need `section` and `severity`).
	 * @return array{overall:int,sections:array<string,array{score:int,counts:array<string,int>}>}
	 */
	public static function calculate( array $results ): array {
		$weights = Section::weights();
		$empty   = array_fill_keys( Result::severities(), 0 );
		$counts  = array();

		foreach ( $results as $row ) {
			$section  = isset( $row['section'] ) ? (string) $row['section'] : '';
			$severity = isset( $row['severity'] ) ? (string) $row['severity'] : '';
			if ( ! isset( $weights[ $section ] ) || ! isset( self::PENALTY[ $severity ] ) ) {
				continue;
			}
			if ( ! isset( $counts[ $section ] ) ) {
				$counts[ $section ] = $empty;
			}
			++$counts[ $section ][ $severity ];
		}

		$sections = array();
		$weighted = 0;
		$total_w  = 0;
		foreach ( array_keys( $weights ) as $section ) {
			if ( ! isset( $counts[ $section ] ) ) {
				continue;
			}
			$section_counts = $counts[ $section ];
			$checked        = array_sum( $section_counts ) - $section_counts[ Result::ERROR ];
			if ( $checked <= 0 ) {
				continue;
			}
			$score = 100;
			foreach ( self::PENALTY as $severity => $penalty ) {
				$score -= $penalty * $section_counts[ $severity ];
			}
			$score                = max( 0, $score );
			$sections[ $section ] = array(
				'score'  => $score,
				'counts' => $section_counts,
			);
			$weighted            += $score * $weights[ $section ];
			$total_w             += $weights[ $section ];
		}

		return array(
			'overall'  => $total_w > 0 ? (int) round( $weighted / $total_w ) : 0,
			'sections' => $sections,
		);
	}
}
