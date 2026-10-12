<?php
/**
 * Https tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Environment\Https;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Https.
 *
 * @covers \StoreAudit\Checks\Environment\Https
 */
final class HttpsTest extends TestCase {

	/**
	 * Stubs for helpers the check uses.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ) {
				return round( (float) $bytes / 1048576, 1 ) . ' MB';
			}
		);
		Functions\when( 'human_time_diff' )->alias(
			static function ( $from, $to ) {
				return (string) ( (int) $to - (int) $from ) . ' seconds';
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	/**
	 * Run the check on prepared data.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	private function run_with( array $data ): \StoreAudit\Result {
		return ( new Https( $data ) )->run();
	}

	/**
	 * Http home is critical.
	 */
	public function test_http_home_is_critical(): void {
		$result = $this->run_with(
			array(
				'home'    => 'http://shop.example',
				'siteurl' => 'https://shop.example',
				'ssl'     => true,
				'cli'     => false,
			)
		);

		$this->assertSame( 'critical', $result->severity() );
		$this->assertStringContainsString( 'http://shop.example', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Http siteurl is critical.
	 */
	public function test_http_siteurl_is_critical(): void {
		$result = $this->run_with(
			array(
				'home'    => 'https://shop.example',
				'siteurl' => 'http://shop.example/wp',
				'ssl'     => true,
				'cli'     => false,
			)
		);

		$this->assertSame( 'critical', $result->severity() );
	}

	/**
	 * Http on local is info.
	 */
	public function test_http_on_local_is_info(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		$result = $this->run_with(
			array(
				'home'    => 'http://shop.test',
				'siteurl' => 'http://shop.test',
				'ssl'     => false,
				'cli'     => false,
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'local', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Https is ok.
	 */
	public function test_https_is_ok(): void {
		$result = $this->run_with(
			array(
				'home'    => 'https://shop.example',
				'siteurl' => 'https://shop.example',
				'ssl'     => true,
				'cli'     => false,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Https without ssl request is hinted.
	 */
	public function test_https_without_ssl_request_is_hinted(): void {
		$result = $this->run_with(
			array(
				'home'    => 'https://shop.example',
				'siteurl' => 'https://shop.example',
				'ssl'     => false,
				'cli'     => false,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
		$this->assertStringContainsString( 'not detected as https', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new Https();

		$this->assertSame( 'environment_https', $check->id() );
		$this->assertSame( 'environment', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
