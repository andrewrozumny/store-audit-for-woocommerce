<?php
/**
 * WordPress Heartbeat check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Performance;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Heartbeat.
 */
final class Heartbeat extends AbstractCheck {

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
		return 'performance_heartbeat';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::PERFORMANCE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'WordPress Heartbeat', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'The Heartbeat is a background ping the dashboard sends to the server every few seconds. A fast ping rate, or many plugins listening to it, can overload a small server.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Slow the Heartbeat down (60 seconds is a good value) with a performance plugin or a small code snippet.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		return $this->evaluate( $this->data ?? $this->collect() );
	}

	/**
	 * Read the live site.
	 *
	 * @return array<string,mixed>
	 */
	private function collect(): array {
		$settings = apply_filters( 'heartbeat_settings', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$count    = static function ( string $tag ): int {
			$hook = $GLOBALS['wp_filter'][ $tag ] ?? null;
			if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
				return 0;
			}
			$n = 0;
			foreach ( $hook->callbacks as $priority ) {
				$n += is_array( $priority ) ? count( $priority ) : 0;
			}
			return $n;
		};
		return array(
			'interval'  => is_array( $settings ) && isset( $settings['interval'] ) ? (int) $settings['interval'] : null,
			'callbacks' => $count( 'wp_ajax_heartbeat' ) + $count( 'heartbeat_received' ),
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$interval  = isset( $d['interval'] ) ? (int) $d['interval'] : null;
		$callbacks = (int) ( $d['callbacks'] ?? 0 );
		$details   = array();

		if ( null === $interval ) {
			$details[] = __( 'Interval: WordPress default (60 seconds in the dashboard, 15 seconds while editing a post).', 'store-audit-for-woocommerce' );
		} else {
			/* translators: %d: seconds. */
			$details[] = sprintf( _n( 'Interval set by a plugin or theme: %d second.', 'Interval set by a plugin or theme: %d seconds.', $interval, 'store-audit-for-woocommerce' ), $interval );
		}
		/* translators: %d: number of callbacks. */
		$details[] = sprintf( __( 'Functions listening to the Heartbeat: %d', 'store-audit-for-woocommerce' ), $callbacks );

		if ( null !== $interval && $interval < 30 ) {
			return Result::warning(
				/* translators: %d: seconds. */
				sprintf( _n( 'The Heartbeat is set to every %d second, which loads the server heavily.', 'The Heartbeat is set to every %d seconds, which loads the server heavily.', $interval, 'store-audit-for-woocommerce' ), $interval ),
				$details
			);
		}
		if ( $callbacks > 10 ) {
			return Result::info(
				/* translators: %d: number of callbacks. */
				sprintf( _n( '%d function runs on every Heartbeat ping.', '%d functions run on every Heartbeat ping.', $callbacks, 'store-audit-for-woocommerce' ), $callbacks ),
				$details
			);
		}
		return Result::ok( __( 'The Heartbeat settings look normal.', 'store-audit-for-woocommerce' ), $details );
	}
}
