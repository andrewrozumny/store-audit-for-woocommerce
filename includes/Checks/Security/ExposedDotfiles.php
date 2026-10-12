<?php
/**
 * Version control and environment files reachable from the web.
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
 * Version control and environment files reachable from the web.
 */
final class ExposedDotfiles extends AbstractProbeCheck {

	/**
	 * Site root path.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Existence test.
	 *
	 * @var callable
	 */
	private $exists;

	/**
	 * Constructor.
	 *
	 * @param SelfProbe|null $probe  Probe override for tests.
	 * @param string|null    $root   WordPress root path (tests).
	 * @param callable|null  $exists File existence test (tests).
	 */
	public function __construct( ?SelfProbe $probe = null, ?string $root = null, ?callable $exists = null ) {
		parent::__construct( $probe );
		$this->root   = $root ?? (string) ABSPATH;
		$this->exists = $exists ?? 'file_exists';
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_exposed_dotfiles';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Exposed .git and .env files', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'A reachable .git folder lets anyone download your whole source code, and a reachable .env file usually holds passwords and API keys. Either one can lead to a full takeover.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Block access to dot-files in the web server config and remove .git and .env from the public folder.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$served = array();
		$rules  = array(
			'/.git/HEAD'   => static fn ( string $body ): bool => str_starts_with( ltrim( $body ), 'ref:' ),
			'/.git/config' => static fn ( string $body ): bool => str_contains( $body, '[core]' ),
			'/.env'        => static fn ( string $body ): bool => '' !== trim( $body ) && 1 !== preg_match( '/<\s*(!doctype|html|head|body)/i', substr( $body, 0, 512 ) ),
		);
		foreach ( $rules as $path => $looks_exposed ) {
			$response = $this->probe->get( $path, 512, 2 );
			if ( ! $response->reachable() ) {
				return $this->unreachable( $response );
			}
			if ( 200 === $response->status() && $looks_exposed( $response->body() ) ) {
				$served[] = $path;
			}
		}

		if ( array() !== $served ) {
			return Result::critical(
				/* translators: %s: paths. */
				sprintf( __( 'These private files can be downloaded by anyone: %s', 'store-audit-for-woocommerce' ), implode( ', ', $served ) ),
				$served
			);
		}

		$on_disk = array();
		foreach ( array( '.git', '.env' ) as $name ) {
			if ( call_user_func( $this->exists, trailingslashit( $this->root ) . $name ) ) {
				$on_disk[] = $name;
			}
		}
		if ( array() !== $on_disk ) {
			return Result::info(
				/* translators: %s: names. */
				sprintf( __( 'Present in the site root but not reachable from the web: %s. Keep it that way.', 'store-audit-for-woocommerce' ), implode( ', ', $on_disk ) ),
				$on_disk
			);
		}
		return Result::ok( __( 'No .git or .env files are reachable from the web.', 'store-audit-for-woocommerce' ) );
	}
}
