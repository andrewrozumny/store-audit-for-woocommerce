<?php
/**
 * Cron tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Environment\Cron;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Cron.
 *
 * @covers \StoreAudit\Checks\Environment\Cron
 */
final class CronTest extends TestCase {

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
		return ( new Cron( $data ) )->run();
	}

	/**
	 * On time is ok.
	 */
	public function test_on_time_is_ok(): void {
		$result = $this->run_with(
			array(
				'disabled'   => false,
				'count'      => 0,
				'oldest'     => 0,
				'hooks'      => array(),
				'as_pending' => 0,
				'local'      => false,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Late tasks are warning.
	 */
	public function test_late_tasks_are_warning(): void {
		$result = $this->run_with(
			array(
				'disabled'   => false,
				'count'      => 3,
				'oldest'     => 7200,
				'hooks'      => array(
					'woocommerce_cleanup_sessions' => 7200,
					'my_hook'                      => 4000,
				),
				'as_pending' => null,
				'local'      => false,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '3 scheduled tasks', $result->summary() . implode( ' ', $result->details() ) );
		$this->assertStringContainsString( 'woocommerce_cleanup_sessions', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Over a day late is critical.
	 */
	public function test_over_a_day_late_is_critical(): void {
		$result = $this->run_with(
			array(
				'disabled'   => false,
				'count'      => 1,
				'oldest'     => 90000,
				'hooks'      => array( 'woocommerce_scheduled_sales' => 90000 ),
				'as_pending' => 0,
				'local'      => false,
			)
		);

		$this->assertSame( 'critical', $result->severity() );
		$this->assertStringContainsString( 'stopped', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Disabled cron without problems is info.
	 */
	public function test_disabled_cron_without_problems_is_info(): void {
		$result = $this->run_with(
			array(
				'disabled'   => true,
				'count'      => 0,
				'oldest'     => 0,
				'hooks'      => array(),
				'as_pending' => null,
				'local'      => false,
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'real server cron', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Many pending actions is warning.
	 */
	public function test_many_pending_actions_is_warning(): void {
		$result = $this->run_with(
			array(
				'disabled'   => false,
				'count'      => 0,
				'oldest'     => 0,
				'hooks'      => array(),
				'as_pending' => 250,
				'local'      => false,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '250', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Local site is mentioned.
	 */
	public function test_local_site_is_mentioned(): void {
		$result = $this->run_with(
			array(
				'disabled'   => false,
				'count'      => 2,
				'oldest'     => 5000,
				'hooks'      => array( 'a' => 5000 ),
				'as_pending' => 0,
				'local'      => true,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( 'local environment', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new Cron();

		$this->assertSame( 'environment_cron', $check->id() );
		$this->assertSame( 'environment', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
