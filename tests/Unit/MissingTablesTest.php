<?php
/**
 * Checks that read WooCommerce tables must cope with a table that does not exist.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Functions;
use StoreAudit\Checks\CheckInterface;
use StoreAudit\Checks\Environment\ObjectCache;
use StoreAudit\Checks\Performance\OrdersStorage;
use StoreAudit\Checks\WooCommerce\ShippingZones;
use StoreAudit\Checks\WooCommerce\Taxes;
use StoreAudit\Result;
use StoreAudit\Runner;

require_once __DIR__ . '/OrderUtilStub.php';

/**
 * A query on a missing table makes WordPress write "WordPress database error" to the log and returns
 * nothing, which the check would then read as "0". The checks look for the table first.
 *
 * @covers \StoreAudit\Support\Db
 */
final class MissingTablesTest extends TestCase {

	/**
	 * Queries sent to the fake database.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * A database where no WooCommerce table exists.
	 */
	protected function set_up(): void {
		parent::set_up();
		$db         = \Mockery::mock( 'wpdb' );
		$db->prefix = 'wp_';
		$db->posts  = 'wp_posts';
		$db->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $text ): string => $text );
		$db->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, ...$args ): string {
				return $query . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}
		);
		$db->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $query ) {
				$this->queries[] = $query;
				return null;
			}
		);
		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.
		Functions\when( 'WC' )->justReturn( new \stdClass() );
		Functions\when( 'wc_tax_enabled' )->justReturn( true );
		Functions\when( 'wp_count_posts' )->justReturn( (object) array( 'publish' => 3 ) );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
	}

	/**
	 * Remove the fake database.
	 */
	protected function tear_down(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tear_down();
	}

	/**
	 * Checks that read one table directly, with the table they need.
	 *
	 * @return array<string,array{0:CheckInterface,1:string}>
	 */
	public static function checks(): array {
		return array(
			'order storage'  => array( new OrdersStorage(), 'wp_wc_orders' ),
			'object cache'   => array( new ObjectCache(), 'wp_wc_orders' ),
			'taxes'          => array( new Taxes(), 'wp_woocommerce_tax_rates' ),
			'shipping zones' => array( new ShippingZones(), 'wp_woocommerce_shipping_zones' ),
		);
	}

	/**
	 * The check ends as "not checked" with the table named, and never sends a query to the missing table.
	 *
	 * @dataProvider checks
	 *
	 * @param CheckInterface $check Check.
	 * @param string         $table Table it needs.
	 */
	public function test_missing_table_means_not_checked( CheckInterface $check, string $table ): void {
		$row = ( new Runner() )->run_one( $check );

		$this->assertSame( Result::ERROR, $row['severity'] );
		$this->assertStringContainsString( $table, $row['summary'] );
		foreach ( $this->queries as $query ) {
			$this->assertStringNotContainsString( 'COUNT(*)', $query, 'a count was sent to a table that does not exist' );
		}
	}
}
