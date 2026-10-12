<?php
/**
 * In-memory options and JSON responses for tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Backs get_option, update_option and delete_option with an array.
 */
trait OptionStoreTrait {

	/**
	 * Stored options.
	 *
	 * @var array<string,mixed>
	 */
	protected array $options = array();

	/**
	 * Stub the option functions and the JSON responders.
	 */
	protected function stub_option_store(): void {
		Functions\when( 'get_option' )->alias(
			fn( string $name, $fallback = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				$existed = array_key_exists( $name, $this->options );
				unset( $this->options[ $name ] );
				return $existed;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null, $status = null ): void {
				throw new JsonResponse( true, $data, (int) ( $status ?? 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ): void {
				throw new JsonResponse( false, $data, (int) ( $status ?? 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
			}
		);
	}
}
