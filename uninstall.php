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

delete_option( 'store_audit_last_report' );
delete_option( 'store_audit_current_run' );
