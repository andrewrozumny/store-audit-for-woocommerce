<?php
/**
 * Report assembly and storage.
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
 * Builds and stores reports.
 */
final class Report {

	public const OPTION = 'store_audit_last_report';

	public const ATTRIBUTION_URL    = 'https://helpmybiz.org';
	public const ATTRIBUTION_DOMAIN = 'helpmybiz.org';

	/**
	 * Severity order used when listing issues, worst first. "ok" is listed separately as passed checks.
	 *
	 * @var list<string>
	 */
	public const ISSUE_ORDER = array( Result::CRITICAL, Result::WARNING, Result::ERROR, Result::INFO );

	/**
	 * Build a report from results.
	 *
	 * @param list<array<string,mixed>> $results Results from Runner.
	 * @return array<string,mixed>
	 */
	public static function build( array $results ): array {
		$score  = Score::calculate( $results );
		$labels = Section::labels();

		$sections = array();
		foreach ( $score['sections'] as $id => $data ) {
			$sections[ $id ] = array(
				'label'  => $labels[ $id ] ?? $id,
				'score'  => $data['score'],
				'counts' => $data['counts'],
			);
		}

		return array(
			'generated_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site_url'       => site_url(),
			'plugin_version' => defined( 'STORE_AUDIT_VERSION' ) ? STORE_AUDIT_VERSION : '',
			'wp_version'     => get_bloginfo( 'version' ),
			'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'score'          => array(
				'overall'  => $score['overall'],
				'sections' => $sections,
			),
			'results'        => $results,
		);
	}

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
	 * @return array{id:string,section:string,title:string,severity:string,summary:string,details:list<string>,explanation:string,fix_hint:string,duration_ms:int}|null
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
		);
	}

	/**
	 * The stored score when it has the expected shape, otherwise a score worked out from the rows.
	 *
	 * @param mixed                     $stored Stored score.
	 * @param list<array<string,mixed>> $rows   Normalised rows.
	 * @return array{overall:int,sections:array<string,array<string,mixed>>}
	 */
	private static function normalize_score( mixed $stored, array $rows ): array {
		if ( is_array( $stored ) && isset( $stored['overall'], $stored['sections'] ) && is_numeric( $stored['overall'] ) && is_array( $stored['sections'] ) ) {
			$sections = array();
			$valid    = true;
			foreach ( $stored['sections'] as $id => $data ) {
				if ( ! is_string( $id ) || ! is_array( $data ) || ! isset( $data['score'], $data['label'], $data['counts'] ) || ! is_numeric( $data['score'] ) || ! is_string( $data['label'] ) || ! is_array( $data['counts'] ) ) {
					$valid = false;
					break;
				}
				$sections[ $id ] = Arr::to_string_keyed( $data );
			}
			if ( $valid ) {
				return array(
					'overall'  => Arr::to_int( $stored['overall'] ),
					'sections' => $sections,
				);
			}
		}
		$score  = Score::calculate( $rows );
		$labels = Section::labels();
		$out    = array();
		foreach ( $score['sections'] as $id => $data ) {
			$out[ $id ] = array(
				'label'  => $labels[ $id ] ?? $id,
				'score'  => $data['score'],
				'counts' => $data['counts'],
			);
		}
		return array(
			'overall'  => $score['overall'],
			'sections' => $out,
		);
	}

	/**
	 * Plain-text attribution line, shared by every export.
	 */
	public static function attribution(): string {
		return 'Report generated by Store Audit for WooCommerce by Andrew Rozumny, ' . self::ATTRIBUTION_DOMAIN;
	}

	/**
	 * Attribution as safe HTML, with the domain linked.
	 */
	public static function attribution_html(): string {
		return esc_html( 'Report generated by Store Audit for WooCommerce by Andrew Rozumny, ' )
			. '<a href="' . esc_url( self::ATTRIBUTION_URL ) . '" target="_blank" rel="noopener">' . esc_html( self::ATTRIBUTION_DOMAIN ) . '</a>';
	}

	/**
	 * Attribution as Markdown, with the domain linked.
	 */
	public static function attribution_markdown(): string {
		return 'Report generated by Store Audit for WooCommerce by Andrew Rozumny, [' . self::ATTRIBUTION_DOMAIN . '](' . self::ATTRIBUTION_URL . ')';
	}

	/**
	 * Report date in the site's local format and time zone.
	 *
	 * @param string $iso ISO 8601 date from the report.
	 */
	public static function local_date( string $iso ): string {
		$time = strtotime( $iso );
		if ( false === $time ) {
			return $iso;
		}
		$format = trim( Arr::to_string( get_option( 'date_format', 'Y-m-d' ), 'Y-m-d' ) . ' ' . Arr::to_string( get_option( 'time_format', 'H:i' ), 'H:i' ) );
		$date   = wp_date( $format, $time );
		return is_string( $date ) ? $date : $iso;
	}

	/**
	 * File name for downloads, for example store-audit-example.com-2026-10-12.json.
	 *
	 * @param array<string,mixed> $report    Report.
	 * @param string              $extension File extension without the dot.
	 */
	public static function filename( array $report, string $extension ): string {
		$host = wp_parse_url( Arr::get_string( $report, 'site_url' ), PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? $host : 'site';
		$time = strtotime( Arr::get_string( $report, 'generated_at' ) );
		$date = gmdate( 'Y-m-d', false === $time ? time() : $time );
		return sanitize_file_name( 'store-audit-' . $host . '-' . $date . '.' . $extension );
	}

	/**
	 * Pretty-printed JSON export with the attribution field.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function to_json( array $report ): string {
		$data = array_merge( array( 'generated_by' => self::attribution() ), $report );
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
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
	 * Make text that comes from the site safe to paste into Markdown: one line, no HTML tags, no links.
	 *
	 * @param string $text Raw text.
	 */
	public static function md_text( string $text ): string {
		$clean = preg_replace( '/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $text );
		$text  = null === $clean ? (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) : $clean;
		return str_replace( array( '<', '>', '[', ']' ), array( '\\<', '\\>', '\\[', '\\]' ), $text );
	}

	/**
	 * Markdown export, usable as a work plan.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function to_markdown( array $report ): string {
		$labels   = Section::labels();
		$score    = Arr::get_map( $report, 'score' );
		$sections = Arr::get_map( $score, 'sections' );
		$time     = strtotime( Arr::get_string( $report, 'generated_at' ) );
		$date     = false === $time ? self::md_text( Arr::get_string( $report, 'generated_at' ) ) : gmdate( 'Y-m-d H:i', $time ) . ' UTC';

		$overall    = Arr::get_int( $score, 'overall' );
		$wp_ver     = Arr::get_string( $report, 'wp_version' );
		$wc_ver     = Arr::get_string( $report, 'wc_version' );
		$plugin_ver = Arr::get_string( $report, 'plugin_version' );
		$env        = array_filter(
			array(
				'' !== $wp_ver ? 'WordPress ' . self::md_text( $wp_ver ) : '',
				'' !== $wc_ver ? 'WooCommerce ' . self::md_text( $wc_ver ) : '',
				'' !== $plugin_ver ? 'Store Audit ' . self::md_text( $plugin_ver ) : '',
			),
			static fn( string $line ): bool => '' !== $line
		);

		$out   = array();
		$out[] = '# ' . sprintf(
			/* translators: %s: site address. */
			__( 'Store Audit report for %s', 'store-audit-for-woocommerce' ),
			self::md_text( Arr::get_string( $report, 'site_url' ) )
		);
		$out[] = '';
		$out[] = '- ' . __( 'Date:', 'store-audit-for-woocommerce' ) . ' ' . $date;
		$out[] = '- ' . __( 'Overall score:', 'store-audit-for-woocommerce' ) . ' **' . $overall . ' / 100**';
		if ( array() !== $env ) {
			$out[] = '- ' . __( 'Versions:', 'store-audit-for-woocommerce' ) . ' ' . implode( ', ', $env );
		}
		$out[] = '';

		$out[] = '## ' . __( 'Scores by section', 'store-audit-for-woocommerce' );
		$out[] = '';
		$out[] = '| ' . implode( ' | ', array( __( 'Section', 'store-audit-for-woocommerce' ), __( 'Score', 'store-audit-for-woocommerce' ), __( 'Critical', 'store-audit-for-woocommerce' ), __( 'Warnings', 'store-audit-for-woocommerce' ), __( 'Info', 'store-audit-for-woocommerce' ), __( 'Passed', 'store-audit-for-woocommerce' ) ) ) . ' |';
		$out[] = '| --- | --- | --- | --- | --- | --- |';
		foreach ( $sections as $id => $data ) {
			$data   = Arr::to_string_keyed( $data );
			$counts = Arr::get_map( $data, 'counts' );
			$name   = self::md_text( Arr::get_string( $data, 'label', $labels[ $id ] ?? $id ) );
			$out[]  = '| ' . str_replace( '|', '\\|', $name ) . ' | ' . Arr::get_int( $data, 'score' ) . ' | '
				. Arr::get_int( $counts, Result::CRITICAL ) . ' | ' . Arr::get_int( $counts, Result::WARNING ) . ' | '
				. Arr::get_int( $counts, Result::INFO ) . ' | ' . Arr::get_int( $counts, Result::OK ) . ' |';
		}
		$out[] = '';

		foreach ( self::group_by_section( $report ) as $id => $rows ) {
			$section_data = Arr::get_map( $sections, $id );
			$name         = self::md_text( Arr::get_string( $section_data, 'label', $labels[ $id ] ?? $id ) );
			$head         = '## ' . $name;
			if ( isset( $section_data['score'] ) ) {
				$head .= ' (' . Arr::get_int( $section_data, 'score' ) . ' / 100)';
			}
			$out[]  = $head;
			$out[]  = '';
			$issues = array();
			$passed = array();
			foreach ( $rows as $row ) {
				if ( Result::OK === ( $row['severity'] ?? '' ) ) {
					$passed[] = self::md_text( Arr::get_string( $row, 'title' ) );
				} else {
					$issues[] = $row;
				}
			}
			foreach ( self::sort_issues( $issues ) as $row ) {
				$out[]   = '### ' . self::severity_label( Arr::get_string( $row, 'severity' ) ) . ': ' . self::md_text( Arr::get_string( $row, 'title' ) );
				$out[]   = '';
				$out[]   = self::md_text( Arr::get_string( $row, 'summary' ) );
				$out[]   = '';
				$hint    = self::md_text( Arr::get_string( $row, 'fix_hint' ) );
				$details = Arr::get_array( $row, 'details' );
				if ( '' !== $hint ) {
					$out[] = '**' . __( 'What to do:', 'store-audit-for-woocommerce' ) . '** ' . $hint;
					$out[] = '';
				}
				foreach ( $details as $line ) {
					$out[] = '- ' . self::md_text( Arr::to_string( $line ) );
				}
				if ( array() !== $details ) {
					$out[] = '';
				}
			}
			if ( array() !== $passed ) {
				$out[] = '**' . __( 'Passed checks', 'store-audit-for-woocommerce' ) . '**';
				$out[] = '';
				foreach ( $passed as $title ) {
					$out[] = '- ' . $title;
				}
				$out[] = '';
			}
		}

		$out[] = '---';
		$out[] = '';
		$out[] = self::attribution_markdown();
		return implode( "\n", $out ) . "\n";
	}
}
