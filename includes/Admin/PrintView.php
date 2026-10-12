<?php
/**
 * Printable HTML view of a report.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Admin;

use StoreAudit\Report;
use StoreAudit\ReportView;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The HTML template of the printable report. Every dynamic value is escaped where it is printed.
 */
final class PrintView {

	/**
	 * Full HTML document for a report, as a string.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function render_document( array $report ): string {
		ob_start();
		self::print_document( $report );
		return (string) ob_get_clean();
	}

	/**
	 * Output the full HTML document for a report. Every dynamic value is escaped where it is printed.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function print_document( array $report ): void {
		$title = sprintf(
			/* translators: %s: site address. */
			__( 'Store Audit report for %s', 'store-audit-for-woocommerce' ),
			Arr::get_string( $report, 'site_url' )
		);

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
		<?php wp_print_styles( 'store-audit-print' ); ?>
</head>
<body class="store-audit-print">
<main class="sap-page">
		<?php self::print_body( $report ); ?>
</main>
		<?php wp_print_scripts( 'store-audit-print' ); ?>
</body>
</html>
		<?php
	}

	/**
	 * Report body markup, as a string. Every dynamic value is escaped.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function render_body( array $report ): string {
		ob_start();
		self::print_body( $report );
		return (string) ob_get_clean();
	}

	/**
	 * Output the report body markup. Every dynamic value is escaped where it is printed.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function print_body( array $report ): void {
		$report   = Report::redact_for_export( $report );
		$score    = Arr::get_map( $report, 'score' );
		$sections = Arr::get_map( $score, 'sections' );

		self::print_header( $report, $score );
		self::print_score_table( $sections );

		?>
		<?php foreach ( ReportView::group_by_section( $report ) as $id => $rows ) : ?>
			<?php self::print_section( ReportView::section_label( Arr::get_map( $sections, $id ), $id ), $rows ); ?>
		<?php endforeach; ?>

<?php self::print_footer(); // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- Keeps the output whitespace of the template. ?>
		<?php
	}

	/**
	 * Color class for a score.
	 *
	 * @param int $score Score.
	 */
	public static function score_class( int $score ): string {
		if ( $score >= 80 ) {
			return 'is-good';
		}
		return $score >= 50 ? 'is-fair' : 'is-poor';
	}

	/**
	 * Title block with the site, the date and the overall score.
	 *
	 * @param array<string,mixed> $report Report.
	 * @param array<string,mixed> $score  The `score` part of the report.
	 */
	private static function print_header( array $report, array $score ): void {
		$overall = Arr::get_int( $score, 'overall' );
		$when    = Report::local_date( Arr::get_string( $report, 'generated_at' ) );
		$note    = Report::incomplete_note( $score );

		?>
<header class="sap-header">
	<div>
		<h1><?php echo esc_html__( 'Store Audit report', 'store-audit-for-woocommerce' ); ?></h1>
		<p class="sap-meta"><?php echo esc_html( Arr::get_string( $report, 'site_url' ) ); ?><br><?php echo esc_html( $when ); ?></p>
	</div>
	<div class="sap-score <?php echo esc_attr( self::score_class( $overall ) ); ?>">
		<span class="sap-score-number"><?php echo esc_html( (string) $overall ); ?></span>
		<span class="sap-score-label"><?php echo esc_html__( 'out of 100', 'store-audit-for-woocommerce' ); ?></span>
		<?php if ( '' !== $note ) : ?>
		<span class="sap-score-note"><?php echo esc_html( $note ); ?></span>
		<?php endif; ?>
	</div>
	<button type="button" class="sap-button" id="store-audit-print-button"><?php echo esc_html__( 'Print / Save as PDF', 'store-audit-for-woocommerce' ); ?></button>
</header>

<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- Keeps the output whitespace of the template.
	}

	/**
	 * The scores-by-section table.
	 *
	 * @param array<string,mixed> $sections Section scores of the report.
	 */
	private static function print_score_table( array $sections ): void {
		?>
<table class="sap-table">
	<thead>
		<tr>
			<th><?php echo esc_html__( 'Section', 'store-audit-for-woocommerce' ); ?></th>
			<th><?php echo esc_html__( 'Score', 'store-audit-for-woocommerce' ); ?></th>
			<th><?php echo esc_html__( 'Critical', 'store-audit-for-woocommerce' ); ?></th>
			<th><?php echo esc_html__( 'Warnings', 'store-audit-for-woocommerce' ); ?></th>
			<th><?php echo esc_html__( 'Info', 'store-audit-for-woocommerce' ); ?></th>
			<th><?php echo esc_html__( 'Passed', 'store-audit-for-woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $sections as $id => $raw_data ) : ?>
			<?php self::print_score_row( $id, $raw_data ); ?>
		<?php endforeach; ?>
	</tbody>
</table>

<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- Keeps the output whitespace of the template.
	}

	/**
	 * One row of the scores table.
	 *
	 * @param string|int $id       Section id.
	 * @param mixed      $raw_data Score data of the section.
	 */
	private static function print_score_row( string|int $id, mixed $raw_data ): void {
		$data   = Arr::to_string_keyed( $raw_data );
		$counts = Arr::get_map( $data, 'counts' );

		?>
		<tr>
			<td><?php echo esc_html( ReportView::section_label( $data, $id ) ); ?></td>
			<td><strong><?php echo esc_html( isset( $data['score'] ) ? (string) Arr::get_int( $data, 'score' ) : __( 'incomplete', 'store-audit-for-woocommerce' ) ); ?></strong></td>
			<td><?php echo esc_html( (string) Arr::get_int( $counts, Result::CRITICAL ) ); ?></td>
			<td><?php echo esc_html( (string) Arr::get_int( $counts, Result::WARNING ) ); ?></td>
			<td><?php echo esc_html( (string) Arr::get_int( $counts, Result::INFO ) ); ?></td>
			<td><?php echo esc_html( (string) Arr::get_int( $counts, Result::OK ) ); ?></td>
		</tr>
<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- Keeps the output whitespace of the template.
	}

	/**
	 * One section: its problems worst first, then the passed checks.
	 *
	 * @param string                    $label Section name.
	 * @param list<array<string,mixed>> $rows  Rows of the section.
	 */
	private static function print_section( string $label, array $rows ): void {
		$split  = ReportView::split_section( $rows );
		$passed = $split['passed'];

		?>
<section class="sap-section">
	<h2><?php echo esc_html( $label ); ?></h2>
			<?php foreach ( $split['issues'] as $row ) : ?>
				<?php self::print_issue( $row ); ?>
		<?php endforeach; ?>
			<?php if ( array() !== $passed ) : ?>
	<div class="sap-passed">
		<h3><?php echo esc_html__( 'Passed checks', 'store-audit-for-woocommerce' ); ?></h3>
		<ul>
				<?php foreach ( $passed as $title ) : ?>
			<li><?php echo esc_html( $title ); ?></li>
		<?php endforeach; ?>
		</ul>
	</div>
		<?php endif; ?>
</section>
<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- Keeps the output whitespace of the template.
	}

	/**
	 * One problem: badge and title, summary, the fix hint and the detail lines.
	 *
	 * @param array<string,mixed> $row Result row.
	 */
	private static function print_issue( array $row ): void {
		$severity = Arr::get_string( $row, 'severity' );
		$details  = Arr::get_array( $row, 'details' );

		?>
	<article class="sap-issue sap-<?php echo esc_attr( $severity ); ?>">
		<h3><span class="sap-badge"><?php echo esc_html( ReportView::severity_label( $severity ) ); ?></span> <?php echo esc_html( Arr::get_string( $row, 'title' ) ); ?></h3>
		<p><?php echo esc_html( Arr::get_string( $row, 'summary' ) ); ?></p>
				<?php if ( '' !== Arr::get_string( $row, 'fix_hint' ) ) : ?>
		<p><strong><?php echo esc_html__( 'What to do:', 'store-audit-for-woocommerce' ); ?></strong> <?php echo esc_html( Arr::get_string( $row, 'fix_hint' ) ); ?></p>
		<?php endif; ?>
				<?php if ( array() !== $details ) : ?>
		<ul>
					<?php foreach ( $details as $line ) : ?>
			<li><?php echo esc_html( Arr::to_string( $line ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
	</article>
<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- Keeps the output whitespace of the template.
	}

	/**
	 * Footer with the attribution, unless it is switched off.
	 */
	private static function print_footer(): void {
		?>
<footer class="sap-footer">
		<?php
		if ( Report::show_attribution() ) {
			echo wp_kses(
				Report::attribution_html(),
				array(
					'a' => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
				)
			);
		}
		?>
							</footer>
<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect -- Keeps the output whitespace of the template.
	}
}
