<?php
/**
 * VersionBranch tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Support;

use StoreAudit\Support\VersionBranch;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for VersionBranch.
 *
 * @covers \StoreAudit\Support\VersionBranch
 */
final class VersionBranchTest extends TestCase {

	/**
	 * Only major and minor count.
	 */
	public function test_branch_of_versions(): void {
		$this->assertSame( '6.8', VersionBranch::of( '6.8.2' ) );
		$this->assertSame( '6.8', VersionBranch::of( '6.8' ) );
		$this->assertSame( '7.0', VersionBranch::of( '7' ) );
	}
}
