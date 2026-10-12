<?php
/**
 * Removes plugin data on uninstall.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/autoload.php';

\StoreAudit\Uninstall::run();
