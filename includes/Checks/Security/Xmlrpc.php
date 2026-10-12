<?php
/**
 * XML-RPC endpoint exposure.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Result;
use StoreAudit\Support\ActivePlugins;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XML-RPC endpoint exposure.
 */
final class Xmlrpc extends AbstractProbeCheck {

	/**
	 * Returns the names of the things on this site that need xmlrpc.php.
	 *
	 * @var \Closure
	 */
	private \Closure $dependents;

	/**
	 * Constructor.
	 *
	 * @param SelfProbe|null $probe      Probe override for tests.
	 * @param callable|null  $dependents Returns the names of the things that need xmlrpc.php (tests).
	 */
	public function __construct( ?SelfProbe $probe = null, ?callable $dependents = null ) {
		parent::__construct( $probe );
		$this->dependents = \Closure::fromCallable( $dependents ?? array( $this, 'read_dependents' ) );
	}

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
			$needed_by = Arr::to_string_list( ( $this->dependents )() );
			if ( array() !== $needed_by ) {
				return Result::info(
					__( 'XML-RPC is open, and Jetpack or the WordPress.com connection needs it, so it should stay reachable.', 'store-audit-for-woocommerce' ),
					array(
						/* translators: %s: plugin names. */
						sprintf( __( 'Needed by: %s', 'store-audit-for-woocommerce' ), implode( ', ', $needed_by ) ),
					),
					__( 'Keep xmlrpc.php open for Jetpack, but block system.multicall and pingbacks (most security plugins have a switch).', 'store-audit-for-woocommerce' )
				);
			}
			return Result::warning( __( 'XML-RPC is open: anyone can call /xmlrpc.php, which is used for password guessing at scale.', 'store-audit-for-woocommerce' ) );
		}
		return Result::ok( __( 'XML-RPC is not answering requests.', 'store-audit-for-woocommerce' ) );
	}

	/**
	 * Jetpack, WooPayments and a stored Jetpack connection, which all talk to the site through xmlrpc.php.
	 *
	 * @return list<string>
	 */
	private function read_dependents(): array {
		$slugs = ActivePlugins::slugs();
		$names = array();
		if ( in_array( 'jetpack', $slugs, true ) ) {
			$names[] = 'Jetpack';
		}
		if ( in_array( 'woocommerce-payments', $slugs, true ) ) {
			$names[] = 'WooPayments';
		}
		$options = Arr::to_array( get_option( 'jetpack_options', array() ) );
		if ( Arr::get_int( $options, 'id' ) > 0 ) {
			$names[] = 'Jetpack connection';
		}
		return $names;
	}
}
