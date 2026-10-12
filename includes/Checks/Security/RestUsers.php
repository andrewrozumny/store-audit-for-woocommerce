<?php
/**
 * Public user list in the REST API.
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
 * Public user list in the REST API.
 */
final class RestUsers extends AbstractProbeCheck {

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_rest_users';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'User list in the REST API', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'WordPress can publish a list of user names to any visitor. Bots use it to learn valid logins and then guess passwords, so a store that exposes it is easier to break into.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Restrict /wp-json/wp/v2/users to logged-in users with a security plugin or a small code snippet.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$response = $this->probe->get( rest_url( 'wp/v2/users' ), 65536 );
		if ( ! $response->reachable() ) {
			return $this->unreachable( $response );
		}

		$slugs = array();
		if ( 200 === $response->status() && 1 === preg_match( '/^\s*\[/', $response->body() ) ) {
			preg_match_all( '/"slug"\s*:\s*"([^"]{1,60})"/', $response->body(), $found );
			$slugs = array_values( array_unique( $found[1] ) );
		}

		if ( array() === $slugs ) {
			return Result::ok( __( 'The REST API does not list user names to visitors.', 'store-audit-for-woocommerce' ) );
		}
		return Result::warning(
			/* translators: %d: number of user names. */
			sprintf( _n( 'The REST API lets anyone list login names (%d found).', 'The REST API lets anyone list login names (%d found).', count( $slugs ), 'store-audit-for-woocommerce' ), count( $slugs ) ),
			array_slice( $slugs, 0, 10 )
		);
	}

	/**
	 * Details name accounts, files or keys, so exports do not carry them.
	 */
	public function is_sensitive(): bool {
		return true;
	}
}
