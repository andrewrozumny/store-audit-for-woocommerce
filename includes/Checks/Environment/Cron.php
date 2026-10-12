<?php
/**
 * Scheduled tasks (cron) check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Environment;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled tasks (cron).
 */
final class Cron extends AbstractCheck {

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
		return 'environment_cron';
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
		return __( 'Scheduled tasks (cron)', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'WooCommerce relies on scheduled tasks for emails, subscription renewals, stock holds and clean-up. If they stop running, customers miss emails and orders get stuck.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Make sure a real server cron job calls wp-cron.php every few minutes, or ask your host why scheduled tasks are late.', 'store-audit-for-woocommerce' );
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
		global $wpdb;
		$now    = time();
		$hooks  = array();
		$count  = 0;
		$oldest = 0;
		$cron   = _get_cron_array();
		foreach ( is_array( $cron ) ? $cron : array() as $timestamp => $events ) {
			if ( ! is_numeric( $timestamp ) || (int) $timestamp >= $now - 3600 || ! is_array( $events ) ) {
				continue;
			}
			$age = $now - (int) $timestamp;
			foreach ( $events as $hook => $instances ) {
				$count                  += is_array( $instances ) ? max( 1, count( $instances ) ) : 1;
				$hooks[ (string) $hook ] = max( $hooks[ (string) $hook ] ?? 0, $age );
			}
			$oldest = max( $oldest, $age );
		}
		arsort( $hooks );

		// Action Scheduler (WooCommerce background jobs). The table may not exist.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = null;
		$table   = $wpdb->prefix . 'actionscheduler_actions';
		$found   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'actionscheduler_actions' ) ) );
		if ( $table === $found ) {
			// Indexed by status and scheduled date, counted only.
			$pending = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending' AND scheduled_date_gmt < %s", gmdate( 'Y-m-d H:i:s', $now - 3600 ) )
			);
		}
		// phpcs:enable

		return array(
			'disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'count'      => $count,
			'oldest'     => $oldest,
			'hooks'      => $hooks,
			'as_pending' => $pending,
			'local'      => 'local' === wp_get_environment_type(),
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$count   = (int) ( $d['count'] ?? 0 );
		$oldest  = (int) ( $d['oldest'] ?? 0 );
		$hooks   = is_array( $d['hooks'] ?? null ) ? array_keys( $d['hooks'] ) : array();
		$as      = isset( $d['as_pending'] ) ? (int) $d['as_pending'] : null;
		$parts   = array();
		$details = array();
		$level   = 'ok';

		if ( $count >= 1 ) {
			$level   = $oldest > 86400 ? 'critical' : 'warning';
			$parts[] = sprintf(
				/* translators: 1: number of tasks, 2: age, e.g. "3 hours". */
				__( '%1$d scheduled tasks are more than an hour late, the oldest by %2$s.', 'store-audit-for-woocommerce' ),
				$count,
				human_time_diff( 0, $oldest )
			);
			foreach ( array_slice( $hooks, 0, 10 ) as $hook ) {
				$details[] = (string) $hook;
			}
			if ( count( $hooks ) > 10 ) {
				/* translators: %d: number of more items. */
				$details[] = sprintf( __( 'and %d more', 'store-audit-for-woocommerce' ), count( $hooks ) - 10 );
			}
		}

		if ( null !== $as && $as > 100 ) {
			$level = 'ok' === $level ? 'warning' : $level;
			/* translators: %d: number of actions. */
			$parts[] = sprintf( __( '%d WooCommerce background actions are waiting for more than an hour.', 'store-audit-for-woocommerce' ), $as );
		}

		if ( ! empty( $d['disabled'] ) ) {
			$details[] = __( 'WP-Cron is switched off (DISABLE_WP_CRON). That is fine only if a real server cron job runs wp-cron.php regularly.', 'store-audit-for-woocommerce' );
		}
		if ( ! empty( $d['local'] ) && 'ok' !== $level ) {
			$details[] = __( 'This site is a local environment, so late tasks are expected when nobody visits it.', 'store-audit-for-woocommerce' );
		}

		if ( 'ok' === $level ) {
			if ( ! empty( $d['disabled'] ) ) {
				return Result::info( __( 'WP-Cron is switched off and no task is late. Make sure a real server cron job is set up.', 'store-audit-for-woocommerce' ), $details );
			}
			return Result::ok( __( 'Scheduled tasks are running on time.', 'store-audit-for-woocommerce' ), $details );
		}

		$summary = implode( ' ', $parts );
		if ( 'critical' === $level ) {
			return Result::critical( $summary . ' ' . __( 'Emails, subscriptions and other WooCommerce tasks have likely stopped.', 'store-audit-for-woocommerce' ), $details );
		}
		return Result::warning( $summary, $details );
	}
}
