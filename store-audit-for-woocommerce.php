<?php
/**
 * Plugin Name:       Store Audit for WooCommerce
 * Plugin URI:        https://helpmybiz.org
 * Description:       A read-only audit of your WooCommerce store: one button, a clear report on security, settings, performance and environment.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   11.2
 * Author:            Andrew Rozumny
 * Author URI:        https://helpmybiz.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       store-audit-for-woocommerce
 * Domain Path:       /languages
 *
 * @package StoreAudit
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STORE_AUDIT_VERSION', '0.1.0' );
define( 'STORE_AUDIT_FILE', __FILE__ );
define( 'STORE_AUDIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'STORE_AUDIT_URL', plugin_dir_url( __FILE__ ) );

require_once STORE_AUDIT_PATH . 'includes/autoload.php';

// The limits as the host set them, before the audit raises anything for its own work.
\StoreAudit\EnvSnapshot::capture();

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// Boot once every plugin is loaded, so WooCommerce can be detected by its class on a single site and on a network.
add_action(
	'plugins_loaded',
	static function (): void {
		\StoreAudit\Plugin::instance()->boot();
	}
);
