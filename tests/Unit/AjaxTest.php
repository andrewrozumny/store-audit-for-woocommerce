<?php
/**
 * Ajax request handling tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Functions;
use StoreAudit\Ajax;

/**
 * Tests for Ajax.
 *
 * @covers \StoreAudit\Ajax
 */
final class AjaxTest extends TestCase {

	/**
	 * Stub the WordPress functions the endpoints call.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ): void {
				unset( $data );
				throw new \RuntimeException( esc_html( 'json_error:' . (string) $status ) );
			}
		);
	}

	/**
	 * Reset the superglobal after each test.
	 */
	protected function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * A run id sent as an array counts as empty, so the run is reported inactive without a PHP warning.
	 */
	public function test_array_run_id_is_treated_as_empty(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		$_POST = array(
			'run_id'   => array( 'x' ),
			'check_id' => array( 'y' ),
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error:409' );
		( new Ajax() )->run_check();
	}

	/**
	 * A failed nonce stops the request before any posted value is read.
	 */
	public function test_failed_nonce_is_rejected(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( false );
		$_POST = array( 'run_id' => 'abc' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error:403' );
		( new Ajax() )->finish();
	}

	/**
	 * A user without the capability is rejected.
	 */
	public function test_missing_capability_is_rejected(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error:403' );
		( new Ajax() )->markdown();
	}

	/**
	 * A nonce sent as an array is refused without WordPress ever turning it into a string (which raises a PHP warning).
	 */
	public function test_array_nonce_is_rejected_before_core_sees_it(): void {
		Functions\expect( 'check_ajax_referer' )->never();
		$_REQUEST = array( 'nonce' => array( 'x' ) );

		try {
			( new Ajax() )->start();
			$this->fail( 'The request was not refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error:403', $e->getMessage() );
		} finally {
			$_REQUEST = array();
		}
	}
}
