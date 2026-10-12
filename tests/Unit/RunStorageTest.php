<?php
/**
 * Audit run storage tests: concurrency, incomplete runs, stale runs.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use StoreAudit\Ajax;
use StoreAudit\Result;

/**
 * Tests for how Ajax keeps the results of a run.
 *
 * @covers \StoreAudit\Ajax
 */
final class RunStorageTest extends TestCase {

	use OptionStoreTrait;

	/**
	 * Stub WordPress and register three fake checks.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_option_store();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->alias( static fn(): string => uniqid( 'run-', true ) );
		Functions\when( 'site_url' )->justReturn( 'https://shop.example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1' );
		Functions\when( 'wp_date' )->justReturn( '12 Oct 2026' );
		Filters\expectApplied( 'store_audit_checks' )->zeroOrMoreTimes()->andReturnUsing(
			static fn(): array => array(
				new FakeCheck( 'environment_a', 'environment' ),
				new FakeCheck( 'security_b', 'security', static fn(): Result => Result::warning( 'B is risky' ) ),
				new FakeCheck( 'plugins_c', 'plugins' ),
			)
		);
	}

	/**
	 * Reset the superglobal after each test.
	 */
	protected function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Call an endpoint and return what it answered.
	 *
	 * @param string               $method Ajax method.
	 * @param array<string,string> $post   Posted values.
	 */
	private function call( string $method, array $post = array() ): JsonResponse {
		$_POST = $post;
		try {
			( new Ajax() )->$method();
		} catch ( JsonResponse $response ) {
			return $response;
		}
		$this->fail( 'The endpoint did not answer.' );
	}

	/**
	 * Start a run and return its id.
	 */
	private function start_run(): string {
		$start = $this->call( 'start' );
		$this->assertTrue( $start->success );
		$data = $start->payload;
		$this->assertIsArray( $data );
		$this->assertIsString( $data['run_id'] );
		return $data['run_id'];
	}

	/**
	 * Ids of the results in a finished report.
	 *
	 * @param JsonResponse $finish Response of finish.
	 * @return list<string>
	 */
	private function result_ids( JsonResponse $finish ): array {
		$report = $finish->payload;
		$this->assertIsArray( $report );
		$ids = array();
		foreach ( (array) $report['results'] as $row ) {
			$this->assertIsArray( $row );
			$ids[] = (string) $row['id'];
		}
		return $ids;
	}

	/**
	 * Two checks saved by overlapping requests must both survive. The second request here read the run
	 * before the first one saved, which is what two parallel requests do; with one shared option the
	 * second save wiped the first result.
	 */
	public function test_overlapping_run_check_requests_do_not_lose_results(): void {
		$run_id   = $this->start_run();
		$snapshot = $this->options;

		$this->assertSame(
			200,
			$this->call(
				'run_check',
				array(
					'run_id'   => $run_id,
					'check_id' => 'environment_a',
				)
			)->status
		);

		// The second request started from the state before the first one saved.
		foreach ( array_keys( $snapshot ) as $name ) {
			$this->options[ $name ] = $snapshot[ $name ];
		}
		$this->assertSame(
			200,
			$this->call(
				'run_check',
				array(
					'run_id'   => $run_id,
					'check_id' => 'security_b',
				)
			)->status
		);

		$finish = $this->call( 'finish', array( 'run_id' => $run_id ) );
		$this->assertTrue( $finish->success );
		$ids = $this->result_ids( $finish );
		$this->assertContains( 'environment_a', $ids, 'the first result was lost' );
		$this->assertContains( 'security_b', $ids );
	}

