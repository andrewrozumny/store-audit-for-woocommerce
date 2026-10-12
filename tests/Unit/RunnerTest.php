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
		$this->assertSame( 'Could not check: boom', $result['summary'] );
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
}
