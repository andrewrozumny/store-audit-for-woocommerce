<?php
/**
 * Removes everything the plugin stored.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uninstall routine, called from uninstall.php.
 */
final class Uninstall {

	/**
	 * Options the plugin creates, by exact name.
	 *
	 * @var list<string>
	 */
	private const OPTIONS = array(
		ReportStore::OPTION,
		Ajax::RUN_OPTION,
	);

	/**
	 * Name prefixes of the rows that come in numbers: one result per check of a run, and the cached
	 * wordpress.org answers with their timeout rows. Nothing else that starts with store_audit_ is touched.
	 *
	 * @var list<string>
	 */
	private const PREFIXES = array(
		Ajax::RESULT_PREFIX,
		'_transient_store_audit_wporg_',
		'_transient_timeout_store_audit_wporg_',
	);

	/**
	 * Sites handled per query on a network.
	 */
	private const SITE_BATCH = 100;

	/**
	 * Remove the plugin data from this site, or from every site of a network.
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			$offset = 0;
			do {
				$ids     = get_sites(
					array(
						'fields' => 'ids',
						'number' => self::SITE_BATCH,
						'offset' => $offset,
					)
				);
				$fetched = count( $ids );
				foreach ( $ids as $site_id ) {
					switch_to_blog( Support\Arr::to_int( $site_id ) );
					self::clean_site();
					restore_current_blog();
				}
				$offset += self::SITE_BATCH;
			} while ( self::SITE_BATCH === $fetched );
			return;
		}
		self::clean_site();
	}

	/**
	 * Delete the rows of the current site: the exact option names, and names that START with one of the prefixes.
	 */
	private static function clean_site(): void {
		foreach ( self::OPTIONS as $name ) {
			delete_option( $name );
		}

		$db    = Db::wpdb();
		$likes = array();
		foreach ( self::PREFIXES as $prefix ) {
			$likes[] = $db->esc_like( $prefix ) . '%';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off lookup of our own rows on uninstall.
		$names = $db->get_col( $db->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $db->options, ...$likes ) );
		foreach ( $names as $name ) {
			delete_option( Support\Arr::to_string( $name ) );
		}
	}
}
