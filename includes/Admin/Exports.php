<?php
/**
 * Report export endpoints: JSON download and the printable view (the template is PrintView).
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Admin;

use StoreAudit\JsonFormatter;
use StoreAudit\Report;
use StoreAudit\ReportStore;
use StoreAudit\Support\Nonce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two admin-post endpoints that read the last stored report. Both need a nonce and manage_options.
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export the audit report.', 'store-audit-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		if ( ! Nonce::is_plain( '_wpnonce' ) || false === check_admin_referer( self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Reload the page and try again.', 'store-audit-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * The last report, or ends the request with a friendly message.
	 *
	 * @return array<string,mixed>
	 */
	private function report_or_die(): array {
		$report = ReportStore::get_last();
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
		$json   = JsonFormatter::format( $report );

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

		PrintView::print_document( $report );
		exit;
	}

	/**
	 * Full HTML document for a report, as a string. See PrintView.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function render_document( array $report ): string {
		return PrintView::render_document( $report );
	}

	/**
	 * Output the full HTML document for a report. See PrintView.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function print_document( array $report ): void {
		PrintView::print_document( $report );
	}

	/**
	 * Report body markup, as a string. See PrintView.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function render_body( array $report ): string {
		return PrintView::render_body( $report );
	}

	/**
	 * Output the report body markup. See PrintView.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	public static function print_body( array $report ): void {
		PrintView::print_body( $report );
	}

	/**
	 * Color class for a score. See PrintView.
	 *
	 * @param int $score Score.
	 */
	public static function score_class( int $score ): string {
		return PrintView::score_class( $score );
	}
}
