<?php
/**
 * Report assembly and storage.
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
 * Builds and stores reports.
 */
final class Report {

	public const OPTION = 'store_audit_last_report';

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
			'results'        => array_values( $results ),
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
	 * Last stored report.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_last(): ?array {
		$report = get_option( self::OPTION, null );
		return is_array( $report ) && isset( $report['results'] ) ? $report : null;
	}
}
