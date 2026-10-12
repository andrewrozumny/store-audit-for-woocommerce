<?php
/**
 * Storage of the last report and repair of what was stored.
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
 * Reads and writes the last report. A stored value is never trusted: it is rebuilt into a well-formed report on every read.
 */
final class ReportStore {

	public const OPTION = 'store_audit_last_report';

	/**
	 * Persist the report as the last one.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function store_last( array $report ): void {
		update_option( self::OPTION, $report, false );
	}

	/**
	 * Last stored report, repaired so every consumer can rely on the types. Anything that is not a report with
	 * at least one usable result row reads as "no report".
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_last(): ?array {
		return self::normalize( get_option( self::OPTION, null ) );
	}

	/**
	 * Turn a stored value into a well-formed report, or null when there is nothing usable in it.
	 *
	 * @param mixed $stored Value of the option.
	 * @return array<string,mixed>|null
	 */
	public static function normalize( mixed $stored ): ?array {
		if ( ! is_array( $stored ) || ! isset( $stored['results'] ) || ! is_array( $stored['results'] ) ) {
			return null;
		}
		$report = Arr::to_string_keyed( $stored );

		$rows = array();
		foreach ( Arr::get_array( $report, 'results' ) as $row ) {
			$clean = self::normalize_row( $row );
			if ( null !== $clean ) {
				$rows[] = $clean;
			}
		}
		if ( array() === $rows ) {
			return null;
		}

		return array(
			'generated_at'   => Arr::get_string( $report, 'generated_at' ),
			'site_url'       => Arr::get_string( $report, 'site_url' ),
			'plugin_version' => Arr::get_string( $report, 'plugin_version' ),
			'wp_version'     => Arr::get_string( $report, 'wp_version' ),
			'wc_version'     => Arr::get_string( $report, 'wc_version' ),
			'score'          => self::normalize_score( $report['score'] ?? null, $rows ),
			'results'        => $rows,
		);
	}

	/**
	 * One result row with known keys and types. An unknown severity counts as "not checked".
	 *
	 * @param mixed $row Stored row.
	 * @return array{id:string,section:string,title:string,severity:string,summary:string,details:list<string>,explanation:string,fix_hint:string,duration_ms:int,sensitive:bool}|null
	 */
	public static function normalize_row( mixed $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row      = Arr::to_string_keyed( $row );
		$severity = Arr::get_string( $row, 'severity' );
		$details  = array();
		foreach ( Arr::get_array( $row, 'details' ) as $line ) {
			if ( is_scalar( $line ) ) {
				$details[] = Arr::to_string( $line );
			}
		}
		return array(
			'id'          => Arr::get_string( $row, 'id' ),
			'section'     => Arr::get_string( $row, 'section' ),
			'title'       => Arr::get_string( $row, 'title' ),
			'severity'    => in_array( $severity, Result::severities(), true ) ? $severity : Result::ERROR,
			'summary'     => Arr::get_string( $row, 'summary' ),
			'details'     => $details,
			'explanation' => Arr::get_string( $row, 'explanation' ),
			'fix_hint'    => Arr::get_string( $row, 'fix_hint' ),
			'duration_ms' => Arr::get_int( $row, 'duration_ms' ),
			'sensitive'   => Arr::get_bool( $row, 'sensitive' ),
		);
	}

	/**
	 * The stored score when it has the expected shape, otherwise a score worked out from the rows. A score stored
	 * by an older version (without the checked/total/incomplete fields) is worked out again too.
	 *
	 * @param mixed                     $stored Stored score.
	 * @param list<array<string,mixed>> $rows   Normalised rows.
	 * @return array{overall:int,checked:int,total:int,incomplete:bool,sections:array<string,array<string,mixed>>}
	 */
	private static function normalize_score( mixed $stored, array $rows ): array {
		if ( is_array( $stored ) && isset( $stored['overall'], $stored['checked'], $stored['total'], $stored['incomplete'], $stored['sections'] ) && is_numeric( $stored['overall'] ) && is_numeric( $stored['checked'] ) && is_numeric( $stored['total'] ) && is_array( $stored['sections'] ) ) {
			$sections = array();
			$valid    = true;
			foreach ( $stored['sections'] as $id => $data ) {
				if ( ! is_string( $id ) || ! is_array( $data ) || ! array_key_exists( 'score', $data ) || ! isset( $data['label'], $data['counts'], $data['checked'], $data['total'], $data['incomplete'] ) || ( null !== $data['score'] && ! is_numeric( $data['score'] ) ) || ! is_string( $data['label'] ) || ! is_array( $data['counts'] ) ) {
					$valid = false;
					break;
				}
				$sections[ $id ] = Arr::to_string_keyed( $data );
			}
			if ( $valid ) {
				return array(
					'overall'    => Arr::to_int( $stored['overall'] ),
					'checked'    => Arr::to_int( $stored['checked'] ),
					'total'      => Arr::to_int( $stored['total'] ),
					'incomplete' => (bool) $stored['incomplete'],
					'sections'   => $sections,
				);
			}
		}
		$score = Score::calculate( $rows );
		return array(
			'overall'    => $score['overall'],
			'checked'    => $score['checked'],
			'total'      => $score['total'],
			'incomplete' => $score['incomplete'],
			'sections'   => Score::with_labels( $score['sections'], Section::labels() ),
		);
	}
}
