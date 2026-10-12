<?php
/**
 * Report exports: JSON download and the printable view.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Admin;

use StoreAudit\Checks\Section;
use StoreAudit\Report;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two admin-post endpoints that read the last stored report. Both need a nonce and manage_woocommerce.
 */
final class Exports {

	public const NONCE_ACTION = 'store_audit_export';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_store_audit_download', array( $this, 'download' ) );
		add_action( 'admin_post_store_audit_print', array( $this, 'print_view' ) );
	}

	/**
	 * URL of an export endpoint, with nonce.
	 *
	 * @param string $action Either store_audit_download or store_audit_print.
	 */
	public static function url( string $action ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), self::NONCE_ACTION );
	}

	/**
	 * Capability and nonce gate. Ends the request when it fails.
	 */
	public function authorize(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to export the audit report.', 'store-audit-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		if ( false === check_admin_referer( self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Reload the page and try again.', 'store-audit-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * The last report, or ends the request with a friendly message.
	 *
	 * @return array<string,mixed>
	 */
	private function report_or_die(): array {
		$report = Report::get_last();
		if ( null === $report ) {
			wp_die(
				esc_html__( 'There is no audit report yet. Run the audit first.', 'store-audit-for-woocommerce' ),
				'',
				array(
					'response'  => 404,
					'link_url'  => esc_url( admin_url( 'admin.php?page=store-audit' ) ),
					'link_text' => esc_html__( 'Go to Store Audit', 'store-audit-for-woocommerce' ),
				)
			);
		}
		return $report;
	}

	/**
	 * Send the last report as a JSON attachment.
	 */
	public function download(): void {
		$this->authorize();
		$report = $this->report_or_die();
		$json   = Report::to_json( $report );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . Report::filename( $report, 'json' ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON attachment, not HTML.
		exit;
	}

	/**
	 * Standalone printable HTML document.
	 */
	public function print_view(): void {
		$this->authorize();
		$report = $this->report_or_die();

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );

		wp_register_style( 'store-audit-print', STORE_AUDIT_URL . 'resources/print.css', array(), STORE_AUDIT_VERSION );
		wp_register_script( 'store-audit-print', STORE_AUDIT_URL . 'resources/print.js', array(), STORE_AUDIT_VERSION, true );

		self::print_document( $report );
		exit;
	}

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
		$labels   = Section::labels();
		$score    = Arr::get_map( $report, 'score' );
		$sections = Arr::get_map( $score, 'sections' );
		$overall  = Arr::get_int( $score, 'overall' );
		$when     = Report::local_date( Arr::get_string( $report, 'generated_at' ) );

		?>
<header class="sap-header">
	<div>
		<h1><?php echo esc_html__( 'Store Audit report', 'store-audit-for-woocommerce' ); ?></h1>
		<p class="sap-meta"><?php echo esc_html( Arr::get_string( $report, 'site_url' ) ); ?><br><?php echo esc_html( $when ); ?></p>
	</div>
	<div class="sap-score <?php echo esc_attr( self::score_class( $overall ) ); ?>">
		<span class="sap-score-number"><?php echo esc_html( (string) $overall ); ?></span>
		<span class="sap-score-label"><?php echo esc_html__( 'out of 100', 'store-audit-for-woocommerce' ); ?></span>
	</div>
	<button type="button" class="sap-button" id="store-audit-print-button"><?php echo esc_html__( 'Print / Save as PDF', 'store-audit-for-woocommerce' ); ?></button>
</header>

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
			<?php
			$data   = Arr::to_string_keyed( $raw_data );
			$counts = Arr::get_map( $data, 'counts' );
			?>
		<tr>
			<td><?php echo esc_html( Arr::get_string( $data, 'label', $labels[ $id ] ?? $id ) ); ?></td>
			<td><strong><?php echo esc_html( (string) Arr::get_int( $data, 'score' ) ); ?></strong></td>
			<td><?php echo esc_html( (string) Arr::get_int( $counts, Result::CRITICAL ) ); ?></td>
			<td><?php echo esc_html( (string) Arr::get_int( $counts, Result::WARNING ) ); ?></td>
			<td><?php echo esc_html( (string) Arr::get_int( $counts, Result::INFO ) ); ?></td>
			<td><?php echo esc_html( (string) Arr::get_int( $counts, Result::OK ) ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>

		<?php foreach ( Report::group_by_section( $report ) as $id => $rows ) : ?>
			<?php
			$issues = array();
			$passed = array();
			foreach ( $rows as $row ) {
				if ( Result::OK === ( $row['severity'] ?? '' ) ) {
					$passed[] = Arr::get_string( $row, 'title' );
				} else {
					$issues[] = $row;
				}
			}
			?>
<section class="sap-section">
	<h2><?php echo esc_html( Arr::get_string( Arr::get_map( $sections, $id ), 'label', $labels[ $id ] ?? $id ) ); ?></h2>
			<?php foreach ( Report::sort_issues( $issues ) as $row ) : ?>
				<?php $severity = Arr::get_string( $row, 'severity' ); ?>
	<article class="sap-issue sap-<?php echo esc_attr( $severity ); ?>">
		<h3><span class="sap-badge"><?php echo esc_html( Report::severity_label( $severity ) ); ?></span> <?php echo esc_html( Arr::get_string( $row, 'title' ) ); ?></h3>
		<p><?php echo esc_html( Arr::get_string( $row, 'summary' ) ); ?></p>
				<?php if ( '' !== Arr::get_string( $row, 'fix_hint' ) ) : ?>
		<p><strong><?php echo esc_html__( 'What to do:', 'store-audit-for-woocommerce' ); ?></strong> <?php echo esc_html( Arr::get_string( $row, 'fix_hint' ) ); ?></p>
		<?php endif; ?>
				<?php if ( array() !== Arr::get_array( $row, 'details' ) ) : ?>
		<ul>
					<?php foreach ( Arr::get_array( $row, 'details' ) as $line ) : ?>
			<li><?php echo esc_html( Arr::to_string( $line ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
	</article>
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
		<?php endforeach; ?>

<footer class="sap-footer">
		<?php
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
		?>
							</footer>
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
}
