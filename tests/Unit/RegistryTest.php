<?php
/**
 * Registry tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use StoreAudit\Registry;

/**
 * Tests for Registry.
 *
 * @covers \StoreAudit\Registry
 */
final class RegistryTest extends TestCase {

	/**
	 * Built-in checks are keyed by id.
	 */
	public function test_builtin_checks_are_keyed_by_id(): void {
		$all = Registry::all();

		$this->assertArrayHasKey( 'environment_php_version', $all );
		$this->assertArrayHasKey( 'environment_wp_debug', $all );
		$this->assertSame( 'environment_php_version', $all['environment_php_version']->id() );
	}

	/**
	 * The filter can add a check, and junk is dropped.
	 */
	public function test_filter_adds_check_and_junk_is_dropped(): void {
		Filters\expectApplied( 'store_audit_checks' )->once()->andReturnUsing(
			static function ( array $checks ): array {
				$checks[] = new FakeCheck( 'plugins_fake', 'plugins' );
				$checks[] = 'not a check';
				$checks[] = new \stdClass();
				return $checks;
			}
		);

		$builtin = count( \StoreAudit\Checks\Environment\Provider::checks() )
			+ count( \StoreAudit\Checks\Plugins\Provider::checks() )
			+ count( \StoreAudit\Checks\WooCommerce\Provider::checks() )
			+ count( \StoreAudit\Checks\Security\Provider::checks() )
			+ count( \StoreAudit\Checks\Performance\Provider::checks() );

		$all = Registry::all();

		$this->assertArrayHasKey( 'plugins_fake', $all );
		foreach ( $all as $check ) {
			$this->assertInstanceOf( \StoreAudit\Checks\CheckInterface::class, $check );
		}
		$this->assertSame( $builtin + 1, count( $all ) );
	}

	/**
	 * Lookup by id.
	 */
	public function test_get_returns_check_or_null(): void {
		$this->assertNotNull( Registry::get( 'environment_wp_debug' ) );
		$this->assertNull( Registry::get( 'does_not_exist' ) );
	}

	/**
	 * Run Registry::all() with extra checks added through the filter, collecting what _doing_it_wrong reports.
	 *
	 * @param mixed[]                                 $extra    Extra checks.
	 * @param list<array{0:string,1:string,2:string}> $reported Reports (by reference).
	 * @return array<string,\StoreAudit\Checks\CheckInterface>
	 */
	private function all_with( array $extra, array &$reported ): array {
		Functions\when( '_doing_it_wrong' )->alias(
			static function ( string $function_name, string $message, string $version ) use ( &$reported ): void {
				$reported[] = array( $function_name, $message, $version );
			}
		);
		Filters\expectApplied( 'store_audit_checks' )->once()->andReturnUsing(
			static function ( array $checks ) use ( $extra ): array {
				return array_merge( $checks, $extra );
			}
		);
		return Registry::all();
	}

	/**
	 * An id that is not lower-case letters, digits and underscores is dropped and reported.
	 */
	public function test_invalid_id_is_dropped_and_reported(): void {
		$reported = array();
		$all      = $this->all_with(
			array(
				new FakeCheck( 'Plugins-Fake', 'plugins' ),
				new FakeCheck( 'plugins fake', 'plugins' ),
				new FakeCheck( '', 'plugins' ),
				new FakeCheck( 'plugins_ok_1', 'plugins' ),
			),
			$reported
		);

		$this->assertArrayHasKey( 'plugins_ok_1', $all );
		$this->assertArrayNotHasKey( 'Plugins-Fake', $all );
		$this->assertArrayNotHasKey( 'plugins fake', $all );
		$this->assertArrayNotHasKey( '', $all );
		$this->assertCount( 3, $reported );
		$this->assertSame( 'store_audit_checks', $reported[0][0] );
		$this->assertSame( '0.1.1', $reported[0][2] );
		$this->assertStringContainsString( 'underscores', $reported[0][1] );
	}

	/**
	 * A check cannot take the id of a built-in one or of a check added before it.
	 */
	public function test_duplicate_id_is_dropped_and_the_first_one_stays(): void {
		$reported = array();
		$first    = new FakeCheck( 'plugins_dup', 'plugins' );
		$all      = $this->all_with(
			array(
				$first,
				new FakeCheck( 'plugins_dup', 'plugins' ),
				new FakeCheck( 'environment_php_version', 'environment' ),
			),
			$reported
		);

		$this->assertSame( $first, $all['plugins_dup'] );
		$this->assertNotInstanceOf( FakeCheck::class, $all['environment_php_version'] );
		$this->assertCount( 2, $reported );
		$this->assertStringContainsString( 'already', $reported[0][1] );
	}

	/**
	 * A section that has no score weight would never count, so the check is dropped and reported.
	 */
	public function test_unknown_section_is_dropped_and_reported(): void {
		$reported = array();
		$all      = $this->all_with( array( new FakeCheck( 'custom_fake', 'custom' ) ), $reported );

		$this->assertArrayNotHasKey( 'custom_fake', $all );
		$this->assertCount( 1, $reported );
		$this->assertStringContainsString( 'section', $reported[0][1] );
	}
}
