<?php
/**
 * AdminUsername tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\AdminUsername;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for AdminUsername.
 *
 * @covers \StoreAudit\Checks\Security\AdminUsername
 * @covers \StoreAudit\Checks\Security\AbstractSecurityCheck
 */
final class AdminUsernameTest extends TestCase {

	/**
	 * Build a user array.
	 *
	 * @param string $login    Login.
	 * @param string $display  Display name.
	 * @param string $nicename Nicename.
	 * @param string $role     Role.
	 * @return array{id:int,login:string,display_name:string,nicename:string,email:string,roles:list<string>}
	 */
	private function user( string $login, string $display = 'Someone', string $nicename = 'someone-else', string $role = 'administrator' ): array {
		return array(
			'id'           => 1,
			'login'        => $login,
			'display_name' => $display,
			'nicename'     => $nicename,
			'email'        => 'a@example.com',
			'roles'        => array( $role ),
		);
	}

	/**
	 * An administrator named admin is critical.
	 */
	public function test_admin_login_with_admin_role_is_critical(): void {
		$admin = $this->user( 'admin' );
		$check = new AdminUsername( static fn () => array( $admin ), static fn ( string $login ) => 'admin' === $login ? $admin : null );

		$this->assertSame( 'critical', $check->run()->severity() );
	}

	/**
	 * A non-administrator named admin is a warning.
	 */
	public function test_admin_login_without_admin_role_is_warning(): void {
		$other = $this->user( 'admin', 'Someone', 'someone-else', 'customer' );
		$check = new AdminUsername( static fn () => array(), static fn ( string $login ) => 'admin' === $login ? $other : null );

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * A login that matches the public name is a warning.
	 */
	public function test_login_equal_to_public_name_is_warning(): void {
		$bob   = $this->user( 'bob', 'Bob', 'someone-else' );
		$check = new AdminUsername( static fn () => array( $bob ), static fn () => null );
		$res   = $check->run();

		$this->assertSame( 'warning', $res->severity() );
		$this->assertStringContainsString( 'bob', implode( ' ', $res->details() ) );
	}

	/**
	 * A login that only shows up in the author archive address is no warning: the nickname advice could not remove it.
	 */
	public function test_login_in_author_slug_only_is_info_with_honest_advice(): void {
		$bob   = $this->user( 'bob', 'Bobby the Baker', 'bob' );
		$check = new AdminUsername( static fn () => array( $bob ), static fn () => null );
		$res   = $check->run();

		$this->assertSame( 'info', $res->severity() );
		$this->assertStringContainsString( 'author archive address', implode( ' ', $res->details() ) );
		$this->assertStringContainsString( 'user slug', $res->fix_hint() );
	}

	/**
	 * The display name is compared without regard to case.
	 */
	public function test_display_name_match_ignores_case(): void {
		$bob   = $this->user( 'bob', 'BOB', 'someone-else' );
		$check = new AdminUsername( static fn () => array( $bob ), static fn () => null );

		$this->assertSame( 'warning', $check->run()->severity() );
	}

	/**
	 * Both exposures: the warning stays and the author address line is added to its details.
	 */
	public function test_display_name_and_slug_both_matching_is_warning_with_slug_line(): void {
		$bob = $this->user( 'bob', 'Bob', 'bob' );
		$res = ( new AdminUsername( static fn () => array( $bob ), static fn () => null ) )->run();

		$this->assertSame( 'warning', $res->severity() );
		$this->assertStringContainsString( 'author archive address', implode( ' ', $res->details() ) );
	}

	/**
	 * Details name logins, so exports hide them.
	 */
	public function test_is_sensitive(): void {
		$this->assertTrue( ( new AdminUsername() )->is_sensitive() );
	}

	/**
	 * Unique logins are ok.
	 */
	public function test_clean_admins_are_ok(): void {
		$admin = $this->user( 'jx92' );
		$check = new AdminUsername( static fn () => array( $admin ), static fn () => null );

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Metadata.
	 */
	public function test_metadata(): void {
		$this->assertSame( 'security_admin_username', ( new AdminUsername() )->id() );
		$this->assertSame( 'security', ( new AdminUsername() )->section() );
	}
}
