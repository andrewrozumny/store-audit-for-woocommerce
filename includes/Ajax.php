<?php
/**
 * AJAX endpoints for the step-by-step runner.
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
 * Admin-only actions: start, run_check, finish and markdown.
 */
final class Ajax {

	public const NONCE_ACTION = 'store_audit';
	public const RUN_OPTION   = 'store_audit_current_run';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_store_audit_start', array( $this, 'start' ) );
		add_action( 'wp_ajax_store_audit_run_check', array( $this, 'run_check' ) );
		add_action( 'wp_ajax_store_audit_finish', array( $this, 'finish' ) );
		add_action( 'wp_ajax_store_audit_markdown', array( $this, 'markdown' ) );
	}

	/**
	 * Nonce and capability gate. Sends an error response and exits when it fails.
	 */
	private function authorize(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'store-audit-for-woocommerce' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to run the audit.', 'store-audit-for-woocommerce' ) ), 403 );
		}
	}

	/**
	 * Posted run id, sanitized.
	 */
	private function posted_run_id(): string {
		// Nonce is verified in authorize().
		return isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Currently stored run, if it matches the id.
	 *
	 * @param string $run_id Run id.
	 * @return array{run_id:string,results:array<string,array<string,mixed>>}|null
	 */
	private function load_run( string $run_id ): ?array {
		$run = get_option( self::RUN_OPTION, null );
		if ( '' === $run_id || ! is_array( $run ) || ( $run['run_id'] ?? '' ) !== $run_id ) {
			return null;
		}
		if ( ! isset( $run['results'] ) || ! is_array( $run['results'] ) ) {
			$run['results'] = array();
		}
		return $run;
	}

	/**
	 * Start a run.
	 */
	public function start(): void {
		$this->authorize();

		$run_id = wp_generate_uuid4();
		update_option(
			self::RUN_OPTION,
			array(
				'run_id'  => $run_id,
				'results' => array(),
			),
			false
		);

		$sections = array();
		foreach ( Section::labels() as $id => $label ) {
			$sections[] = array(
				'id'    => $id,
				'label' => $label,
			);
		}
		$checks = array();
		foreach ( Registry::all() as $check ) {
			$checks[] = array(
				'id'      => $check->id(),
				'title'   => $check->title(),
				'section' => $check->section(),
			);
		}

		wp_send_json_success(
			array(
				'run_id'   => $run_id,
				'sections' => $sections,
				'checks'   => $checks,
			)
		);
	}

	/**
	 * Run a single check.
	 */
	public function run_check(): void {
		$this->authorize();

		$run_id   = $this->posted_run_id();
		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['check_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$run      = $this->load_run( $run_id );
		if ( null === $run ) {
			wp_send_json_error( array( 'message' => __( 'This audit run is no longer active. Start it again.', 'store-audit-for-woocommerce' ) ), 409 );
		}
		$check = Registry::get( $check_id );
		if ( null === $check ) {
			wp_send_json_error( array( 'message' => __( 'Unknown check.', 'store-audit-for-woocommerce' ) ), 404 );
		}

		$result                      = ( new Runner() )->run_one( $check );
		$run['results'][ $check_id ] = $result;
		update_option( self::RUN_OPTION, $run, false );

		wp_send_json_success( $result );
	}

	/**
	 * Finish a run and build the report.
	 */
	public function finish(): void {
		$this->authorize();

		$run = $this->load_run( $this->posted_run_id() );
		if ( null === $run ) {
			wp_send_json_error( array( 'message' => __( 'This audit run is no longer active. Start it again.', 'store-audit-for-woocommerce' ) ), 409 );
		}

		$report = Report::build( array_values( $run['results'] ) );
		Report::store_last( $report );
		delete_option( self::RUN_OPTION );

		$report['generated_at_local'] = Report::local_date( (string) $report['generated_at'] );
		wp_send_json_success( $report );
	}

	/**
	 * The last stored report as Markdown, for the copy button.
	 */
	public function markdown(): void {
		$this->authorize();

		$report = Report::get_last();
		if ( null === $report ) {
			wp_send_json_error( array( 'message' => __( 'There is no audit report yet. Run the audit first.', 'store-audit-for-woocommerce' ) ), 404 );
		}
		wp_send_json_success( array( 'markdown' => Report::to_markdown( $report ) ) );
	}
}
