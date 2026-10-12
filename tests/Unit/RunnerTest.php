<?php
/**
 * Runner tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Functions;
use StoreAudit\Result;
use StoreAudit\Runner;

/**
 * Tests for Runner.
 *
 * @covers \StoreAudit\Runner
 */
final class RunnerTest extends TestCase {

	/**
	 * Extra stubs.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_raise_memory_limit' )->justReturn( true );
	}

	/**
	 * A normal result is merged with check metadata.
	 */
	public function test_result_is_merged_with_metadata(): void {
		$check  = new FakeCheck( 'environment_fake', 'environment', static fn() => Result::warning( 'Careful', array( 'x' ) ) );
		$result = ( new Runner() )->run_one( $check );

		$this->assertSame( 'environment_fake', $result['id'] );
		$this->assertSame( 'environment', $result['section'] );
		$this->assertSame( 'Fake', $result['title'] );
		$this->assertSame( 'warning', $result['severity'] );
		$this->assertSame( 'Careful', $result['summary'] );
		$this->assertSame( array( 'x' ), $result['details'] );
		$this->assertSame( 'Because.', $result['explanation'] );
		$this->assertSame( 'Fix it.', $result['fix_hint'] );
		$this->assertIsInt( $result['duration_ms'] );
	}

	/**
	 * A fix hint on the result overrides the check's one.
	 */
	public function test_result_fix_hint_override(): void {
		$check  = new FakeCheck( 'environment_fake', 'environment', static fn() => Result::warning( 'Careful', array(), 'Custom hint' ) );
		$result = ( new Runner() )->run_one( $check );

		$this->assertSame( 'Custom hint', $result['fix_hint'] );
	}

	/**
	 * An exception becomes an error result instead of bubbling up.
	 */
	public function test_exception_becomes_error_result(): void {
		$check  = new FakeCheck(
			'environment_fake',
			'environment',
			static function (): Result {
				throw new \RuntimeException( 'boom' );
			}
		);
		$result = ( new Runner() )->run_one( $check );

		$this->assertSame( 'error', $result['severity'] );
		$this->assertSame( 'Could not check: an internal error occurred (details in the PHP error log when debugging is on).', $result['summary'] );
		$this->assertStringNotContainsString( 'boom', $result['summary'] );
		$this->assertSame( 'environment_fake', $result['id'] );
	}

	/**
	 * Fatal-class errors (TypeError etc.) are caught too.
	 */
	public function test_throwable_error_is_caught(): void {
		$check  = new FakeCheck(
			'environment_fake',
			'environment',
			static function (): Result {
				throw new \TypeError( 'bad type' );
			}
		);
		$result = ( new Runner() )->run_one( $check );

		$this->assertSame( 'error', $result['severity'] );
	}

	/**
	 * The raw exception text and any path in it never reach the report.
	 */
	public function test_error_summary_has_no_path(): void {
		$check  = new FakeCheck(
			'environment_fake',
			'environment',
			static function (): Result {
				throw new \RuntimeException( 'Cannot open /var/www/html/wp-config.php' );
			}
		);
		$result = ( new Runner() )->run_one( $check );

		$this->assertStringNotContainsString( '/var/www', $result['summary'] );
		$this->assertStringNotContainsString( 'wp-config', $result['summary'] );
	}

	/**
	 * Only a limit that is set and shorter than 30 seconds is raised; zero and longer limits are left alone.
	 */
	public function test_time_limit_is_only_ever_raised(): void {
		$this->assertNull( Runner::time_limit_to_set( 0 ), 'unlimited (CLI) must not become 30' );
		$this->assertNull( Runner::time_limit_to_set( 30 ) );
		$this->assertNull( Runner::time_limit_to_set( 120 ), 'a longer limit must never be lowered' );
		$this->assertSame( 30, Runner::time_limit_to_set( 10 ) );
		$this->assertSame( 30, Runner::time_limit_to_set( 1 ) );
		$this->assertNull( Runner::time_limit_to_set( -1 ) );
	}

	/**
	 * The sensitive flag of a check is stored in the row; checks that do not declare it are not sensitive.
	 */
	public function test_sensitive_flag_is_carried(): void {
		$plain     = ( new Runner() )->run_one( new FakeCheck() );
		$sensitive = ( new Runner() )->run_one( new SensitiveFakeCheck() );

		$this->assertFalse( $plain['sensitive'] );
		$this->assertTrue( $sensitive['sensitive'] );
		$this->assertFalse( ( new Runner() )->not_run( new SensitiveFakeCheck() )['sensitive'] );
	}
}
