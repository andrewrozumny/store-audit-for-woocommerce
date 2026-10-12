<?php
/**
 * Dashboard widget with the last score and the top problems.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Admin;

use StoreAudit\Report;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Store Audit" widget on the WordPress dashboard.
 */
final class DashboardWidget {

	public const WIDGET_ID = 'store_audit_dashboard_widget';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the widget for shop managers and admins only.
	 */
	public function add_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$callback = array( $this, 'render' );
		wp_add_dashboard_widget( self::WIDGET_ID, __( 'Store Audit', 'store-audit-for-woocommerce' ), $callback );
	}

	/**
	 * Small inline style, dashboard only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( 'index.php' !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$css = '#' . self::WIDGET_ID . ' .sa-widget-score{display:flex;align-items:baseline;gap:8px;margin:0 0 4px}'
			. '#' . self::WIDGET_ID . ' .sa-widget-number{font-size:44px;font-weight:700;line-height:1}'
			. '#' . self::WIDGET_ID . ' .is-good{color:#1e7e34}#' . self::WIDGET_ID . ' .is-fair{color:#b26200}#' . self::WIDGET_ID . ' .is-poor{color:#b32d2e}'
			. '#' . self::WIDGET_ID . ' .sa-widget-note{color:#b26200;font-weight:600;margin:0 0 4px}'
			. '#' . self::WIDGET_ID . ' .sa-widget-date{color:#50575e;margin:0 0 12px}'
			. '#' . self::WIDGET_ID . ' .sa-widget-list{margin:0 0 12px;padding:0;list-style:none}'
			. '#' . self::WIDGET_ID . ' .sa-widget-list li{margin:0 0 8px;padding-left:10px;border-left:4px solid #8c8f94}'
			. '#' . self::WIDGET_ID . ' .sa-widget-list li.sa-critical{border-left-color:#b32d2e}'
			. '#' . self::WIDGET_ID . ' .sa-widget-list li.sa-warning{border-left-color:#dba617}'
			. '#' . self::WIDGET_ID . ' .sa-widget-list span{display:block;color:#50575e}';
		wp_add_inline_style( 'dashboard', $css );
	}

	/**
	 * The worst problems first: critical, then warning, then error. Keeps the report order inside a severity.
	 *
	 * @param array<mixed> $results Result rows from a report.
	 * @param int          $limit   How many to return.
	 * @return list<array<string,mixed>>
	 */
	public static function top_problems( array $results, int $limit = 3 ): array {
		$rows = array();
		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row = Arr::to_string_keyed( $row );
			if ( in_array( $row['severity'] ?? '', array( Result::CRITICAL, Result::WARNING, Result::ERROR ), true ) ) {
				$rows[] = $row;
			}
		}
		return array_slice( Report::sort_issues( $rows ), 0, max( 0, $limit ) );
	}

	/**
	 * Widget body.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page   = admin_url( 'admin.php?page=' . Page::SLUG );
		$report = Report::get_last();

		if ( null === $report ) {
			echo '<p>' . esc_html__( 'The store has not been audited yet. One click checks security, settings, performance and environment.', 'store-audit-for-woocommerce' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $page ) . '">' . esc_html__( 'Run your first audit', 'store-audit-for-woocommerce' ) . '</a></p>';
			return;
		}

		$score_data = Arr::get_map( $report, 'score' );
		$score      = Arr::get_int( $score_data, 'overall' );
		$note       = Report::incomplete_note( $score_data );
		$top        = self::top_problems( Arr::get_array( $report, 'results' ) );

		echo '<div class="sa-widget-score"><span class="sa-widget-number ' . esc_attr( Exports::score_class( $score ) ) . '">' . esc_html( (string) $score ) . '</span>';
		echo '<span>' . esc_html__( 'out of 100', 'store-audit-for-woocommerce' ) . '</span></div>';
		if ( '' !== $note ) {
			echo '<p class="sa-widget-note">' . esc_html( $note ) . '</p>';
		}
		echo '<p class="sa-widget-date">' . esc_html(
			sprintf(
				/* translators: %s: date and time of the last audit. */
				__( 'Last run: %s', 'store-audit-for-woocommerce' ),
				Report::local_date( Arr::get_string( $report, 'generated_at' ) )
			)
		) . '</p>';

		if ( array() === $top ) {
			echo '<p>' . esc_html__( 'No critical problems, warnings or failed checks. Good work.', 'store-audit-for-woocommerce' ) . '</p>';
		} else {
			echo '<ul class="sa-widget-list">';
			foreach ( $top as $row ) {
				echo '<li class="sa-' . esc_attr( Arr::get_string( $row, 'severity' ) ) . '"><strong>' . esc_html( Arr::get_string( $row, 'title' ) ) . '</strong><span>' . esc_html( Arr::get_string( $row, 'summary' ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '<p><a href="' . esc_url( $page ) . '">' . esc_html__( 'Open full report', 'store-audit-for-woocommerce' ) . '</a></p>';
	}
}
