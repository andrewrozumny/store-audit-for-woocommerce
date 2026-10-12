<?php
/**
 * Minimal, cached client for the wordpress.org plugin and theme info API.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Plugins;

use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only external host this plugin talks to is api.wordpress.org.
 *
 * Lookups return a small array: status (found, closed, not_found, unavailable),
 * version, last_updated (unix timestamp or 0) and reason. Results (including
 * "not found") are cached in a transient for 12 hours; failures are not cached.
 */
final class WporgApi {

	public const FOUND       = 'found';
	public const CLOSED      = 'closed';
	public const NOT_FOUND   = 'not_found';
	public const UNAVAILABLE = 'unavailable';

	private const CACHE_TTL = 12 * 3600;

	/**
	 * HTTP fetcher: receives a URL, returns array{code:int,body:string} or null on failure.
	 *
	 * @var callable
	 */
	private $fetcher;

	/**
	 * Cache reader: receives a key, returns the cached array or false.
	 *
	 * @var callable
	 */
	private $cache_get;

	/**
	 * Cache writer: receives key, value, ttl.
	 *
	 * @var callable
	 */
	private $cache_set;

	/**
	 * Number of real HTTP requests made by this instance.
	 *
	 * @var int
	 */
	private int $requests = 0;

	/**
	 * Constructor.
	 *
	 * @param callable|null $fetcher   HTTP fetcher override for tests.
	 * @param callable|null $cache_get Cache reader override for tests.
	 * @param callable|null $cache_set Cache writer override for tests.
	 */
	public function __construct( ?callable $fetcher = null, ?callable $cache_get = null, ?callable $cache_set = null ) {
		$this->fetcher   = $fetcher ?? array( $this, 'http_fetch' );
		$this->cache_get = $cache_get ?? static fn( string $key ) => get_transient( $key );
		$this->cache_set = $cache_set ?? static function ( string $key, array $value, int $ttl ): void {
			set_transient( $key, $value, $ttl );
		};
	}

	/**
	 * Slug of a plugin from its main file ("akismet/akismet.php" gives "akismet", "hello.php" gives "hello").
	 *
	 * @param string $plugin_file Path relative to the plugins directory.
	 */
	public static function slug_from_file( string $plugin_file ): string {
		$dir = dirname( $plugin_file );
		if ( '.' !== $dir && '' !== $dir ) {
			return explode( '/', $plugin_file )[0];
		}
		return basename( $plugin_file, '.php' );
	}

	/**
	 * Real HTTP requests made so far (cache hits do not count).
	 */
	public function request_count(): int {
		return $this->requests;
	}

	/**
	 * Look up a plugin.
	 *
	 * @param string $slug Plugin slug.
	 * @return array{status:string,version:string,last_updated:int,reason:string}
	 */
	public function plugin( string $slug ): array {
		$url = add_query_arg(
			array(
				'action' => 'plugin_information',
				'slug'   => $slug,
			),
			'https://api.wordpress.org/plugins/info/1.2/'
		);
		return $this->lookup( 'plugin', $slug, $url );
	}

	/**
	 * Look up a theme.
	 *
	 * @param string $slug Theme slug.
	 * @return array{status:string,version:string,last_updated:int,reason:string}
	 */
	public function theme( string $slug ): array {
		$url = add_query_arg(
			array(
				'action' => 'theme_information',
				'slug'   => $slug,
			),
			'https://api.wordpress.org/themes/info/1.2/'
		);
		return $this->lookup( 'theme', $slug, $url );
	}

	/**
	 * Cached lookup.
	 *
	 * @param string $type "plugin" or "theme".
	 * @param string $slug Slug.
	 * @param string $url  Request URL.
	 * @return array{status:string,version:string,last_updated:int,reason:string}
	 */
	private function lookup( string $type, string $slug, string $url ): array {
		$key    = 'store_audit_wporg_' . md5( $type . ':' . $slug );
		$cached = ( $this->cache_get )( $key );
		if ( is_array( $cached ) && in_array( $cached['status'] ?? null, array( self::FOUND, self::CLOSED, self::NOT_FOUND ), true ) ) {
			return array(
				'status'       => Arr::get_string( $cached, 'status' ),
				'version'      => Arr::get_string( $cached, 'version' ),
				'last_updated' => Arr::get_int( $cached, 'last_updated' ),
				'reason'       => Arr::get_string( $cached, 'reason' ),
			);
		}

		++$this->requests;
		$response = ( $this->fetcher )( $url );
		$parsed   = $this->parse( $response );
		if ( self::UNAVAILABLE !== $parsed['status'] ) {
			( $this->cache_set )( $key, $parsed, self::CACHE_TTL );
		}
		return $parsed;
	}

	/**
	 * Turn an HTTP response into the normalised array.
	 *
	 * @param mixed $response Response from the fetcher: array{code:int,body:string} or null.
	 * @return array{status:string,version:string,last_updated:int,reason:string}
	 */
	private function parse( mixed $response ): array {
		$empty = array(
			'status'       => self::UNAVAILABLE,
			'version'      => '',
			'last_updated' => 0,
			'reason'       => '',
		);
		if ( ! is_array( $response ) ) {
			return $empty;
		}

		$data = json_decode( Arr::get_string( $response, 'body' ), true );
		if ( ! is_array( $data ) ) {
			// Unreadable answer, or a server error: treat as unavailable, not as "not found".
			return $empty;
		}

		if ( isset( $data['error'] ) ) {
			if ( Arr::get_bool( $data, 'closed' ) || 'closed' === $data['error'] ) {
				return array_merge(
					$empty,
					array(
						'status' => self::CLOSED,
						'reason' => isset( $data['reason_text'] ) && is_string( $data['reason_text'] ) ? self::short_text( $data['reason_text'] ) : '',
					)
				);
			}
			if ( is_string( $data['error'] ) && false !== stripos( $data['error'], 'not found' ) ) {
				return array_merge( $empty, array( 'status' => self::NOT_FOUND ) );
			}
			return $empty;
		}

		if ( 200 !== Arr::get_int( $response, 'code' ) ) {
			return $empty;
		}

		$last_updated = 0;
		if ( isset( $data['last_updated'] ) && is_string( $data['last_updated'] ) ) {
			$parsed_time  = strtotime( $data['last_updated'] );
			$last_updated = false === $parsed_time ? 0 : $parsed_time;
		}

		return array(
			'status'       => self::FOUND,
			'version'      => isset( $data['version'] ) && is_string( $data['version'] ) ? $data['version'] : '',
			'last_updated' => $last_updated,
			'reason'       => '',
		);
	}

	/**
	 * Text from a remote answer as one short plain line (at most 300 characters).
	 *
	 * @param string $text Raw text.
	 */
	private static function short_text( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		return mb_strlen( $text ) > 300 ? mb_substr( $text, 0, 300 ) . '...' : $text;
	}

	/**
	 * Production HTTP fetcher: wp_remote_get, 5 second timeout.
	 *
	 * @param string $url URL on api.wordpress.org.
	 * @return array{code:int,body:string}|null
	 */
	private function http_fetch( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		return array(
			'code' => Arr::to_int( wp_remote_retrieve_response_code( $response ) ),
			'body' => wp_remote_retrieve_body( $response ),
		);
	}
}
