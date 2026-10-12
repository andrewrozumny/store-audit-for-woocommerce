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
			ReportStore::store_last( $report );
		}

		if ( 'json' === $format ) {
			\WP_CLI::line( JsonFormatter::format( $report, false ) );
			return;
		}

		if ( 'markdown' === $format ) {
			\WP_CLI::line( rtrim( MarkdownFormatter::format( $report, false ) ) );
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
		$score = Arr::get_map( $report, 'score' );
		$note  = Report::incomplete_note( $score );
		\WP_CLI::log(
			sprintf(
				/* translators: %d: overall score. */
				__( 'Overall score: %d / 100', 'store-audit-for-woocommerce' ),
				Arr::get_int( $score, 'overall' )
			) . ( '' !== $note ? ' (' . $note . ')' : '' )
		);
		foreach ( Arr::get_map( $score, 'sections' ) as $id => $section ) {
			$section = Arr::to_string_keyed( $section );
			if ( Arr::get_bool( $section, 'incomplete' ) ) {
				\WP_CLI::log( ( $labels[ $id ] ?? $id ) . ': ' . Score::incomplete_note( Arr::get_int( $section, 'checked' ), Arr::get_int( $section, 'total' ) ) );
			}
		}
		if ( Report::show_attribution() ) {
			\WP_CLI::log( Report::attribution() );
		}
	}
}
