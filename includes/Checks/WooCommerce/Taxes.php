<?php
/**
 * Tax settings check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Plugins\DetailList;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxes switched on without any tax rate are charged at zero.
 */
final class Taxes extends AbstractCheck {

	/**
	 * Returns array{enabled:bool,rates:int}.
	 *
	 * @var callable
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? array( $this, 'read_taxes' );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_taxes';
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
		return __( 'Tax rates', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'If tax calculation is turned on but no rates are defined, every order is charged zero tax. That can mean wrongly priced products and trouble with tax authorities.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'In WooCommerce, Settings, Tax, add the tax rates that apply to you (or turn tax calculation off if you do not charge tax).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$data  = Arr::to_array( ( $this->source )() );
		$rates = Arr::get_int( $data, 'rates' );

		if ( ! Arr::get_bool( $data, 'enabled' ) ) {
			return Result::info( __( 'Tax calculation is turned off.', 'store-audit-for-woocommerce' ) );
		}

		if ( 0 === $rates ) {
			return Result::warning( __( 'Tax calculation is on, but no tax rates are defined, so no tax is added to orders.', 'store-audit-for-woocommerce' ) );
		}

		return Result::ok(
			sprintf(
				/* translators: %d: number of tax rates. */
				_n( 'Tax calculation is on with %d tax rate.', 'Tax calculation is on with %d tax rates.', $rates, 'store-audit-for-woocommerce' ),
				$rates
			)
		);
	}

	/**
	 * Read tax state. The rates table is small and COUNT(*) is cheap.
	 *
	 * @return array{enabled:bool,rates:int}
	 */
	private function read_taxes(): array {
		$enabled = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
		$rates   = 0;
		if ( $enabled ) {
			$db = Db::wpdb();
			Db::require_table( $db->prefix . 'woocommerce_tax_rates' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cheap COUNT on a small table; there is no WooCommerce API for a bare count.
			$rates = Arr::to_int( $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i', $db->prefix . 'woocommerce_tax_rates' ) ) );
		}
		return array(
			'enabled' => $enabled,
			'rates'   => $rates,
		);
	}
}
