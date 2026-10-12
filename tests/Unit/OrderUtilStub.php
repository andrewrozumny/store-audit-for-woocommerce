<?php
/**
 * Stand-in for the WooCommerce order storage helper, with HPOS switched on.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( OrderUtil::class ) ) {
	/**
	 * Test double: custom order tables are always on.
	 */
	final class OrderUtil {

		/**
		 * HPOS state.
		 */
		public static function custom_orders_table_usage_is_enabled(): bool {
			return true;
		}
	}
}
