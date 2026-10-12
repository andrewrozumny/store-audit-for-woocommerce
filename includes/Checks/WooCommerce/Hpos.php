<?php
/**
 * High-performance order storage check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the order storage mode (HPOS) and compatibility sync.
 */
final class Hpos extends AbstractCheck {

	/**
	 * Returns array{hpos:bool,sync:bool}.
	 *
	 * @var \Closure
	 */
	private \Closure $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 */
	public function __construct( ?callable $source = null ) {
		$source     ??= static function (): array {
			$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
				&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			return array(
				'hpos' => $hpos,
				'sync' => 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ),
			);
		};
		$this->source = \Closure::fromCallable( $source );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_hpos';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::WOOCOMMERCE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Order storage (HPOS)', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'High-performance order storage keeps orders in dedicated database tables instead of the general posts table, which makes order lists and reports faster on busy stores. Sync keeps the old storage updated too.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Optional: in WooCommerce, Settings, Advanced, Features, enable high-performance order storage, and turn sync off once migration is done.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$data = Arr::to_array( ( $this->source )() );
		$hpos = Arr::get_bool( $data, 'hpos' );
		$sync = Arr::get_bool( $data, 'sync' );

		if ( $hpos && $sync ) {
			return Result::info( __( 'High-performance order storage is on and sync with the old storage is also on. Sync doubles the writes and can be turned off after migration.', 'store-audit-for-woocommerce' ) );
		}
		if ( $hpos ) {
			return Result::ok( __( 'High-performance order storage is on and sync is off.', 'store-audit-for-woocommerce' ) );
		}
		if ( $sync ) {
			return Result::info( __( 'Orders still use the old storage (posts table) and sync to the modern order tables is on.', 'store-audit-for-woocommerce' ) );
		}
		return Result::info( __( 'Orders use the old storage (posts table). The modern, faster order storage is off.', 'store-audit-for-woocommerce' ) );
	}
}
