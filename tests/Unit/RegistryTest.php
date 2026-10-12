<?php
/**
 * Registry tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Filters;
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
}
