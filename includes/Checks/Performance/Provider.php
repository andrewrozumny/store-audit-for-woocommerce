<?php
/**
 * Performance checks provider.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Performance;

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
			new CatalogSize(),
			new OrdersStorage(),
			new TableSizes(),
			new Autoload(),
			new ExpiredTransients(),
			new Revisions(),
			new ActivePlugins(),
			new Heartbeat(),
			new Images(),
		);
	}
}
