<?php
/**
 * Order counts for the checks that need them.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Support;

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts shop orders whichever way WooCommerce stores them: the dedicated tables (HPOS) or the posts tables.
 */
final class OrderStats {

	/**
	 * Whether orders live in the dedicated HPOS tables.
	 */
	public static function uses_hpos(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Number of shop orders. Reads only; a missing HPOS table ends the check as "not checked".
	 *
	 * @param bool|null $hpos Whether orders are in the HPOS tables; null asks WooCommerce.
	 */
	public static function count( ?bool $hpos = null ): int {
		$db = Db::wpdb();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count for the audit; there is no cheaper WooCommerce API.
		if ( $hpos ?? self::uses_hpos() ) {
			Db::require_table( $db->prefix . 'wc_orders' );
			return Arr::to_int( $db->get_var( $db->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'shop_order'", $db->prefix . 'wc_orders' ) ) );
		}
		return Arr::to_int( $db->get_var( $db->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order'", $db->posts ) ) );
		// phpcs:enable
	}
}
