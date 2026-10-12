<?php
/**
 * Detail list helper.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Plugins;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caps long detail lists.
 */
final class DetailList {

	/**
	 * Cap a list at $max items and append "and N more".
	 *
	 * @param string[] $items Items.
	 * @param int      $max   Maximum items shown.
	 * @return list<string>
	 */
	public static function cap( array $items, int $max = 20 ): array {
		$items = array_values( $items );
		if ( count( $items ) <= $max ) {
			return $items;
		}
		$more    = count( $items ) - $max;
		$items   = array_slice( $items, 0, $max );
		$items[] = sprintf(
			/* translators: %d: number of items not shown. */
			__( 'and %d more', 'store-audit-for-woocommerce' ),
			$more
		);
		return $items;
	}
}
