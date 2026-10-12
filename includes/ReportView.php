<?php
/**
 * Report view model: grouping, ordering and the passed/problem split shared by every output.
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
 * Presentation logic that the printable report and the Markdown export share, so both list the same things in the same order.
 */
final class ReportView {

	/**
	 * Severity order used when listing issues, worst first. "ok" is listed separately as passed checks. This is the
	 * only place the order is defined: the admin script receives it through its page config.
	 *
	 * @var list<string>
	 */
	public const ISSUE_ORDER = array( Result::CRITICAL, Result::WARNING, Result::ERROR, Result::INFO );

	/**
	 * Every severity in display order, worst first and passed checks last (what the admin script sorts by).
	 *
	 * @return list<string>
	 */
	public static function severity_order(): array {
		return array_merge( self::ISSUE_ORDER, array( Result::OK ) );
	}

	/**
	 * Result rows grouped by section id, in display order.
	 *
	 * @param array<string,mixed> $report Report.
	 * @return array<string,list<array<string,mixed>>>
	 */
	public static function group_by_section( array $report ): array {
		$groups = array();
		foreach ( Arr::get_array( $report, 'results' ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row = Arr::to_string_keyed( $row );
			$groups[ Arr::get_string( $row, 'section' ) ][] = $row;
		}
		$ordered = array();
		foreach ( array_keys( Section::labels() ) as $id ) {
			if ( isset( $groups[ $id ] ) ) {
				$ordered[ $id ] = $groups[ $id ];
			}
		}
		foreach ( $groups as $id => $rows ) {
			if ( ! isset( $ordered[ $id ] ) ) {
				$ordered[ $id ] = $rows;
			}
		}
		return $ordered;
	}

	/**
	 * Sort rows worst first (critical, warning, error, info); keeps the original order inside a severity.
	 *
	 * @param list<array<string,mixed>> $rows Rows.
	 * @return list<array<string,mixed>>
	 */
	public static function sort_issues( array $rows ): array {
		$rank  = array_flip( self::ISSUE_ORDER );
		$keyed = array();
		foreach ( $rows as $i => $row ) {
			$keyed[] = array( $rank[ Arr::get_string( $row, 'severity' ) ] ?? 99, $i, $row );
		}
		usort(
			$keyed,
			static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] )
		);
		return array_map( static fn( array $item ): array => $item[2], $keyed );
	}

	/**
	 * Split one section's rows into the problems (worst first) and the titles of the checks that passed.
	 *
	 * @param list<array<string,mixed>> $rows Rows of one section.
	 * @return array{issues:list<array<string,mixed>>,passed:list<string>}
	 */
	public static function split_section( array $rows ): array {
		$issues = array();
		$passed = array();
		foreach ( $rows as $row ) {
			if ( Result::OK === ( $row['severity'] ?? '' ) ) {
				$passed[] = Arr::get_string( $row, 'title' );
			} else {
				$issues[] = $row;
			}
		}
		return array(
			'issues' => self::sort_issues( $issues ),
			'passed' => $passed,
		);
	}

	/**
	 * Display name of a section: the label stored with its score, else the registered label, else the id.
	 *
	 * @param array<string,mixed> $section_data One section of the report score (may be empty).
	 * @param string|int          $id           Section id (an array key, so a numeric id arrives as an int).
	 */
	public static function section_label( array $section_data, string|int $id ): string {
		return Arr::get_string( $section_data, 'label', Section::labels()[ $id ] ?? (string) $id );
	}

	/**
	 * Human label for a severity.
	 *
	 * @param string $severity Severity.
	 */
	public static function severity_label( string $severity ): string {
		switch ( $severity ) {
			case Result::CRITICAL:
				return __( 'Critical', 'store-audit-for-woocommerce' );
			case Result::WARNING:
				return __( 'Warning', 'store-audit-for-woocommerce' );
			case Result::INFO:
				return __( 'Info', 'store-audit-for-woocommerce' );
			case Result::OK:
				return __( 'OK', 'store-audit-for-woocommerce' );
			default:
				return __( 'Not checked', 'store-audit-for-woocommerce' );
		}
	}

	/**
	 * Section score for text outputs: "55 / 100", or the word "incomplete" when too few of its checks ran.
	 *
	 * @param array<string,mixed> $section  One section of the report score.
	 * @param bool                $with_max Add " / 100" after the number.
	 */
	public static function section_score_text( array $section, bool $with_max = true ): string {
		if ( ! isset( $section['score'] ) ) {
			return __( 'incomplete', 'store-audit-for-woocommerce' );
		}
		return Arr::get_int( $section, 'score' ) . ( $with_max ? ' / 100' : '' );
	}
}
