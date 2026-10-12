<?php
/**
 * JSON export of a report.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a report into pretty-printed JSON.
 */
final class JsonFormatter {

	/**
	 * Pretty-printed JSON export with the attribution field. Details of sensitive checks are left out unless
	 * the full data is asked for (command line).
	 *
	 * @param array<string,mixed> $report Report.
	 * @param bool                $redact Leave out the details of sensitive checks.
	 */
	public static function format( array $report, bool $redact = true ): string {
		if ( $redact ) {
			$report = Report::redact_for_export( $report );
		}
		$data = Report::show_attribution() ? array_merge( array( 'generated_by' => Report::attribution() ), $report ) : $report;
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}
}
