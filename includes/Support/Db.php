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

	/**
	 * Stop the check with a clear message when a table is missing. Querying a missing table would only fill the
	 * log with database errors and read as "zero rows". The runner turns the exception into "not checked".
	 *
	 * @param string $table Full table name, with the prefix.
	 * @throws \RuntimeException When the table does not exist.
	 */
	public static function require_table( string $table ): void {
		if ( ! self::table_exists( $table ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: database table name. */
						__( 'the database table %s does not exist.', 'store-audit-for-woocommerce' ),
						$table
					)
				)
			);
		}
	}
}
