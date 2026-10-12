<?php
/**
 * Typed readers for loosely typed arrays and values.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Narrow mixed values (options, API payloads, report rows) to concrete types with runtime checks.
 */
final class Arr {

	/**
	 * Scalar value as a string; anything else gives the default.
	 *
	 * @param mixed  $value   Value.
	 * @param string $fallback Fallback.
	 */
	public static function to_string( mixed $value, string $fallback = '' ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return (string) $value;
		}
		return $fallback;
	}

	/**
	 * Integer value: ints, floats, booleans and strings (PHP string-to-int rules); anything else gives the default.
	 *
	 * @param mixed $value   Value.
	 * @param int   $fallback Fallback.
	 */
	public static function to_int( mixed $value, int $fallback = 0 ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) || is_bool( $value ) || is_string( $value ) ) {
			return (int) $value;
		}
		return $fallback;
	}

	/**
	 * Float value from numbers and strings.
	 *
	 * @param mixed $value   Value.
	 * @param float $fallback Fallback.
	 */
	public static function to_float( mixed $value, float $fallback = 0.0 ): float {
		if ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
			return (float) $value;
		}
		return $fallback;
	}

	/**
	 * Array value or an empty array.
	 *
	 * @param mixed $value Value.
	 * @return array<mixed>
	 */
	public static function to_array( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Entries of an array whose keys are strings (integer keys are dropped).
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	public static function to_string_keyed( mixed $value ): array {
		$out = array();
		foreach ( self::to_array( $value ) as $key => $item ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $item;
			}
		}
		return $out;
	}

	/**
	 * Only the string items of a value, in order.
	 *
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	public static function to_string_list( mixed $value ): array {
		$out = array();
		foreach ( self::to_array( $value ) as $item ) {
			if ( is_string( $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * String stored under a key.
	 *
	 * @param array<mixed> $data    Source.
	 * @param string|int   $key     Key.
	 * @param string       $fallback Fallback.
	 */
	public static function get_string( array $data, string|int $key, string $fallback = '' ): string {
		return array_key_exists( $key, $data ) ? self::to_string( $data[ $key ], $fallback ) : $fallback;
	}

	/**
	 * Integer stored under a key.
	 *
	 * @param array<mixed> $data    Source.
	 * @param string|int   $key     Key.
	 * @param int          $fallback Fallback.
	 */
	public static function get_int( array $data, string|int $key, int $fallback = 0 ): int {
		return array_key_exists( $key, $data ) ? self::to_int( $data[ $key ], $fallback ) : $fallback;
	}

	/**
	 * Float stored under a key.
	 *
	 * @param array<mixed> $data    Source.
	 * @param string|int   $key     Key.
	 * @param float        $fallback Fallback.
	 */
	public static function get_float( array $data, string|int $key, float $fallback = 0.0 ): float {
		return array_key_exists( $key, $data ) ? self::to_float( $data[ $key ], $fallback ) : $fallback;
	}

	/**
	 * Array stored under a key, or an empty array.
	 *
	 * @param array<mixed> $data Source.
	 * @param string|int   $key  Key.
	 * @return array<mixed>
	 */
	public static function get_array( array $data, string|int $key ): array {
		return array_key_exists( $key, $data ) ? self::to_array( $data[ $key ] ) : array();
	}

	/**
	 * String-keyed array stored under a key, or an empty array.
	 *
	 * @param array<mixed> $data Source.
	 * @param string|int   $key  Key.
	 * @return array<string,mixed>
	 */
	public static function get_map( array $data, string|int $key ): array {
		return array_key_exists( $key, $data ) ? self::to_string_keyed( $data[ $key ] ) : array();
	}

	/**
	 * PHP truthiness of a stored value; false when the key is missing (same as `! empty( $data[ $key ] )`).
	 *
	 * @param array<mixed> $data Source.
	 * @param string|int   $key  Key.
	 */
	public static function get_bool( array $data, string|int $key ): bool {
		return array_key_exists( $key, $data ) && (bool) $data[ $key ];
	}
}
