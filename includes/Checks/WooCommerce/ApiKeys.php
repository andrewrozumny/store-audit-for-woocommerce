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
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

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

		foreach ( Arr::to_array( $keys ) as $raw_key ) {
			$key         = Arr::to_string_keyed( $raw_key );
			$permissions = Arr::get_string( $key, 'permissions' );
			if ( ! in_array( $permissions, array( 'write', 'read_write' ), true ) ) {
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
}
