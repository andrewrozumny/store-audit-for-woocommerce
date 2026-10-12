<?php
/**
 * ApiKeys tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use StoreAudit\Checks\WooCommerce\ApiKeys;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for ApiKeys.
 *
 * @covers \StoreAudit\Checks\WooCommerce\ApiKeys
 */
final class ApiKeysTest extends TestCase {

	private const NOW = 1790000000;

	/**
	 * Key row helper.
	 *
	 * @param string      $permissions Permissions.
	 * @param string|null $last_access Last access.
	 * @return array{description:string,permissions:string,last_access:?string,user:string}
	 */
	private function key( string $permissions, ?string $last_access ): array {
		return array(
			'description' => 'ERP sync',
			'permissions' => $permissions,
			'last_access' => $last_access,
			'user'        => 'admin',
		);
	}

	/**
	 * Read only never used.
	 */
	public function test_read_only_key_is_ignored(): void {
		$check = new ApiKeys( fn(): array => array( $this->key( 'read', null ) ), self::NOW );

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Recently used write key.
	 */
	public function test_recent_write_key_is_ok(): void {
		$check = new ApiKeys( fn(): array => array( $this->key( 'read_write', '2026-09-20 10:00:00' ) ), self::NOW );

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Never used write key.
	 */
	public function test_never_used_write_key_warns(): void {
		$check = new ApiKeys( fn(): array => array( $this->key( 'write', null ) ), self::NOW );

		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( 'never used', $result->details()[0] );
		$this->assertStringContainsString( 'ERP sync', $result->details()[0] );
	}

	/**
	 * Idle read_write key.
	 */
	public function test_old_read_write_key_warns(): void {
		$check = new ApiKeys( fn(): array => array( $this->key( 'read_write', '2024-01-10 10:00:00' ) ), self::NOW );

		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '10 Jan 2024', $result->details()[0] );
	}
}
