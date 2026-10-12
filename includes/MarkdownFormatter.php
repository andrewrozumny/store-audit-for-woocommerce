<?php
/**
 * Markdown export of a report.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a report into Markdown that can be used as a work plan. Text that comes from the site is made safe first.
 */
final class MarkdownFormatter {

	/**
	 * Markdown export, usable as a work plan. Details of sensitive checks are left out unless the full data is
	 * asked for (command line).
	 *
	 * @param array<string,mixed> $report Report.
	 * @param bool                $redact Leave out the details of sensitive checks.
	 */
	public static function format( array $report, bool $redact = true ): string {
		if ( $redact ) {
			$report = Report::redact_for_export( $report );
		}
		$score    = Arr::get_map( $report, 'score' );
		$sections = Arr::get_map( $score, 'sections' );

		$out = self::header_lines( $report, $score );
		array_push( $out, ...self::score_table_lines( $sections ) );
		foreach ( ReportView::group_by_section( $report ) as $id => $rows ) {
			array_push( $out, ...self::section_lines( $id, $rows, Arr::get_map( $sections, $id ) ) );
		}
		if ( Report::show_attribution() ) {
			$out[] = '---';
			$out[] = '';
			$out[] = Report::attribution_markdown();
		}
		return implode( "\n", $out ) . "\n";
	}

