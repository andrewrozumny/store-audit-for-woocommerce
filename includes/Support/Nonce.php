<?php
/**
 * Request nonce guard.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress turns a nonce into a string without checking its type, so a nonce sent as an array (nonce[]=x) makes
 * core raise a PHP warning. Requests like that are refused before core sees them.
 */
final class Nonce {

	/**
	 * Whether the request field is absent or plain text. Only the type is looked at, the nonce itself is
	 * verified by WordPress straight after.
	 *
	 * @param string $field Request field name.
	 */
	public static function is_plain( string $field ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only the type of the value is inspected here, never the value.
		return ! isset( $_REQUEST[ $field ] ) || is_string( $_REQUEST[ $field ] );
	}
}
