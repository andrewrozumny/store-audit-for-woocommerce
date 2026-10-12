<?php
/**
 * HTTP probe of the site's own URLs.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

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
			$args['method'] = $method;
			return wp_remote_request( $url, $args );
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
	 * Resolve a path or URL into a URL of this site, or null when it is foreign.
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

		$host   = wp_parse_url( $target, PHP_URL_HOST );
		$scheme = wp_parse_url( $target, PHP_URL_SCHEME );
		if ( ! is_string( $host ) || ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		foreach ( array( home_url(), site_url() ) as $own ) {
			$own_host = wp_parse_url( $own, PHP_URL_HOST );
			if ( is_string( $own_host ) && strtolower( $own_host ) === strtolower( $host ) ) {
				return $target;
			}
		}
		return null;
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
			return ProbeResponse::failure( 'Refused: not an address of this site.' );
		}

		$version = defined( 'STORE_AUDIT_VERSION' ) ? (string) STORE_AUDIT_VERSION : '0';
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
			return ProbeResponse::failure( 'Unexpected answer.' );
		}

		$response = $raw['response'] ?? array();
		$status   = is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0;
		if ( $status < 100 ) {
			return ProbeResponse::failure( 'No HTTP status.' );
		}

		$headers = array();
		$source  = $raw['headers'] ?? array();
		if ( $source instanceof \Traversable ) {
			$source = iterator_to_array( $source );
		}
		if ( is_array( $source ) ) {
			foreach ( $source as $name => $value ) {
				$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}
		}

		$body = isset( $raw['body'] ) && is_string( $raw['body'] ) ? $raw['body'] : '';
		return new ProbeResponse( true, $status, $headers, $body );
	}
}
