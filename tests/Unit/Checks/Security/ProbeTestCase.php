<?php
/**
 * Base test case for checks that use SelfProbe.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Security;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Security\SelfProbe;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Stubs the URL helpers and builds fake probes.
 */
abstract class ProbeTestCase extends TestCase {

	/**
	 * Requests seen by the fake transport.
	 *
	 * @var list<array{method:string,url:string,args:array<string,mixed>}>
	 */
	protected array $requests = array();

	/**
	 * Stub the URL helpers.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'site_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test' . $path );
		Functions\when( 'rest_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.test/wp-json/' . $path );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'trailingslashit' )->alias( static fn ( string $value ): string => rtrim( $value, '/\\' ) . '/' );
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'baseurl' => 'https://shop.test/wp-content/uploads' ) );
		$this->requests = array();
	}

	/**
	 * Build a probe answering by path.
	 *
	 * @param array<string,array{0:int,1?:string}> $routes Path (after the host) => status and body. Unlisted paths answer 404.
	 */
	protected function probe( array $routes ): SelfProbe {
		return new SelfProbe(
			function ( string $method, string $url, array $args ) use ( $routes ): array {
				$this->requests[] = array(
					'method' => $method,
					'url'    => $url,
					'args'   => $args,
				);
				$path             = (string) wp_parse_url( $url, PHP_URL_PATH );
				$route            = $routes[ $path ] ?? array( 404, '' );
				return array(
					'response' => array( 'code' => $route[0] ),
					'headers'  => array( 'Content-Type' => 'text/html' ),
					'body'     => $route[1] ?? '',
				);
			}
		);
	}

	/**
	 * Build a probe whose requests all fail.
	 */
	protected function broken_probe(): SelfProbe {
		return new SelfProbe(
			static function (): array {
				return array();
			}
		);
	}
}
