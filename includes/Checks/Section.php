<?php
/**
 * Report sections.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Section ids, labels and score weights.
 */
final class Section {

	public const ENVIRONMENT = 'environment';
	public const PLUGINS     = 'plugins';
	public const WOOCOMMERCE = 'woocommerce';
	public const SECURITY    = 'security';
	public const PERFORMANCE = 'performance';

	/**
	 * Translated labels keyed by section id, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::SECURITY    => __( 'Security', 'store-audit-for-woocommerce' ),
			self::WOOCOMMERCE => __( 'WooCommerce settings', 'store-audit-for-woocommerce' ),
			self::ENVIRONMENT => __( 'Environment', 'store-audit-for-woocommerce' ),
			self::PLUGINS     => __( 'Plugins and theme', 'store-audit-for-woocommerce' ),
			self::PERFORMANCE => __( 'Performance', 'store-audit-for-woocommerce' ),
		);
	}

	/**
	 * Score weights keyed by section id.
	 *
	 * @return array<string,int>
	 */
	public static function weights(): array {
		return array(
			self::SECURITY    => 30,
			self::WOOCOMMERCE => 25,
			self::ENVIRONMENT => 20,
			self::PLUGINS     => 15,
			self::PERFORMANCE => 10,
		);
	}
}
