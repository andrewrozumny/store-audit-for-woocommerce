<?php
/**
 * NegativeStock tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\WooCommerce;

use Brain\Monkey\Functions;
use StoreAudit\Checks\WooCommerce\NegativeStock;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for NegativeStock.
 *
 * @covers \StoreAudit\Checks\WooCommerce\NegativeStock
 */
final class NegativeStockTest extends TestCase {

	/**
	 * No negative stock.
	 */
	public function test_none_is_ok(): void {
		$check = new NegativeStock(
			static fn(): array => array(
				'managing' => true,
				'count'    => 0,
				'names'    => array(),
			)
		);

		$this->assertSame( 'ok', $check->run()->severity() );
	}

	/**
	 * Some negative.
	 */
	public function test_negative_warns_with_names(): void {
		$check = new NegativeStock(
			static fn(): array => array(
				'managing' => true,
				'count'    => 25,
				'names'    => array( 'Mug', 'Cap' ),
			)
		);

		$result = $check->run();

		$this->assertSame( 'warning', $result->severity() );
		$this->assertSame( array( 'Mug', 'Cap', 'and 23 more' ), $result->details() );
	}

	/**
	 * Stock management off.
	 */
	public function test_stock_management_off_is_info(): void {
		$check = new NegativeStock(
			static fn(): array => array(
				'managing' => false,
				'count'    => 0,
				'names'    => array(),
			)
		);

		$this->assertSame( 'info', $check->run()->severity() );
	}

	/**
	 * Negative stock is normal for products that take backorders, so the live queries leave those out,
	 * on the lookup table and on the postmeta fallback, for the product itself and for variations that inherit.
	 *
	 * @dataProvider lookup_tables
	 *
	 * @param bool $has_lookup Whether the product lookup table exists.
	 */
	public function test_live_queries_skip_products_with_backorders( bool $has_lookup ): void {
		$queries      = array();
		$db           = \Mockery::mock( 'wpdb' );
		$db->prefix   = 'wp_';
		$db->postmeta = 'wp_postmeta';
		$db->posts    = 'wp_posts';
		$db->shouldReceive( 'esc_like' )->andReturnUsing( static fn( string $text ): string => $text );
		$db->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, ...$args ): string {
				$flat = array();
				array_walk_recursive(
					$args,
					static function ( $value ) use ( &$flat ): void {
						$flat[] = (string) $value;
					}
				);
				return $query . ' /* ' . implode( ',', $flat ) . ' */';
			}
		);
		$db->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( string $query ) use ( &$queries, $has_lookup ) {
				$queries[] = $query;
				return str_contains( $query, 'SHOW TABLES' ) ? ( $has_lookup ? 'wp_wc_product_meta_lookup' : null ) : 0;
			}
		);
		$db->shouldReceive( 'get_col' )->andReturnUsing(
			static function ( string $query ) use ( &$queries ): array {
				$queries[] = $query;
				return array();
			}
		);
		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.
		Functions\when( 'get_option' )->justReturn( 'yes' );

		( new NegativeStock() )->run();
		unset( $GLOBALS['wpdb'] );

		$data = array_values( array_filter( $queries, static fn( string $query ): bool => ! str_contains( $query, 'SHOW TABLES' ) ) );
		$this->assertCount( 2, $data );
		foreach ( $data as $query ) {
			$this->assertStringContainsString( "'_backorders'", $query );
			$this->assertStringContainsString( "IN ('yes','notify')", $query );
			$this->assertStringContainsString( "'parent'", $query );
		}
	}

	/**
	 * Both storage layouts.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function lookup_tables(): array {
		return array(
			'lookup table' => array( true ),
			'postmeta'     => array( false ),
		);
	}
}
