<?php
/**
 * WP-CLI command.
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
	 *   - markdown
	 * ---
	 *
	 * [--save]
	 * : Store the report as the last report shown in the admin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp store-audit run --format=json
	 *     wp store-audit run --format=markdown > store-audit.md
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
			\WP_CLI::line( Report::to_json( $report ) );
			return;
		}

		if ( 'markdown' === $format ) {
			\WP_CLI::line( rtrim( Report::to_markdown( $report ) ) );
			return;
		}

		$labels = Checks\Section::labels();
		$rows   = array();
		foreach ( $results as $row ) {
			$rows[] = array(
				'section'  => $labels[ Arr::get_string( $row, 'section' ) ] ?? Arr::get_string( $row, 'section' ),
				'severity' => Arr::get_string( $row, 'severity' ),
				'title'    => Arr::get_string( $row, 'title' ),
				'summary'  => Arr::get_string( $row, 'summary' ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'section', 'severity', 'title', 'summary' ) );
		\WP_CLI::log( sprintf( 'Overall score: %d / 100', Arr::get_int( Arr::get_map( $report, 'score' ), 'overall' ) ) );
		\WP_CLI::log( Report::attribution() );
	}
}
