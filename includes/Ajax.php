<?php
/**
 * AJAX endpoints for the step-by-step runner.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Checks\Section;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Nonce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-only actions: start, run_check, finish and markdown.
 */
final class Ajax {

	public const NONCE_ACTION  = 'store_audit';
	public const RUN_OPTION    = 'store_audit_current_run';
	public const RESULT_PREFIX = 'store_audit_run_result_';

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
	 * Nonce and capability gate. Sends an error response and exits when it fails, and only then reads the request.
	 * Posted values are accepted as plain strings only; an array (for example run_id[]=x) counts as empty.
	 *
	 * @return array{run_id:string,check_id:string}
	 */
	private function authorized_request(): array {
		if ( ! Nonce::is_plain( 'nonce' ) || false === check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'store-audit-for-woocommerce' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to run the audit.', 'store-audit-for-woocommerce' ) ), 403 );
		}

		$run_id   = isset( $_POST['run_id'] ) && is_string( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$check_id = isset( $_POST['check_id'] ) && is_string( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : '';
		return array(
			'run_id'   => $run_id,
			'check_id' => $check_id,
		);
	}

	/**
	 * Currently stored run, if it matches the id. The run option holds only the id; each result lives in its own
	 * option (see result_option), so two requests saving at the same time cannot overwrite each other.
	 *
	 * @param string $run_id Run id.
	 */
	private function run_is_active( string $run_id ): bool {
		$run = get_option( self::RUN_OPTION, null );
		return '' !== $run_id && is_array( $run ) && Arr::get_string( $run, 'run_id' ) === $run_id;
	}

	/**
	 * Name of the option that holds the result of one check of the current run.
	 *
	 * @param string $check_id Check id.
	 */
	private function result_option( string $check_id ): string {
		return self::RESULT_PREFIX . $check_id;
	}

	/**
	 * Drop the stored results of any run.
	 */
	private function clear_results(): void {
		foreach ( array_keys( Registry::all() ) as $check_id ) {
			delete_option( $this->result_option( $check_id ) );
		}
	}

	/**
	 * Start a run.
	 */
	public function start(): void {
		$this->authorized_request();

		$run_id = wp_generate_uuid4();
		$this->clear_results();
		update_option(
			self::RUN_OPTION,
			array(
				'run_id' => $run_id,
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
		$request  = $this->authorized_request();
		$check_id = $request['check_id'];
		if ( ! $this->run_is_active( $request['run_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This audit run is no longer active. Start it again.', 'store-audit-for-woocommerce' ) ), 409 );
		}
		$check = Registry::get( $check_id );
		if ( null === $check ) {
			wp_send_json_error( array( 'message' => __( 'Unknown check.', 'store-audit-for-woocommerce' ) ), 404 );
		}

		$result = ( new Runner() )->run_one( $check );
		// One option per check: a parallel request for another check writes a different row.
		update_option(
			$this->result_option( $check_id ),
			array(
				'run_id' => $request['run_id'],
				'result' => $result,
			),
			false
		);

		wp_send_json_success( $result );
	}

	/**
	 * Finish a run and build the report. Checks that did not run are listed as "not checked", so the report
	 * never looks complete when it is not.
	 */
	public function finish(): void {
		$request = $this->authorized_request();
		if ( ! $this->run_is_active( $request['run_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This audit run is no longer active. Start it again.', 'store-audit-for-woocommerce' ) ), 409 );
		}

		$runner  = new Runner();
		$results = array();
		$ran     = 0;
		foreach ( Registry::all() as $check_id => $check ) {
			$stored = get_option( $this->result_option( $check_id ), null );
			$row    = is_array( $stored ) && Arr::get_string( $stored, 'run_id' ) === $request['run_id'] ? Report::normalize_row( $stored['result'] ?? null ) : null;
			if ( null === $row ) {
				$results[] = $runner->not_run( $check );
				continue;
			}
			++$ran;
			$results[] = $row;
		}
		if ( 0 === $ran ) {
			wp_send_json_error( array( 'message' => __( 'No check has run in this audit yet. Start it again.', 'store-audit-for-woocommerce' ) ), 409 );
		}

		$report = Report::build( $results );
		Report::store_last( $report );
		$this->clear_results();
		delete_option( self::RUN_OPTION );

		$report['generated_at_local'] = Report::local_date( Arr::get_string( $report, 'generated_at' ) );
		wp_send_json_success( $report );
	}

	/**
	 * The last stored report as Markdown, for the copy button.
	 */
	public function markdown(): void {
		$this->authorized_request();

		$report = Report::get_last();
		if ( null === $report ) {
			wp_send_json_error( array( 'message' => __( 'There is no audit report yet. Run the audit first.', 'store-audit-for-woocommerce' ) ), 404 );
		}
		wp_send_json_success( array( 'markdown' => Report::to_markdown( $report ) ) );
	}
}