	/**
	 * A report built from an incomplete run lists the missing checks as not checked, so the score is
	 * never presented as if those checks had passed.
	 */
	public function test_finish_marks_missing_checks_as_not_checked(): void {
		$run_id = $this->start_run();
		$this->call(
			'run_check',
			array(
				'run_id'   => $run_id,
				'check_id' => 'security_b',
			)
		);

		$finish = $this->call( 'finish', array( 'run_id' => $run_id ) );
		$this->assertTrue( $finish->success );
		$report = $finish->payload;
		$this->assertIsArray( $report );
		$this->assertSame( array( 'environment_a', 'security_b', 'plugins_c' ), $this->result_ids( $finish ) );

		$by_id = array();
		foreach ( (array) $report['results'] as $row ) {
			$this->assertIsArray( $row );
			$by_id[ (string) $row['id'] ] = $row;
		}
		$this->assertSame( Result::ERROR, $by_id['environment_a']['severity'] );
		$this->assertSame( Result::ERROR, $by_id['plugins_c']['severity'] );
		$this->assertSame( Result::WARNING, $by_id['security_b']['severity'] );
		$this->assertNotSame( '', $by_id['plugins_c']['summary'] );
		$score = $report['score'];
		$this->assertIsArray( $score );
		$this->assertArrayNotHasKey( 'environment', (array) $score['sections'], 'a section with nothing checked must have no score' );
	}

	/**
	 * Finishing a run in which nothing ran must not replace the last good report.
	 */
	public function test_finish_with_no_results_is_rejected(): void {
		$this->options['store_audit_last_report'] = array( 'marker' => 'keep me' );
		$run_id                                   = $this->start_run();

		$finish = $this->call( 'finish', array( 'run_id' => $run_id ) );

		$this->assertFalse( $finish->success );
		$this->assertSame( 409, $finish->status );
		$this->assertSame( array( 'marker' => 'keep me' ), $this->options['store_audit_last_report'] );
	}

	/**
	 * Starting a second run drops the first run and its results.
	 */
	public function test_new_run_replaces_the_old_one(): void {
		$first = $this->start_run();
		$this->call(
			'run_check',
			array(
				'run_id'   => $first,
				'check_id' => 'environment_a',
			)
		);
		$second = $this->start_run();

		$late = $this->call(
			'run_check',
			array(
				'run_id'   => $first,
				'check_id' => 'plugins_c',
			)
		);
		$this->assertSame( 409, $late->status );

		$this->call(
			'run_check',
			array(
				'run_id'   => $second,
				'check_id' => 'security_b',
			)
		);
		$finish = $this->call( 'finish', array( 'run_id' => $second ) );
		$report = $finish->payload;
		$this->assertIsArray( $report );
		foreach ( (array) $report['results'] as $row ) {
			$this->assertIsArray( $row );
			if ( 'environment_a' === $row['id'] ) {
				$this->assertSame( Result::ERROR, $row['severity'], 'a result of the replaced run leaked into the new one' );
			}
		}
	}

	/**
	 * A finished run leaves no temporary rows behind.
	 */
	public function test_finish_cleans_up_temporary_rows(): void {
		$run_id = $this->start_run();
		$this->call(
			'run_check',
			array(
				'run_id'   => $run_id,
				'check_id' => 'environment_a',
			)
		);
		$this->call( 'finish', array( 'run_id' => $run_id ) );

		$left = array_keys( $this->options );
		sort( $left );
		$this->assertSame( array( 'store_audit_last_report' ), $left );
	}

	/**
	 * A damaged stored run (wrong types) is treated as no active run, never as a fatal error.
	 */
	public function test_damaged_run_option_is_not_fatal(): void {
		foreach ( array( 'text', 42, array(), array( 'run_id' => array( 'x' ) ) ) as $damaged ) {
			$this->options['store_audit_current_run'] = $damaged;
			$this->assertSame(
				409,
				$this->call(
					'run_check',
					array(
						'run_id'   => 'r1',
						'check_id' => 'environment_a',
					)
				)->status
			);
			$this->assertSame( 409, $this->call( 'finish', array( 'run_id' => 'r1' ) )->status );
		}
	}
}
