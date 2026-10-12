<?php
/**
 * Leftover WordPress readme and license files.
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
 * Leftover WordPress readme and license files.
 */
final class ReadmeLicense extends AbstractProbeCheck {

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
		return 'security_readme_license';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'WordPress readme and license files', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'The files readme.html and license.txt in the site root reveal the WordPress version to anyone who asks. They are harmless alone, but they help attackers choose what to try.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Delete readme.html and license.txt from the site root (WordPress puts them back on each update).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$served = array();
		foreach ( array( 'readme.html', 'license.txt' ) as $file ) {
			if ( ! call_user_func( $this->exists, trailingslashit( $this->root ) . $file ) ) {
				continue;
			}
			$response = $this->probe->head( '/' . $file, 3 );
			if ( ! $response->reachable() ) {
				return $this->unreachable( $response );
			}
			if ( 200 === $response->status() ) {
				$served[] = $file;
			}
		}

		if ( array() === $served ) {
			return Result::ok( __( 'readme.html and license.txt are not served.', 'store-audit-for-woocommerce' ) );
		}
		return Result::info(
			/* translators: %s: file names. */
			sprintf( _n( 'This file reveals the WordPress version and can be removed: %s', 'These files reveal the WordPress version and can be removed: %s', count( $served ), 'store-audit-for-woocommerce' ), implode( ', ', $served ) ),
			$served
		);
	}
}
