<?php
/**
 * Result tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use StoreAudit\Result;

/**
 * Tests for Result.
 *
 * @covers \StoreAudit\Result
 */
final class ResultTest extends TestCase {

	/**
	 * Named constructors set the right severity.
	 */
	public function test_named_constructors_set_severity(): void {
		$this->assertSame( 'critical', Result::critical( 'x' )->severity() );
		$this->assertSame( 'warning', Result::warning( 'x' )->severity() );
		$this->assertSame( 'info', Result::info( 'x' )->severity() );
		$this->assertSame( 'ok', Result::ok( 'x' )->severity() );
		$this->assertSame( 'error', Result::error( 'x' )->severity() );
	}

	/**
	 * Array form carries details and the fix hint override.
	 */
	public function test_to_array_contains_all_fields(): void {
		$result = Result::warning( 'Summary', array( 'a', 'b' ), 'Do this' );

		$this->assertSame(
			array(
				'severity' => 'warning',
				'summary'  => 'Summary',
				'details'  => array( 'a', 'b' ),
				'fix_hint' => 'Do this',
			),
			$result->to_array()
		);
	}

	/**
	 * Defaults: no details and no override.
	 */
	public function test_defaults_are_empty(): void {
		$result = Result::ok( 'Fine' );

		$this->assertSame( array(), $result->details() );
		$this->assertSame( '', $result->fix_hint() );
	}
}
