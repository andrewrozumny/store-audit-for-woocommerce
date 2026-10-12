<?php
/**
 * Plugin bootstrap.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
	}

	/**
	 * Whether the Pro layer is active. Free build always returns false unless filtered.
	 */
	public static function is_pro(): bool {
		return (bool) apply_filters( 'store_audit_is_pro', false );
	}

	/**
	 * Hook everything up.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		( new Admin\Page() )->register();
		( new Ajax() )->register();
		( new Admin\Exports() )->register();
		( new Admin\DashboardWidget() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register();
		}
	}

	/**
	 * Whether WooCommerce is loaded or active. This runs on plugins_loaded, when every active plugin has been
	 * included, so the class check is reliable; the option lookups cover a WooCommerce that did not load. On a
	 * network, a plugin activated for the whole network is listed in a different place than a per-site one.
	 */
	private function woocommerce_active(): bool {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}
		$file   = 'woocommerce/woocommerce.php';
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $file, $active, true ) ) {
			return true;
		}
		return is_multisite() && array_key_exists( $file, (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Store Audit for WooCommerce needs WooCommerce to be installed and active.', 'store-audit-for-woocommerce' )
		);
	}
}
