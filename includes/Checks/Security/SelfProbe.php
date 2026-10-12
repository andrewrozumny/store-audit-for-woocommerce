<?php
/**
 * HTTP probe of the site's own URLs.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anonymous HTTP requests to this very site, never to anything else.
 *
 * Used to see what an outside visitor (or attacker) sees. Short timeout, no
 * redirects, no cookies, TLS certificates verified.
 */
final class SelfProbe {

	/**
	 * Transport: (method, url, args) returning a wp_remote_request style array or WP_Error.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param callable|null $transport Transport override for tests.
	 */
	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport ?? static function ( string $method, string $url, array $args ) {
			$request = array(
				'method'      => $method,
				'timeout'     => Arr::get_float( $args, 'timeout', 5.0 ),
				'redirection' => Arr::get_int( $args, 'redirection' ),
				'cookies'     => array(),
				'user-agent'  => Arr::get_string( $args, 'user-agent' ),
			);
			if ( array_key_exists( 'body', $args ) ) {
				$request['body'] = Arr::get_string( $args, 'body' );
			}
			if ( array_key_exists( 'limit_response_size', $args ) ) {
				$request['limit_response_size'] = Arr::get_int( $args, 'limit_response_size' );
			}
			if ( array_key_exists( 'headers', $args ) ) {
				$headers = array();
				foreach ( Arr::to_string_keyed( $args['headers'] ) as $name => $value ) {
					$headers[ $name ] = Arr::to_string( $value );
				}
				$request['headers'] = $headers;
			}
			return wp_remote_request( $url, $request );
		};
	}

	/**
	 * HEAD request.
	 *
	 * @param string $target  Path starting with / or a full URL on this site.
	 * @param int    $timeout Seconds.
	 */
	public function head( string $target, int $timeout = 5 ): ProbeResponse {
		return $this->request( 'HEAD', $target, array(), $timeout );
	}

	/**
	 * GET request reading at most $max_bytes of the body.
	 *
	 * @param string $target    Path starting with / or a full URL on this site.
	 * @param int    $max_bytes Body limit.
	 * @param int    $timeout   Seconds.
	 */
	public function get( string $target, int $max_bytes = 2048, int $timeout = 5 ): ProbeResponse {
		return $this->request( 'GET', $target, array( 'limit_response_size' => $max_bytes ), $timeout );
	}

	/**
	 * POST request with a small body.
	 *
	 * @param string               $target  Path or full URL on this site.
	 * @param string               $body    Request body.
	 * @param array<string,string> $headers Extra request headers.
	 * @param int                  $timeout Seconds.
	 */
	public function post( string $target, string $body, array $headers = array(), int $timeout = 5 ): ProbeResponse {
		return $this->request(
			'POST',
			$target,
			array(
				'body'                => $body,
				'headers'             => $headers,
				'limit_response_size' => 8192,
			),
			$timeout
		);
	}

	/**
	 * Resolve a path or URL into a URL of this site, or null when it is foreign. A full URL counts as this site only
	 * when scheme, host and port all match the home address or the site address, so another port or the other
	 * scheme of the same host is never requested.
	 *
	 * @param string $target Path or URL.
	 */
	public function resolve( string $target ): ?string {
		if ( '' === $target || 1 === preg_match( '/[\x00-\x20\x7f]/', $target ) ) {
			return null;
		}
		if ( str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ) {
			return site_url( $target );
		}

		$origin = self::origin( $target );
		if ( null === $origin ) {
			return null;
		}
		foreach ( array( home_url(), site_url() ) as $own ) {
			if ( self::origin( $own ) === $origin ) {
				return $target;
			}
		}
		return null;
	}

	/**
	 * Host of a well-formed http(s) address that is not an address of this site, or null for anything else.
	 *
	 * @param string $target Path or URL.
	 */
	private function foreign_host_of( string $target ): ?string {
		if ( '' === $target || 1 === preg_match( '/[\x00-\x20\x7f]/', $target ) || null !== $this->resolve( $target ) ) {
			return null;
		}
		$origin = self::origin( $target );
		return null === $origin ? null : $origin['host'];
	}

	/**
	 * Scheme, lower-case host and port (the default port of the scheme when none is written) of an http(s) URL.
	 *
	 * @param string $url URL.
	 * @return array{scheme:string,host:string,port:int}|null
	 */
	private static function origin( string $url ): ?array {
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		if ( ! is_string( $host ) || '' === $host || ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		$scheme = strtolower( $scheme );
		return array(
			'scheme' => $scheme,
			'host'   => strtolower( $host ),
			'port'   => is_int( $port ) ? $port : ( 'https' === $scheme ? 443 : 80 ),
		);
	}

	/**
	 * Perform a request.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $target  Path or URL on this site.
	 * @param array<string,mixed> $extra   Extra request args.
	 * @param int                 $timeout Seconds (capped at 5).
	 */
	private function request( string $method, string $target, array $extra, int $timeout ): ProbeResponse {
		$url = $this->resolve( $target );
		if ( null === $url ) {
			$foreign = $this->foreign_host_of( $target );
			return null !== $foreign
				? ProbeResponse::foreign_host( $foreign )
				: ProbeResponse::failure( __( 'Refused: not an address of this site.', 'store-audit-for-woocommerce' ) );
		}

		$version = defined( 'STORE_AUDIT_VERSION' ) ? STORE_AUDIT_VERSION : '0';
		$args    = array_merge(
			array(
				'timeout'     => max( 1, min( 5, $timeout ) ),
				'redirection' => 0,
				'cookies'     => array(),
				'user-agent'  => 'StoreAudit/' . $version,
			),
			$extra
		);

		$raw = call_user_func( $this->transport, $method, $url, $args );
		if ( is_wp_error( $raw ) ) {
			return ProbeResponse::failure( $raw->get_error_message() );
		}
		if ( ! is_array( $raw ) ) {
			return ProbeResponse::failure( __( 'Unexpected answer.', 'store-audit-for-woocommerce' ) );
		}

		$response = $raw['response'] ?? array();
		$status   = is_array( $response ) ? Arr::get_int( $response, 'code' ) : 0;
		if ( $status < 100 ) {
			return ProbeResponse::failure( __( 'No HTTP status.', 'store-audit-for-woocommerce' ) );
		}

		$headers = array();
		$source  = $raw['headers'] ?? array();
		if ( $source instanceof \Traversable ) {
			$source = iterator_to_array( $source );
		}
		if ( is_array( $source ) ) {
			foreach ( $source as $name => $value ) {
				$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', Arr::to_string_list( $value ) ) : Arr::to_string( $value );
			}
		}

		$body = isset( $raw['body'] ) && is_string( $raw['body'] ) ? $raw['body'] : '';
		return new ProbeResponse( true, $status, $headers, $body );
	}
}
