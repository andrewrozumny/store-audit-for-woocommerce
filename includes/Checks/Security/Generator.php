<?php
/**
 * WordPress version shown in the page source.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress version shown in the page source.
 */
final class Generator extends AbstractProbeCheck {

	/**
	 * Latest WordPress version provider (tests).
	 *
	 * @var callable|null
	 */
	private $latest;

	/**
	 * Constructor.
	 *
	 * @param SelfProbe|null $probe  Probe override for tests.
	 * @param callable|null  $latest Returns the latest known WordPress version (tests).
	 */
	public function __construct( ?SelfProbe $probe = null, ?callable $latest = null ) {
		parent::__construct( $probe );
		$this->latest = $latest;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_generator';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'WordPress version exposed', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Every page of the store can tell visitors which WordPress version it runs. Attackers use this to pick known weaknesses of that exact version, especially if it is outdated.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Remove the generator tag (remove_action wp_head wp_generator) or use a security plugin, and keep WordPress updated.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$response = $this->probe->get( home_url( '/' ), 65536 );
		if ( ! $response->reachable() ) {
			return $this->unreachable( $response );
		}

		if ( 200 !== $response->status() || 1 !== preg_match( '/<meta\s+name=["\']generator["\']\s+content=["\']WordPress\s+([0-9][0-9A-Za-z.\-]*)/i', $response->body(), $match ) ) {
			return Result::ok( __( 'The page source does not show the WordPress version.', 'store-audit-for-woocommerce' ) );
		}

		$exposed = $match[1];
		$latest  = null !== $this->latest ? call_user_func( $this->latest ) : $this->default_latest();
		if ( is_string( $latest ) && '' !== $latest && version_compare( $exposed, $latest, '<' ) ) {
			return Result::warning(
				/* translators: 1: exposed version, 2: latest version. */
				sprintf( __( 'The page source shows WordPress %1$s, and %2$s is available.', 'store-audit-for-woocommerce' ), $exposed, $latest )
			);
		}
		return Result::info(
			/* translators: %s: version number. */
			sprintf( __( 'The page source shows the WordPress version (%s). It is up to date, but hiding it costs nothing.', 'store-audit-for-woocommerce' ), $exposed )
		);
	}

	/**
	 * Latest version according to the cached core update data (no network call).
	 */
	private function default_latest(): ?string {
		$updates = get_site_transient( 'update_core' );
		if ( is_object( $updates ) && isset( $updates->updates ) && is_array( $updates->updates ) ) {
			foreach ( $updates->updates as $update ) {
				if ( is_object( $update ) && isset( $update->response, $update->current ) && 'upgrade' === $update->response ) {
					return Arr::to_string( $update->current );
				}
			}
		}
		return null;
	}
}
