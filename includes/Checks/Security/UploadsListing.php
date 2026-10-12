<?php
/**
 * Directory listing of the uploads folder.
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
 * Directory listing of the uploads folder.
 */
final class UploadsListing extends AbstractProbeCheck {

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_uploads_listing';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Uploads folder listing', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'If the web server lists the files of your uploads folder, anyone can browse and download everything in it, including invoices, exports and private documents.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Disable directory listing on your server (Options -Indexes in .htaccess, or autoindex off in nginx).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$upload = wp_upload_dir( null, false );
		$base   = isset( $upload['baseurl'] ) ? (string) $upload['baseurl'] : '';
		if ( '' === $base ) {
			return Result::info( __( 'The uploads folder address is unknown, so this check was skipped.', 'store-audit-for-woocommerce' ) );
		}

		$response = $this->probe->get( trailingslashit( $base ), 4096 );
		if ( ! $response->reachable() ) {
			return $this->unreachable( $response );
		}
		if ( 200 === $response->status() && str_contains( $response->body(), 'Index of' ) ) {
			return Result::warning( __( 'The uploads folder shows a file listing to any visitor.', 'store-audit-for-woocommerce' ) );
		}
		return Result::ok( __( 'The uploads folder does not show a file listing.', 'store-audit-for-woocommerce' ) );
	}
}
