<?php
/**
 * Memory limit check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Environment;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\EnvSnapshot;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memory limit.
 */
final class MemoryLimit extends AbstractCheck {

	/**
	 * Data to evaluate (tests) or null to read the live site.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $data Pre-collected data for tests; null reads the live site.
	 */
	public function __construct( ?array $data = null ) {
		$this->data = $data;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'environment_memory_limit';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::ENVIRONMENT;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Memory limit', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Memory is the working space PHP gets for each page load. A store with too little hits blank pages and failed checkouts, especially with many plugins or big imports.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Ask your host to raise the PHP memory limit to at least 256M, or set WP_MEMORY_LIMIT in wp-config.php.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		return $this->evaluate( $this->data ?? $this->collect() );
	}

	/**
	 * Read the values the site had when the plugin loaded, before the audit raised the limits for its own work.
	 *
	 * @return array<string,mixed>
	 */
	private function collect(): array {
		$snapshot = EnvSnapshot::get();
		return array(
			'wp'  => '' !== $snapshot['wp_memory_limit'] ? wp_convert_hr_to_bytes( $snapshot['wp_memory_limit'] ) : 0,
			'php' => wp_convert_hr_to_bytes( $snapshot['memory_limit'] ),
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$wp  = Arr::get_int( $d, 'wp' );
		$php = isset( $d['php'] ) ? Arr::get_int( $d, 'php' ) : null;

		$describe = function ( ?int $bytes ): string {
			if ( null === $bytes ) {
				return __( 'unknown', 'store-audit-for-woocommerce' );
			}
			return -1 === $bytes ? __( 'unlimited', 'store-audit-for-woocommerce' ) : $this->format_bytes( $bytes );
		};

		$details = array(
			/* translators: %s: memory size. */
			sprintf( __( 'WP_MEMORY_LIMIT: %s', 'store-audit-for-woocommerce' ), $describe( $wp ) ),
			/* translators: %s: memory size. */
			sprintf( __( 'PHP memory_limit: %s', 'store-audit-for-woocommerce' ), $describe( $php ) ),
		);

		if ( -1 === $wp || -1 === $php ) {
			return Result::ok( __( 'Memory is not limited.', 'store-audit-for-woocommerce' ), $details );
		}

		// What a page load gets is the higher of the two values, provided WordPress may raise the PHP limit.
		$effective = max( $wp, (int) $php );
		if ( $wp > (int) $php ) {
			$details[] = __( 'WordPress raises the PHP limit to WP_MEMORY_LIMIT only when the host allows changing it while the site runs. If the host does not, pages get the lower PHP memory_limit.', 'store-audit-for-woocommerce' );
		}

		if ( $effective < 134217728 ) {
			return Result::critical(
				/* translators: %s: memory size. */
				sprintf( __( 'Only %s of memory is available to PHP, too little for WooCommerce.', 'store-audit-for-woocommerce' ), $this->format_bytes( $effective ) ),
				$details
			);
		}

		if ( $effective < 268435456 ) {
			return Result::warning(
				/* translators: %s: memory size. */
				sprintf( __( '%s of memory is available to PHP, 256M or more is recommended for a store.', 'store-audit-for-woocommerce' ), $this->format_bytes( $effective ) ),
				$details
			);
		}

		return Result::ok(
			/* translators: %s: memory size. */
			sprintf( __( '%s of memory is available to PHP.', 'store-audit-for-woocommerce' ), $this->format_bytes( $effective ) ),
			$details
		);
	}
}
