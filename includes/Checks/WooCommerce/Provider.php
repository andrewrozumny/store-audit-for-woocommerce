<?php
/**
 * WooCommerce checks provider.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\CheckInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the checks of this section. Add new checks here.
 */
final class Provider {

	/**
	 * Checks of this section.
	 *
	 * @return list<CheckInterface>
	 */
	public static function checks(): array {
		return array(
			new GuestCheckout(),
			new TestGateways(),
			new Taxes(),
			new ShippingZones(),
			new Pages(),
			new TermsPage(),
			new Hpos(),
			new NegativeStock(),
			new StaleOrders(),
			new Webhooks(),
			new ApiKeys(),
		);
	}
}
