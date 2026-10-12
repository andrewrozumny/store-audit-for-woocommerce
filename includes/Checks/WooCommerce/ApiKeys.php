<?php
/**
 * REST API keys check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Plugins\DetailList;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write-capable API keys that nobody uses are a risk. Key values are never read or shown.
 */
final class ApiKeys extends AbstractCheck {

	/**
	 * Returns list<array{description:string,permissions:string,last_access:?string,user:string}>.
	 *
	 * @var callable
	 */
	private $source;

	/**
	 * Current unix time.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 * @param int|null      $now    Current unix time override for tests.
	 */
	public function __construct( ?callable $source = null, ?int $now = null ) {
		$this->source = $source ?? array( $this, 'read_keys' );
		$this->now    = $now ?? time();
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_api_keys';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::WOOCOMMERCE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'REST API keys', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'An API key with write access lets software change your products and orders. A key that was never used, or not for a year, is probably forgotten, and if it leaks someone can alter your store.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'In WooCommerce, Settings, Advanced, REST API, revoke keys you no longer use.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$keys   = ( $this->source )();
		$cutoff = strtotime( '-12 months', $this->now );
		$lines  = array();

		foreach ( $keys as $key ) {
			if ( ! in_array( $key['permissions'], array( 'write', 'read_write' ), true ) ) {
				continue;
			}
			$access = null === $key['last_access'] || '' === $key['last_access'] || 0 === strpos( $key['last_access'], '0000' )
				? false
				: strtotime( $key['last_access'] . ' UTC' );

			if ( false === $access ) {
				$last = __( 'never used', 'store-audit-for-woocommerce' );
			} elseif ( $access < $cutoff ) {
				$last = sprintf(
					/* translators: %s: date. */
					__( 'last used %s', 'store-audit-for-woocommerce' ),
					gmdate( 'j M Y', $access )
				);
			} else {
				continue;
			}

			$lines[] = sprintf(
				/* translators: 1: key description, 2: permission, 3: user, 4: last access. */
				__( '%1$s (%2$s, user %3$s): %4$s', 'store-audit-for-woocommerce' ),
				'' !== $key['description'] ? $key['description'] : __( '(no description)', 'store-audit-for-woocommerce' ),
				$key['permissions'],
				$key['user'],
				$last
			);
		}

		if ( array() === $lines ) {
			return Result::ok( __( 'No unused or long-idle API keys with write access.', 'store-audit-for-woocommerce' ) );
		}

		return Result::warning(
			sprintf(
				/* translators: %d: number of keys. */
				_n( '%d API key with write access is unused or idle for over a year.', '%d API keys with write access are unused or idle for over a year.', count( $lines ), 'store-audit-for-woocommerce' ),
				count( $lines )
			),
			DetailList::cap( $lines )
		);
	}

	/**
	 * Read keys. Only description, user, permissions and last access are selected: never key values.
	 *
	 * @return list<array{description:string,permissions:string,last_access:?string,user:string}>
	 */
	private function read_keys(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_api_keys';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_results( $wpdb->prepare( 'SELECT description, user_id, permissions, last_access FROM %i ORDER BY key_id LIMIT 200', $table ), ARRAY_A );
		$out   = array();
		foreach ( (array) $found as $row ) {
			$user  = get_userdata( (int) $row['user_id'] );
			$out[] = array(
				'description' => (string) $row['description'],
				'permissions' => (string) $row['permissions'],
				'last_access' => null === $row['last_access'] ? null : (string) $row['last_access'],
				'user'        => $user instanceof \WP_User ? $user->user_login : '#' . (int) $row['user_id'],
			);
		}
		return $out;
	}
}
