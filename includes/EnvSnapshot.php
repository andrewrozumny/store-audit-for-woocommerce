<?php
/**
 * Snapshot of the PHP limits as the site had them before the audit changed anything.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The audit raises the time and memory limits for its own work. The environment checks must report what the
 * store runs with, so the values are taken once when the plugin loads, before any such change.
 */
final class EnvSnapshot {

	/**
	 * Captured values.
	 *
	 * @var array{memory_limit:string,max_execution_time:int,wp_memory_limit:string}|null
	 */
	private static ?array $data = null;

	/**
	 * Take the snapshot. Later calls keep the first one.
	 */
	public static function capture(): void {
		if ( null !== self::$data ) {
			return;
		}
		self::$data = array(
			'memory_limit'       => Arr::to_string( ini_get( 'memory_limit' ) ),
			'max_execution_time' => Arr::to_int( ini_get( 'max_execution_time' ) ),
			'wp_memory_limit'    => defined( 'WP_MEMORY_LIMIT' ) ? Arr::to_string( constant( 'WP_MEMORY_LIMIT' ) ) : '',
		);
	}

	/**
	 * Set the snapshot from given values (tests).
	 *
	 * @param string $memory_limit        PHP memory_limit as written, for example "256M".
	 * @param int    $max_execution_time  Seconds, 0 for unlimited.
	 * @param string $wp_memory_limit     WP_MEMORY_LIMIT as written, empty when not defined.
	 */
	public static function set( string $memory_limit, int $max_execution_time, string $wp_memory_limit = '' ): void {
		self::$data = array(
			'memory_limit'       => $memory_limit,
			'max_execution_time' => $max_execution_time,
			'wp_memory_limit'    => $wp_memory_limit,
		);
	}

	/**
	 * The snapshot, taken now when nothing captured it earlier.
	 *
	 * @return array{memory_limit:string,max_execution_time:int,wp_memory_limit:string}
	 */
	public static function get(): array {
		self::capture();
		return self::$data ?? array(
			'memory_limit'       => '',
			'max_execution_time' => 0,
			'wp_memory_limit'    => '',
		);
	}

	/**
	 * Forget the snapshot (tests).
	 */
	public static function reset(): void {
		self::$data = null;
	}
}
