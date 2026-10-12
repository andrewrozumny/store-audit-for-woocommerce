<?php
/**
 * Security checks provider.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

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
			new AdminUsername(),
			new AdminCount(),
			new InactiveAdmins(),
			new Xmlrpc(),
			new RestUsers(),
			new Generator(),
			new UploadsListing(),
			new WpConfigHttp(),
			new ReadmeLicense(),
			new ExposedDotfiles(),
			new BackupsInWebroot(),
			new Antifraud(),
			new SslAdmin(),
		);
	}
}
