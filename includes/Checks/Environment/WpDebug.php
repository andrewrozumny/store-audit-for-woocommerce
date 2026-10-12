<?php
/**
 * WP_DEBUG check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Environment;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug mode should be off on a live store.
 */
final class WpDebug extends AbstractCheck {

	/**
	 * WP_DEBUG value.
	 *
	 * @var bool
	 */
	private bool $debug;

	/**
	 * WP_DEBUG_DISPLAY value (WordPress defaults to true when undefined).
	 *
	 * @var bool
	 */
	private bool $display;

	/**
	 * Constructor.
	 *
	 * @param bool|null $debug   WP_DEBUG override for tests.
	 * @param bool|null $display WP_DEBUG_DISPLAY override for tests.
	 */
	public function __construct( ?bool $debug = null, ?bool $display = null ) {
		$this->debug   = $debug ?? ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		$this->display = $display ?? ( ! defined( 'WP_DEBUG_DISPLAY' ) || (bool) WP_DEBUG_DISPLAY );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'environment_wp_debug';
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
		return __( 'Debug mode (WP_DEBUG)', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Debug mode is meant for developers. On a live store it can show technical error messages to customers, reveal server paths to attackers, and slow the site down.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Set WP_DEBUG to false in wp-config.php (or ask your developer or host to do it).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		if ( ! $this->debug ) {
			return Result::ok( __( 'Debug mode is off.', 'store-audit-for-woocommerce' ) );
		}

		if ( ! $this->is_production() ) {
			return Result::info(
				/* translators: %s: environment type, e.g. local, development, staging. */
				sprintf( __( 'Debug mode is on, and the site declares itself a non-production environment (%s), so this is expected.', 'store-audit-for-woocommerce' ), wp_get_environment_type() )
			);
		}

		if ( $this->display ) {
			return Result::critical( __( 'Debug mode is on and errors are shown on the screen of a live site.', 'store-audit-for-woocommerce' ) );
		}

		return Result::warning( __( 'Debug mode is enabled on a live site. Errors are hidden from visitors but still logged.', 'store-audit-for-woocommerce' ) );
	}
}
