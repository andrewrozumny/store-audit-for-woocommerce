<?php
/**
 * Database access helper.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed access to the global WordPress database object.
 */
final class Db {

	/**
	 * The WordPress database object.
	 *
	 * @throws \RuntimeException When the database object is not available.
	 */
	public static function wpdb(): \wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'The WordPress database object is not available.' );
		}
		return $wpdb;
	}

	/**
	 * Whether a table with the exact name exists.
	 *
	 * @param string $table Full table name including the prefix.
	 */
	public static function table_exists( string $table ): bool {
		$db = self::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema lookup; there is no WordPress API for it.
		return $table === $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $table ) ) );
	}
}