	/**
	 * Make text that comes from the site safe to paste into Markdown: one line, no HTML tags, no links.
	 *
	 * @param string $text Raw text.
	 */
	public static function text( string $text ): string {
		$clean = preg_replace( '/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $text );
		$text  = null === $clean ? (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) : $clean;
		return str_replace( array( '<', '>', '[', ']' ), array( '\\<', '\\>', '\\[', '\\]' ), $text );
	}

	/**
	 * Title and the date, score and versions list.
	 *
	 * @param array<string,mixed> $report Report.
	 * @param array<string,mixed> $score  The `score` part of the report.
	 * @return list<string>
	 */
	private static function header_lines( array $report, array $score ): array {
		$time = strtotime( Arr::get_string( $report, 'generated_at' ) );
		$date = false === $time ? self::text( Arr::get_string( $report, 'generated_at' ) ) : gmdate( 'Y-m-d H:i', $time ) . ' UTC';
		$note = Report::incomplete_note( $score );

		$overall = Arr::get_int( $score, 'overall' );
		$out     = array();
		$out[]   = '# ' . sprintf(
			/* translators: %s: site address. */
			__( 'Store Audit report for %s', 'store-audit-for-woocommerce' ),
			self::text( Arr::get_string( $report, 'site_url' ) )
		);
		$out[] = '';
		$out[] = '- ' . __( 'Date:', 'store-audit-for-woocommerce' ) . ' ' . $date;
		$out[] = '- ' . __( 'Overall score:', 'store-audit-for-woocommerce' ) . ' **' . $overall . ' / 100**' . ( '' !== $note ? ' (' . $note . ')' : '' );
		$env   = self::versions( $report );
		if ( array() !== $env ) {
			$out[] = '- ' . __( 'Versions:', 'store-audit-for-woocommerce' ) . ' ' . implode( ', ', $env );
		}
		$out[] = '';
		return $out;
	}

	/**
	 * The WordPress, WooCommerce and plugin versions the report was made with, empty ones left out.
	 *
	 * @param array<string,mixed> $report Report.
	 * @return list<string>
	 */
	private static function versions( array $report ): array {
		$wp_ver     = Arr::get_string( $report, 'wp_version' );
		$wc_ver     = Arr::get_string( $report, 'wc_version' );
		$plugin_ver = Arr::get_string( $report, 'plugin_version' );
		return array_values(
			array_filter(
				array(
					'' !== $wp_ver ? 'WordPress ' . self::text( $wp_ver ) : '',
					'' !== $wc_ver ? 'WooCommerce ' . self::text( $wc_ver ) : '',
					'' !== $plugin_ver ? 'Store Audit ' . self::text( $plugin_ver ) : '',
				),
				static fn( string $line ): bool => '' !== $line
			)
		);
	}

	/**
	 * The "Scores by section" table.
	 *
	 * @param array<string,mixed> $sections Section scores of the report.
	 * @return list<string>
	 */
	private static function score_table_lines( array $sections ): array {
		$out   = array();
		$out[] = '## ' . __( 'Scores by section', 'store-audit-for-woocommerce' );
		$out[] = '';
		$out[] = '| ' . implode( ' | ', array( __( 'Section', 'store-audit-for-woocommerce' ), __( 'Score', 'store-audit-for-woocommerce' ), __( 'Critical', 'store-audit-for-woocommerce' ), __( 'Warnings', 'store-audit-for-woocommerce' ), __( 'Info', 'store-audit-for-woocommerce' ), __( 'Passed', 'store-audit-for-woocommerce' ) ) ) . ' |';
		$out[] = '| --- | --- | --- | --- | --- | --- |';
		foreach ( $sections as $id => $data ) {
			$data   = Arr::to_string_keyed( $data );
			$counts = Arr::get_map( $data, 'counts' );
			$name   = self::text( ReportView::section_label( $data, $id ) );
			$out[]  = '| ' . str_replace( '|', '\\|', $name ) . ' | ' . ReportView::section_score_text( $data, false ) . ' | '
				. Arr::get_int( $counts, Result::CRITICAL ) . ' | ' . Arr::get_int( $counts, Result::WARNING ) . ' | '
				. Arr::get_int( $counts, Result::INFO ) . ' | ' . Arr::get_int( $counts, Result::OK ) . ' |';
		}
		$out[] = '';
		return $out;
	}

	/**
	 * One section: heading with its score, the problems worst first, then the passed checks.
	 *
	 * @param string|int                $id           Section id.
	 * @param list<array<string,mixed>> $rows         Rows of the section.
	 * @param array<string,mixed>       $section_data Score data of the section (may be empty).
	 * @return list<string>
	 */
	private static function section_lines( string|int $id, array $rows, array $section_data ): array {
		$head = '## ' . self::text( ReportView::section_label( $section_data, $id ) );
		if ( array() !== $section_data ) {
			$head .= ' (' . ReportView::section_score_text( $section_data ) . ')';
		}
		$out   = array( $head, '' );
		$split = ReportView::split_section( $rows );
		foreach ( $split['issues'] as $row ) {
			array_push( $out, ...self::issue_lines( $row ) );
		}
		if ( array() !== $split['passed'] ) {
			$out[] = '**' . __( 'Passed checks', 'store-audit-for-woocommerce' ) . '**';
			$out[] = '';
			foreach ( $split['passed'] as $title ) {
				$out[] = '- ' . self::text( $title );
			}
			$out[] = '';
		}
		return $out;
	}

	/**
	 * One problem: heading, summary, the fix hint and the detail lines.
	 *
	 * @param array<string,mixed> $row Result row.
	 * @return list<string>
	 */
	private static function issue_lines( array $row ): array {
		$out     = array();
		$out[]   = '### ' . ReportView::severity_label( Arr::get_string( $row, 'severity' ) ) . ': ' . self::text( Arr::get_string( $row, 'title' ) );
		$out[]   = '';
		$out[]   = self::text( Arr::get_string( $row, 'summary' ) );
		$out[]   = '';
		$hint    = self::text( Arr::get_string( $row, 'fix_hint' ) );
		$details = Arr::get_array( $row, 'details' );
		if ( '' !== $hint ) {
			$out[] = '**' . __( 'What to do:', 'store-audit-for-woocommerce' ) . '** ' . $hint;
			$out[] = '';
		}
		foreach ( $details as $line ) {
			$out[] = '- ' . self::text( Arr::to_string( $line ) );
		}
		if ( array() !== $details ) {
			$out[] = '';
		}
		return $out;
	}
}
