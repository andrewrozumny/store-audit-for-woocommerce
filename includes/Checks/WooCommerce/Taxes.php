<?php
/**
 * Tax settings check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\ActivePlugins;
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
	 * Plugins that calculate tax automatically, by slug.
	 */
	private const SERVICES = array(
		'woocommerce-services'                    => 'WooCommerce Tax',
		'woocommerce-tax'                         => 'WooCommerce Tax',
		'taxjar-simplified-taxes-for-woocommerce' => 'TaxJar',
		'avalara-avatax'                          => 'Avalara AvaTax',
		'woocommerce-avatax'                      => 'Avalara AvaTax',
		'stripe-tax-for-woocommerce'              => 'Stripe Tax',
	);

	/**
	 * Returns array{enabled:bool,rates:int,auto:string}.
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
		$this->source = \Closure::fromCallable( $source ?? array( $this, 'read_taxes' ) );
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
		return __( 'If tax calculation is turned on but no rates are defined and no tax service is connected, every order is charged zero tax. That can mean wrongly priced products and trouble with tax authorities.', 'store-audit-for-woocommerce' );
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
			$auto = Arr::get_string( $data, 'auto' );
			if ( '' !== $auto ) {
				return Result::ok(
					__( 'Tax calculation is on and an automatic tax service works out the tax.', 'store-audit-for-woocommerce' ),
					array(
						/* translators: %s: name of the tax service. */
						sprintf( __( 'Taxes are calculated automatically by %s.', 'store-audit-for-woocommerce' ), $auto ),
					)
				);
			}
			return Result::info( __( 'No manual tax rates are set. If an automatic tax service calculates your tax, this is fine; otherwise no tax is added.', 'store-audit-for-woocommerce' ) );
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
	 * @return array{enabled:bool,rates:int,auto:string}
	 */
	private function read_taxes(): array {
		$enabled = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
		$rates   = 0;
		$auto    = '';
		if ( $enabled ) {
			$db = Db::wpdb();
			Db::require_table( $db->prefix . 'woocommerce_tax_rates' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cheap COUNT on a small table; there is no WooCommerce API for a bare count.
			$rates = Arr::to_int( $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i', $db->prefix . 'woocommerce_tax_rates' ) ) );
			$auto  = 0 === $rates ? $this->automatic_service() : '';
		}
		return array(
			'enabled' => $enabled,
			'rates'   => $rates,
			'auto'    => $auto,
		);
	}

	/**
	 * Name of the automatic tax service in use, or an empty string.
	 */
	private function automatic_service(): string {
		if ( 'yes' === get_option( 'wc_connect_taxes_enabled', 'no' ) ) {
			return 'WooCommerce Tax';
		}
		foreach ( ActivePlugins::slugs() as $slug ) {
			if ( isset( self::SERVICES[ $slug ] ) ) {
				return self::SERVICES[ $slug ];
			}
		}
		return '';
	}
}
