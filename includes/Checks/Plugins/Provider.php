<?php
/**
 * Plugins checks provider.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Plugins;

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
		return array();
	}
}
