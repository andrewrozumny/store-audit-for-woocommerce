<?php
/**
 * WP-CLI command.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the audit from the command line.
 */
final class Cli {

	/**
	 * Register the command.
	 */
	public static function register(): void {
		\WP_CLI::add_command( 'store-audit run', array( new self(), 'run' ) );
	}

	/**
	 * Run the store audit (read-only).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--save]
	 * : Store the report as the last report shown in the admin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp store-audit run --format=json
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );
		$format  = $assoc_args['format'] ?? 'table';
		$results = ( new Runner() )->run_all();
		$report  = Report::build( $results );

		if ( isset( $assoc_args['save'] ) ) {
			Report::store_last( $report );
		}

		if ( 'json' === $format ) {
			\WP_CLI::print_value( $report, array( 'format' => 'json' ) );
			return;
		}

		$labels = Checks\Section::labels();
		$rows   = array();
		foreach ( $results as $row ) {
			$rows[] = array(
				'section'  => $labels[ $row['section'] ] ?? $row['section'],
				'severity' => $row['severity'],
				'title'    => $row['title'],
				'summary'  => $row['summary'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'section', 'severity', 'title', 'summary' ) );
		\WP_CLI::log( sprintf( 'Overall score: %d / 100', $report['score']['overall'] ) );
	}
}
