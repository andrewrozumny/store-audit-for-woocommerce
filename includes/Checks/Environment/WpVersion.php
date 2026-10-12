<?php
/**
 * WordPress version check.
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
 * WordPress version.
 */
final class WpVersion extends AbstractCheck {

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
		return 'environment_wp_version';
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
		return __( 'WordPress version', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Each WordPress update fixes bugs and security holes. A store running an old core is easier to hack and may stop working with newer plugins.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Back up the site, then update WordPress from Dashboard > Updates.', 'store-audit-for-woocommerce' );
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
		$current = get_bloginfo( 'version' );
		$latest  = null;
		$core    = get_site_transient( 'update_core' );
		if ( is_object( $core ) && Arr::to_int( $core->last_checked ?? null ) > 0 && isset( $core->updates ) && is_array( $core->updates ) ) {
			$latest = $current;
			foreach ( $core->updates as $offer ) {
				if ( is_object( $offer ) && isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response && version_compare( Arr::to_string( $offer->current ), $latest, '>' ) ) {
					$latest = Arr::to_string( $offer->current );
				}
			}
		}
		return array(
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
		$current = Arr::get_string( $d, 'current' );
		$latest  = isset( $d['latest'] ) ? Arr::get_string( $d, 'latest' ) : null;

		if ( null === $latest ) {
			return Result::info(
				/* translators: %s: WordPress version. */
				sprintf( __( 'WordPress %s is installed. Could not compare, update data not available yet.', 'store-audit-for-woocommerce' ), $current )
			);
		}

		if ( version_compare( $current, $latest, '>=' ) ) {
			return Result::ok(
				/* translators: %s: WordPress version. */
				sprintf( __( 'WordPress %s is up to date.', 'store-audit-for-woocommerce' ), $current )
			);
		}

		if ( VersionBranch::of( $current ) !== VersionBranch::of( $latest ) ) {
			return Result::warning(
				/* translators: 1: installed WordPress version, 2: latest version. */
				sprintf( __( 'WordPress %1$s is installed, a newer major release (%2$s) is available.', 'store-audit-for-woocommerce' ), $current, $latest )
			);
		}

		return Result::info(
			/* translators: 1: installed WordPress version, 2: latest version. */
			sprintf( __( 'WordPress %1$s is installed, a minor update (%2$s) is available. Minor updates usually contain security fixes.', 'store-audit-for-woocommerce' ), $current, $latest )
		);
	}
}
