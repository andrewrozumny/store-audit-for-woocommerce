<?php
/**
 * Which checks hide their details from exports.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Environment\PhpVersion;
use StoreAudit\Checks\Security\AdminCount;
use StoreAudit\Checks\Security\BackupsInWebroot;
use StoreAudit\Checks\Security\InactiveAdmins;
use StoreAudit\Checks\Security\RestUsers;
use StoreAudit\Checks\WooCommerce\ApiKeys;

/**
 * Tests for AbstractCheck::is_sensitive().
 *
 * @covers \StoreAudit\Checks\AbstractCheck
 */
final class SensitiveChecksTest extends TestCase {

	/**
	 * The checks whose details name accounts, keys or files are sensitive.
	 */
	public function test_checks_with_names_in_details_are_sensitive(): void {
		foreach ( array( new AdminCount(), new InactiveAdmins(), new RestUsers(), new ApiKeys(), new BackupsInWebroot() ) as $check ) {
			$this->assertTrue( $check->is_sensitive(), $check->id() );
		}
	}

	/**
	 * By default a check is not sensitive, so existing and third-party checks keep working.
	 */
	public function test_default_is_not_sensitive(): void {
		$this->assertFalse( ( new FakeCheck() )->is_sensitive() );
		$this->assertFalse( ( new PhpVersion() )->is_sensitive() );
		$this->assertInstanceOf( AbstractCheck::class, new FakeCheck() );
	}
}
