<?php
/**
 * WooCommerce version check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Environment;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\VersionBranch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce version.
 */
final class WcVersion extends AbstractCheck {

	/**
	 * Data to evaluate (tests) or null to read the live site.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $data Pre-collected data for tests; null reads the live site.
	 */
	public function __construct( ?array $data = null ) {
		$this->data = $data;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'environment_wc_version';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::ENVIRONMENT;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'WooCommerce version', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'WooCommerce handles your cart, checkout and orders. Old versions miss security and payment fixes and can break at checkout when WordPress or PHP is updated.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Back up the site, test on a staging copy if you can, then update WooCommerce from Dashboard > Updates.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		return $this->evaluate( $this->data ?? $this->collect() );
	}

	/**
	 * Read the live site.
	 *
	 * @return array<string,mixed>
	 */
	private function collect(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array( 'active' => false );
		}
		$current = WC()->version;
		$latest  = null;
		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) ) {
			$response = $plugins->response ?? null;
			$offer    = is_array( $response ) ? ( $response['woocommerce/woocommerce.php'] ?? null ) : null;
			if ( is_object( $offer ) && isset( $offer->new_version ) ) {
				$latest = Arr::to_string( $offer->new_version );
			} elseif ( Arr::to_int( $plugins->last_checked ?? null ) > 0 ) {
				$latest = $current;
			}
		}
		return array(
			'active'  => true,
			'current' => $current,
			'latest'  => $latest,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		if ( ! Arr::get_bool( $d, 'active' ) ) {
			return Result::info( __( 'WooCommerce is not active, so there is nothing to check.', 'store-audit-for-woocommerce' ) );
		}
		$current = Arr::get_string( $d, 'current' );
		$latest  = isset( $d['latest'] ) ? Arr::get_string( $d, 'latest' ) : null;

		// Defensive: the plugin header declares WooCommerce 9.0 as the minimum, so this branch is not reached on a supported install.
		if ( version_compare( $current, '9.0', '<' ) ) {
			return Result::critical(
				/* translators: %s: WooCommerce version. */
				sprintf( __( 'WooCommerce %s is very old and no longer receives security fixes.', 'store-audit-for-woocommerce' ), $current )
			);
		}

		if ( null === $latest ) {
			return Result::info(
				/* translators: %s: WooCommerce version. */
				sprintf( __( 'WooCommerce %s is installed. Could not compare, update data not available yet.', 'store-audit-for-woocommerce' ), $current )
			);
		}

		if ( version_compare( $current, $latest, '>=' ) ) {
			return Result::ok(
				/* translators: %s: WooCommerce version. */
				sprintf( __( 'WooCommerce %s is up to date.', 'store-audit-for-woocommerce' ), $current )
			);
		}

		if ( VersionBranch::of( $current ) !== VersionBranch::of( $latest ) ) {
			return Result::warning(
				/* translators: 1: installed WooCommerce version, 2: latest version. */
				sprintf( __( 'WooCommerce %1$s is installed, version %2$s is available.', 'store-audit-for-woocommerce' ), $current, $latest )
			);
		}

		return Result::info(
			/* translators: 1: installed WooCommerce version, 2: latest version. */
			sprintf( __( 'WooCommerce %1$s is installed, a small update (%2$s) is available.', 'store-audit-for-woocommerce' ), $current, $latest )
		);
	}
}
