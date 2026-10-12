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
	 * Prefixes of every row the plugin creates: its options, the run rows, and the transients with their timeout rows.
	 *
	 * @var list<string>
	 */
	private const PREFIXES = array(
		'store_audit_',
		'_transient_store_audit_',
		'_transient_timeout_store_audit_',
	);

	/**
	 * Remove the plugin data from this site, or from every site of a network.
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( Support\Arr::to_int( $site_id ) );
				self::clean_site();
				restore_current_blog();
			}
			return;
		}
		self::clean_site();
	}

	/**
	 * Delete the rows of the current site. Only names that START with one of the prefixes are touched.
	 */
	private static function clean_site(): void {
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
