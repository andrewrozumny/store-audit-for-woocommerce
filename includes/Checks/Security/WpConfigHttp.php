<?php
/**
 * Configuration file served as text.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration file served as text.
 */
final class WpConfigHttp extends AbstractProbeCheck {

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_wp_config_http';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'wp-config.php exposure', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'The file wp-config.php holds your database password. If the server ever shows it as plain text instead of running it, anyone can take over your database, customers and orders.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Fix the server so PHP files are always executed, and change the database password and secret keys at once.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$response = $this->probe->get( '/wp-config.php', 4096 );
		if ( ! $response->reachable() ) {
			return $this->unreachable( $response );
		}

		$body = $response->body();
		if ( 200 === $response->status() && ( str_contains( $body, 'DB_PASSWORD' ) || str_contains( $body, '<?php' ) ) ) {
			return Result::critical( __( 'wp-config.php is downloadable as text: the database password is public.', 'store-audit-for-woocommerce' ) );
		}
		return Result::ok( __( 'wp-config.php is not readable from the web.', 'store-audit-for-woocommerce' ) );
	}
}
