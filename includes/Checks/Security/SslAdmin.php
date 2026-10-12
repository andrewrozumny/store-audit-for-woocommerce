<?php
/**
 * Forced secure login and admin.
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
 * Forced secure login and admin.
 */
final class SslAdmin extends AbstractSecurityCheck {

	/**
	 * FORCE_SSL_ADMIN value.
	 *
	 * @var bool
	 */
	private ?bool $forced;

	/**
	 * Whether the site runs on https (null means detect).
	 *
	 * @var bool|null
	 */
	private ?bool $https;

	/**
	 * Constructor.
	 *
	 * @param bool|null $forced FORCE_SSL_ADMIN override (tests).
	 * @param bool|null $https  https override (tests).
	 */
	public function __construct( ?bool $forced = null, ?bool $https = null ) {
		$this->forced = $forced;
		$this->https  = $https;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_ssl_admin';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Secure admin (FORCE_SSL_ADMIN)', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Forcing HTTPS for login and admin pages guarantees that passwords and session cookies never travel unencrypted, even if some link or plugin still points to the plain http address.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Add define( \'FORCE_SSL_ADMIN\', true ); to wp-config.php.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$https = $this->https ?? str_starts_with( home_url(), 'https://' );
		if ( ! $https ) {
			return Result::info( __( 'The site address is not https, so forced secure admin does not apply yet.', 'store-audit-for-woocommerce' ) );
		}
		if ( $this->forced ?? force_ssl_admin() ) {
			return Result::ok( __( 'Login and admin pages are forced to use https.', 'store-audit-for-woocommerce' ) );
		}
		return Result::info( __( 'The site uses https, but FORCE_SSL_ADMIN is not set in wp-config.php.', 'store-audit-for-woocommerce' ) );
	}
}
