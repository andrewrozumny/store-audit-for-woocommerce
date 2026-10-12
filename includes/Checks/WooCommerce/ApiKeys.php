<?php
/**
 * REST API keys check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Support\DetailList;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API keys that nobody uses are a risk, and a key with write access most of all. Key values are never read or shown.
 */
final class ApiKeys extends AbstractCheck {

	/**
	 * Returns list<array{description:string,permissions:string,last_access:?string,user:string}>.
	 *
	 * @var \Closure
	 */
	private \Closure $source;

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
		$this->source = \Closure::fromCallable( $source ?? array( $this, 'read_keys' ) );
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
		return __( 'An API key lets software reach your store. A key with write access can change your products and orders, and even a read key hands out orders and customer data. A key that was never used, or not for a year, is probably forgotten, and if it leaks someone else gets that access.', 'store-audit-for-woocommerce' );
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
		$reads  = 0;

		foreach ( Arr::to_array( $keys ) as $raw_key ) {
			$key         = Arr::to_string_keyed( $raw_key );
			$permissions = Arr::get_string( $key, 'permissions' );
			if ( ! in_array( $permissions, array( 'read', 'write', 'read_write' ), true ) ) {
				continue;
			}
			$last_access = $key['last_access'] ?? null;
			$access      = ! is_string( $last_access ) || '' === $last_access || str_starts_with( $last_access, '0000' )
				? false
				: strtotime( $last_access . ' UTC' );
			$description = Arr::get_string( $key, 'description' );

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

			if ( 'read' === $permissions ) {
				++$reads;
			}
			$lines[] = sprintf(
				/* translators: 1: key description, 2: permission, 3: user, 4: last access. */
				__( '%1$s (%2$s, user %3$s): %4$s', 'store-audit-for-woocommerce' ),
				'' !== $description ? $description : __( '(no description)', 'store-audit-for-woocommerce' ),
				$permissions,
				Arr::get_string( $key, 'user' ),
				$last
			);
		}

		if ( array() === $lines ) {
			return Result::ok( __( 'No unused or long-idle API keys.', 'store-audit-for-woocommerce' ) );
		}

		$details = DetailList::cap( $lines );
		if ( $reads > 0 ) {
			$details[] = __( 'A read key can still read orders and customer data, so an unused one is worth removing too.', 'store-audit-for-woocommerce' );
		}
		return Result::warning(
			sprintf(
				/* translators: %d: number of keys. */
				_n( '%d API key is unused or idle for over a year.', '%d API keys are unused or idle for over a year.', count( $lines ), 'store-audit-for-woocommerce' ),
				count( $lines )
			),
			$details
		);
	}

	/**
	 * Read keys. Only description, user, permissions and last access are selected: never key values.
	 *
	 * @return list<array{description:string,permissions:string,last_access:?string,user:string}>
	 */
	private function read_keys(): array {
		$db    = Db::wpdb();
		$table = $db->prefix . 'woocommerce_api_keys';
		if ( ! Db::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only audit query on a small table; WooCommerce has no API for it.
		$found = $db->get_results( $db->prepare( 'SELECT description, user_id, permissions, last_access FROM %i ORDER BY key_id LIMIT 200', $table ), ARRAY_A );
		$out   = array();
		foreach ( Arr::to_array( $found ) as $row ) {
			$row         = Arr::to_string_keyed( $row );
			$user_id     = Arr::get_int( $row, 'user_id' );
			$user        = get_userdata( $user_id );
			$last_access = $row['last_access'] ?? null;
			$out[]       = array(
				'description' => Arr::get_string( $row, 'description' ),
				'permissions' => Arr::get_string( $row, 'permissions' ),
				'last_access' => is_string( $last_access ) ? $last_access : null,
				'user'        => $user instanceof \WP_User ? $user->user_login : '#' . $user_id,
			);
		}
		return $out;
	}

	/**
	 * Details name accounts, files or keys, so exports do not carry them.
	 */
	public function is_sensitive(): bool {
		return true;
	}
}
