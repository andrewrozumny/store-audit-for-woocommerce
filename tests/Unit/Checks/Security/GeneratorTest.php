<?php
/**
 * Generator tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use StoreAudit\Checks\Security\Generator;

/**
 * Tests for Generator.
 *
 * @covers \StoreAudit\Checks\Security\Generator
 */
final class GeneratorTest extends ProbeTestCase {

	private const PAGE = '<html><head><meta name="generator" content="WordPress 6.4.1" /></head></html>';

	/**
	 * An outdated exposed version is a warning.
	 */
	public function test_outdated_version_warns(): void {
		$check  = new Generator( $this->probe( array( '/' => array( 200, self::PAGE ) ) ), static fn () => '6.5' );
		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '6.4.1', $result->summary() );
	}

	/**
	 * A current exposed version is info.
	 */
	public function test_current_version_is_info(): void {
		$check = new Generator( $this->probe( array( '/' => array( 200, self::PAGE ) ) ), static fn () => '6.4.1' );

		$this->assertSame( 'info', $check->run()->severity() );
	}

	/**
	 * No tag is ok.
	 */
	public function test_no_tag_is_ok(): void {
		$check = new Generator( $this->probe( array( '/' => array( 200, '<html></html>' ) ) ), static fn () => '6.5' );

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Without a loopback the check is not checked (an error row), never a pass.
	 */
	public function test_unreachable_is_not_checked(): void {
		$this->assertSame( 'error', ( new Generator( $this->broken_probe() ) )->run()->severity() );
	}
}
