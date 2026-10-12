<?php
/**
 * HTTPS check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Environment;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTPS.
 */
final class Https extends AbstractCheck {

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
		return 'environment_https';
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
		return __( 'HTTPS', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'HTTPS encrypts what customers type, including addresses and card details. Without it browsers warn visitors that the store is not secure, and sales are lost.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Install an SSL certificate (most hosts offer one for free) and set both the WordPress Address and Site Address to https in Settings > General.', 'store-audit-for-woocommerce' );
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
		return array(
			'home'    => Arr::to_string( get_option( 'home' ) ),
			'siteurl' => Arr::to_string( get_option( 'siteurl' ) ),
			'ssl'     => is_ssl(),
			'cli'     => 'cli' === PHP_SAPI,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$home    = Arr::get_string( $d, 'home' );
		$siteurl = Arr::get_string( $d, 'siteurl' );
		$http    = array();
		if ( 0 !== stripos( $home, 'https://' ) ) {
			/* translators: %s: URL. */
			$http[] = sprintf( __( 'Site Address (home): %s', 'store-audit-for-woocommerce' ), $home );
		}
		if ( 0 !== stripos( $siteurl, 'https://' ) ) {
			/* translators: %s: URL. */
			$http[] = sprintf( __( 'WordPress Address (siteurl): %s', 'store-audit-for-woocommerce' ), $siteurl );
		}

		if ( array() !== $http ) {
			$summary = __( 'The store address does not use https.', 'store-audit-for-woocommerce' );
			if ( ! $this->is_production() ) {
				return Result::info(
					/* translators: %s: environment type. */
					sprintf( __( 'The store address does not use https, and the site declares itself a non-production environment (%s), so this is expected.', 'store-audit-for-woocommerce' ), wp_get_environment_type() ),
					$http
				);
			}
			return Result::critical( $summary, $http );
		}

		$details = array();
		if ( ! Arr::get_bool( $d, 'ssl' ) && ! Arr::get_bool( $d, 'cli' ) ) {
			$details[] = __( 'The current request was not detected as https. If you use a proxy or CDN, check that it passes the HTTPS information to WordPress.', 'store-audit-for-woocommerce' );
		}

		return Result::ok( __( 'Both the Site Address and the WordPress Address use https.', 'store-audit-for-woocommerce' ), $details );
	}
}
