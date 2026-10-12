<?php
/**
 * XML-RPC endpoint exposure.
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
 * XML-RPC endpoint exposure.
 */
final class Xmlrpc extends AbstractProbeCheck {

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_xmlrpc';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'XML-RPC', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'XML-RPC is an old remote access door of WordPress that bots use to try thousands of passwords in one request. Most stores do not need it, so leaving it open only helps attackers.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Disable XML-RPC with a security plugin, or ask your host to block /xmlrpc.php.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$enabled = apply_filters( 'xmlrpc_enabled', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		if ( ! $enabled ) {
			return Result::ok( __( 'XML-RPC is switched off.', 'store-audit-for-woocommerce' ) );
		}

		$body     = '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>';
		$response = $this->probe->post( '/xmlrpc.php', $body, array( 'Content-Type' => 'text/xml' ) );
		if ( ! $response->reachable() ) {
			return $this->unreachable( $response );
		}

		$answer = $response->body();
		if ( 200 === $response->status() && str_contains( $answer, '<methodResponse' ) && ! str_contains( $answer, '<fault>' ) ) {
			return Result::warning( __( 'XML-RPC is open: anyone can call /xmlrpc.php, which is used for password guessing at scale.', 'store-audit-for-woocommerce' ) );
		}
		return Result::ok( __( 'XML-RPC is not answering requests.', 'store-audit-for-woocommerce' ) );
	}
}
