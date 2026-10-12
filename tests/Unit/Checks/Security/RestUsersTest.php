<?php
/**
 * RestUsers tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\RestUsers;

/**
 * Tests for RestUsers.
 *
 * @covers \StoreAudit\Checks\Security\RestUsers
 */
final class RestUsersTest extends ProbeTestCase {

	/**
	 * Exposed slugs are listed.
	 */
	public function test_exposed_users_warn(): void {
		$probe  = $this->probe( array( '/wp-json/wp/v2/users' => array( 200, '[{"id":1,"slug":"bob"},{"id":2,"slug":"amy"}]' ) ) );
		$result = ( new RestUsers( $probe ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'bob', 'amy' ), $result->details() );
	}

	/**
	 * At most ten slugs are shown.
	 */
	public function test_slugs_are_capped(): void {
		$items = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$items[] = '{"slug":"user' . $i . '"}';
		}
		$probe = $this->probe( array( '/wp-json/wp/v2/users' => array( 200, '[' . implode( ',', $items ) . ']' ) ) );

		$this->assertCount( 10, ( new RestUsers( $probe ) )->run()->details() );
	}

	/**
	 * Protected or empty answers are fine.
	 */
	public function test_protected_is_ok(): void {
		$this->assertSame( 'ok', ( new RestUsers( $this->probe( array( '/wp-json/wp/v2/users' => array( 401, '{"code":"rest_cannot_access"}' ) ) ) ) )->run()->severity() );
		$this->assertSame( 'ok', ( new RestUsers( $this->probe( array( '/wp-json/wp/v2/users' => array( 200, '[]' ) ) ) ) )->run()->severity() );
	}

	/**
	 * Without a loopback the check is not checked (an error row), never a pass.
	 */
	public function test_unreachable_is_not_checked(): void {
		$this->assertSame( 'error', ( new RestUsers( $this->broken_probe() ) )->run()->severity() );
	}
}
