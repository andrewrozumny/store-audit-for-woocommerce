<?php
/**
 * AdminCount tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\AdminCount;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for AdminCount.
 *
 * @covers \StoreAudit\Checks\Security\AdminCount
 * @covers \StoreAudit\Checks\Security\AbstractSecurityCheck
 */
final class AdminCountTest extends TestCase {

	/**
	 * Build n administrators.
	 *
	 * @param int $n Count.
	 * @return list<array{id:int,login:string,display_name:string,nicename:string,email:string,roles:list<string>}>
	 */
	private function admins( int $n ): array {
		$list = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$list[] = array(
				'id'           => $i,
				'login'        => 'user' . $i,
				'display_name' => 'User ' . $i,
				'nicename'     => 'user' . $i,
				'email'        => 'person' . $i . '@corp.example',
				'roles'        => array( 'administrator' ),
			);
		}
		return $list;
	}

	/**
	 * Up to three is ok.
	 */
	public function test_three_is_ok(): void {
		$this->assertSame( 'ok', ( new AdminCount( fn () => $this->admins( 3 ) ) )->run()->severity() );
	}

	/**
	 * More than three warns and shows only the email domain.
	 */
	public function test_five_warns_without_full_emails(): void {
		$result = ( new AdminCount( fn () => $this->admins( 5 ) ) )->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertCount( 5, $result->details() );
		$this->assertStringContainsString( 'corp.example', $result->details()[0] );
		$this->assertStringNotContainsString( 'person1@', implode( ' ', $result->details() ) );
	}

	/**
	 * Long lists are capped.
	 */
	public function test_long_list_is_capped(): void {
		$this->assertCount( 21, ( new AdminCount( fn () => $this->admins( 25 ) ) )->run()->details() );
	}
}
