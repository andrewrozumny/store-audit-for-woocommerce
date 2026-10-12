<?php
/**
 * Terms and conditions page check.
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The checkout should link to published terms and conditions.
 */
final class TermsPage extends AbstractCheck {

	/**
	 * Returns array{id:int,status:string}.
	 *
	 * @var callable
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? static function (): array {
			$id   = function_exists( 'wc_terms_and_conditions_page_id' ) ? wc_terms_and_conditions_page_id() : 0;
			$post = $id > 0 ? get_post( $id ) : null;
			return array(
				'id'     => $id,
				'status' => $post instanceof \WP_Post ? $post->post_status : '',
			);
		};
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'woocommerce_terms_page';
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
		return __( 'Terms and conditions page', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Customers should agree to clear terms before they pay. Without them you have little to rely on in refund disputes and chargebacks, and some countries require them by law.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Write or publish a terms page, then choose it in WooCommerce, Settings, Advanced, Page setup.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$page   = Arr::to_array( ( $this->source )() );
		$status = Arr::get_string( $page, 'status' );

		if ( Arr::get_int( $page, 'id' ) <= 0 ) {
			return Result::warning( __( 'No terms and conditions page is selected for the checkout.', 'store-audit-for-woocommerce' ) );
		}

		if ( 'publish' !== $status ) {
			return Result::warning(
				'' === $status
					? __( 'The selected terms and conditions page does not exist any more.', 'store-audit-for-woocommerce' )
					: sprintf(
						/* translators: %s: post status such as draft or trash. */
						__( 'The terms and conditions page is not published (status: %s).', 'store-audit-for-woocommerce' ),
						$status
					)
			);
		}

		return Result::ok( __( 'A published terms and conditions page is set.', 'store-audit-for-woocommerce' ) );
	}
}
