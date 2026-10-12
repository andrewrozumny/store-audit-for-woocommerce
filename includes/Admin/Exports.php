<?php
/**
 * Report exports: JSON download and the printable view.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Admin;

use StoreAudit\Report;
use StoreAudit\Result;

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
		if ( ! check_admin_referer( self::NONCE_ACTION ) ) {
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

		echo self::render_document( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in render_document().
		exit;
	}

	/**
	 * Full HTML document for a report.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function render_document( array $report ): string {
		$title = sprintf(
			/* translators: %s: site address. */
			__( 'Store Audit report for %s', 'store-audit-for-woocommerce' ),
			(string) ( $report['site_url'] ?? '' )
		);

		ob_start();
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
		<?php echo self::render_body( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_body(). ?>
</main>
		<?php wp_print_scripts( 'store-audit-print' ); ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Report body markup. Every dynamic value is escaped.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function render_body( array $report ): string {
		$labels   = \StoreAudit\Checks\Section::labels();
		$score    = is_array( $report['score'] ?? null ) ? $report['score'] : array();
		$sections = is_array( $score['sections'] ?? null ) ? $score['sections'] : array();
		$overall  = (int) ( $score['overall'] ?? 0 );
		$when     = Report::local_date( (string) ( $report['generated_at'] ?? '' ) );

		ob_start();
		?>
<header class="sap-header">
	<div>
		<h1><?php echo esc_html__( 'Store Audit report', 'store-audit-for-woocommerce' ); ?></h1>
		<p class="sap-meta"><?php echo esc_html( (string) ( $report['site_url'] ?? '' ) ); ?><br><?php echo esc_html( $when ); ?></p>
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
		<?php foreach ( $sections as $id => $data ) : ?>
			<?php $counts = is_array( $data['counts'] ?? null ) ? $data['counts'] : array(); ?>
		<tr>
			<td><?php echo esc_html( (string) ( $data['label'] ?? ( $labels[ $id ] ?? $id ) ) ); ?></td>
			<td><strong><?php echo esc_html( (string) (int) ( $data['score'] ?? 0 ) ); ?></strong></td>
			<td><?php echo esc_html( (string) (int) ( $counts[ Result::CRITICAL ] ?? 0 ) ); ?></td>
			<td><?php echo esc_html( (string) (int) ( $counts[ Result::WARNING ] ?? 0 ) ); ?></td>
			<td><?php echo esc_html( (string) (int) ( $counts[ Result::INFO ] ?? 0 ) ); ?></td>
			<td><?php echo esc_html( (string) (int) ( $counts[ Result::OK ] ?? 0 ) ); ?></td>
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
					$passed[] = (string) ( $row['title'] ?? '' );
				} else {
					$issues[] = $row;
				}
			}
			?>
<section class="sap-section">
	<h2><?php echo esc_html( (string) ( $sections[ $id ]['label'] ?? ( $labels[ $id ] ?? $id ) ) ); ?></h2>
			<?php foreach ( Report::sort_issues( $issues ) as $row ) : ?>
				<?php $severity = (string) ( $row['severity'] ?? '' ); ?>
	<article class="sap-issue sap-<?php echo esc_attr( $severity ); ?>">
		<h3><span class="sap-badge"><?php echo esc_html( Report::severity_label( $severity ) ); ?></span> <?php echo esc_html( (string) ( $row['title'] ?? '' ) ); ?></h3>
		<p><?php echo esc_html( (string) ( $row['summary'] ?? '' ) ); ?></p>
				<?php if ( '' !== (string) ( $row['fix_hint'] ?? '' ) ) : ?>
		<p><strong><?php echo esc_html__( 'What to do:', 'store-audit-for-woocommerce' ); ?></strong> <?php echo esc_html( (string) $row['fix_hint'] ); ?></p>
		<?php endif; ?>
				<?php if ( array() !== (array) ( $row['details'] ?? array() ) ) : ?>
		<ul>
					<?php foreach ( (array) $row['details'] as $line ) : ?>
			<li><?php echo esc_html( (string) $line ); ?></li>
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
		return (string) ob_get_clean();
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
