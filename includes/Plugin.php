<?php
/**
 * Plugin bootstrap.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

use StoreAudit\Support\ActivePlugins;

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
		/**
		 * Filters whether the Pro layer is active. The free build returns false; a Pro add-on returns true. The
		 * callback returns the new value (true when the Pro layer is active).
		 *
		 * @since 0.1.0
		 *
		 * @param bool|int|string $is_pro Whether the Pro layer is active. Default false. Any truthy value counts as true.
		 */
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
		return ActivePlugins::is_active( 'woocommerce/woocommerce.php' );
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
