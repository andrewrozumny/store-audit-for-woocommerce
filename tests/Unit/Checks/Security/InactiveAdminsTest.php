<?php
/**
 * InactiveAdmins tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\InactiveAdmins;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for InactiveAdmins.
 *
 * @covers \StoreAudit\Checks\Security\InactiveAdmins
 */
final class InactiveAdminsTest extends TestCase {

	private const NOW = 1800000000;

	/**
	 * Two administrators, ids 1 and 2.
	 *
	 * @return list<array{id:int,login:string,display_name:string,nicename:string,email:string,roles:list<string>}>
	 */
	private function admins(): array {
		return array(
			array(
				'id'           => 1,
				'login'        => 'old',
				'display_name' => 'Old',
				'nicename'     => 'old',
				'email'        => 'o@example.com',
				'roles'        => array( 'administrator' ),
			),
			array(
				'id'           => 2,
				'login'        => 'fresh',
				'display_name' => 'Fresh',
				'nicename'     => 'fresh',
				'email'        => 'f@example.com',
				'roles'        => array( 'administrator' ),
			),
		);
	}

	/**
	 * An old last activity is a warning, a recent session is not.
	 */
	public function test_old_activity_warns(): void {
		$meta  = static function ( int $id, string $key ) {
			if ( 1 === $id && 'wc_last_active' === $key ) {
				return (string) ( self::NOW - 400 * 86400 );
			}
			if ( 2 === $id && 'session_tokens' === $key ) {
				return array( 'tok' => array( 'login' => self::NOW - 86400 ) );
			}
			return '';
		};
		$check = new InactiveAdmins( fn () => $this->admins(), $meta, self::NOW );
		$res   = $check->run();

		$this->assertSame( 'warning', $res->severity() );
		$this->assertStringContainsString( 'old', $res->details()[0] );
		$this->assertCount( 1, $res->details() );
	}

	/**
	 * Recent activity everywhere is ok.
	 */
	public function test_recent_activity_is_ok(): void {
		$meta  = static fn ( int $id, string $key ) => 'last_login' === $key ? (string) ( self::NOW - 86400 ) : '';
		$check = new InactiveAdmins( fn () => $this->admins(), $meta, self::NOW );

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * No record at all is info with an unknown-activity line.
	 */
	public function test_unknown_activity_is_info(): void {
		$check = new InactiveAdmins( fn () => $this->admins(), static fn () => '', self::NOW );
		$res   = $check->run();

		$this->assertSame( 'info', $res->severity() );
		$this->assertStringContainsString( 'Unknown activity', $res->details()[0] );
	}
}
