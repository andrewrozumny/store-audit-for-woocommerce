<?php
/**
 * Revisions tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Performance;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Performance\Revisions;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Revisions.
 *
 * @covers \StoreAudit\Checks\Performance\Revisions
 */
final class RevisionsTest extends TestCase {

	/**
	 * Stubs for helpers the check uses.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ) {
				return round( (float) $bytes / 1048576, 1 ) . ' MB';
			}
		);
		Functions\when( 'human_time_diff' )->alias(
			static function ( $from, $to ) {
				return (string) ( (int) $to - (int) $from ) . ' seconds';
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	/**
	 * Run the check on prepared data.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	private function run_with( array $data ): \StoreAudit\Result {
		return ( new Revisions( $data ) )->run();
	}

	/**
	 * Few is ok.
	 */
	public function test_few_is_ok(): void {
		$result = $this->run_with(
			array(
				'count' => 100,
				'limit' => 'unlimited',
			)
		);

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( 'unlimited', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Many is warning.
	 */
	public function test_many_is_warning(): void {
		$result = $this->run_with(
			array(
				'count' => 8000,
				'limit' => 'unlimited',
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '8000', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new Revisions();

		$this->assertSame( 'performance_revisions', $check->id() );
		$this->assertSame( 'performance', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
