<?php
/**
 * Core store pages check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\WooCommerce;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart, checkout, My account and shop pages must exist and be published.
 */
final class Pages extends AbstractCheck {

	private const PAGES = array( 'cart', 'checkout', 'myaccount', 'shop' );

	/**
	 * Returns array<string,array{id:int,status:string}> keyed by page key; status is '' when the page does not exist.
	 *
	 * @var \Closure
	 */
	private \Closure $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = \Closure::fromCallable( $source ?? array( $this, 'read_pages' ) );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_pages';
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
		return __( 'Cart, checkout and account pages', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'WooCommerce needs a cart page, a checkout page, a My account page and a shop page. If the cart or checkout page is missing or not published, customers cannot buy anything.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'In WooCommerce, Status, Tools, use "Create default WooCommerce pages", or assign and publish the pages in Settings, Advanced.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$pages    = Arr::to_array( ( $this->source )() );
		$critical = false;
		$lines    = array();

		foreach ( self::PAGES as $key ) {
			$page   = Arr::get_map( $pages, $key );
			$label  = $this->label( $key );
			$status = Arr::get_string( $page, 'status' );

			if ( Arr::get_int( $page, 'id' ) <= 0 ) {
				$lines[] = sprintf(
					/* translators: %s: page name. */
					__( '%s: no page assigned', 'store-audit-for-woocommerce' ),
					$label
				);
			} elseif ( '' === $status ) {
				$lines[] = sprintf(
					/* translators: %s: page name. */
					__( '%s: the assigned page does not exist any more', 'store-audit-for-woocommerce' ),
					$label
				);
			} elseif ( 'publish' !== $status ) {
				$lines[] = sprintf(
					/* translators: 1: page name, 2: post status such as draft or trash. */
					__( '%1$s: page is not published (status: %2$s)', 'store-audit-for-woocommerce' ),
					$label,
					$status
				);
			} else {
				continue;
			}

			if ( 'cart' === $key || 'checkout' === $key ) {
				$critical = true;
			}
		}

		if ( array() === $lines ) {
			return Result::ok( __( 'The cart, checkout, My account and shop pages are all published.', 'store-audit-for-woocommerce' ) );
		}

		$summary = __( 'Some core store pages are missing or not published.', 'store-audit-for-woocommerce' );
		return $critical ? Result::critical( $summary, $lines ) : Result::warning( $summary, $lines );
	}

	/**
	 * Page label.
	 *
	 * @param string $key Page key.
	 */
	private function label( string $key ): string {
		switch ( $key ) {
			case 'cart':
				return __( 'Cart page', 'store-audit-for-woocommerce' );
			case 'checkout':
				return __( 'Checkout page', 'store-audit-for-woocommerce' );
			case 'myaccount':
				return __( 'My account page', 'store-audit-for-woocommerce' );
			default:
				return __( 'Shop page', 'store-audit-for-woocommerce' );
		}
	}

	/**
	 * Read pages from WooCommerce.
	 *
	 * @return array<string,array{id:int,status:string}>
	 */
	private function read_pages(): array {
		$out = array();
		foreach ( self::PAGES as $key ) {
			$id     = wc_get_page_id( $key );
			$status = '';
			if ( $id > 0 ) {
				$post   = get_post( $id );
				$status = $post instanceof \WP_Post ? $post->post_status : '';
			}
			$out[ $key ] = array(
				'id'     => $id,
				'status' => $status,
			);
		}
		return $out;
	}
}
