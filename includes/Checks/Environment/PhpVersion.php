<?php
/**
 * PHP version check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Environment;

use DateTimeImmutable;
use DateTimeZone;
use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags PHP versions that no longer get security fixes.
 */
final class PhpVersion extends AbstractCheck {

	/**
	 * End of security support per PHP branch.
	 */
	private const EOL_DATES = array(
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
		'8.5' => '2029-12-31',
	);

	/**
	 * Running PHP version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Current date.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $today;

	/**
	 * Constructor.
	 *
	 * @param string|null            $version PHP version to evaluate (defaults to the running one).
	 * @param DateTimeImmutable|null $today   "Today" (defaults to now, UTC).
	 */
	public function __construct( ?string $version = null, ?DateTimeImmutable $today = null ) {
		$this->version = $version ?? PHP_VERSION;
		$this->today   = $today ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'environment_php_version';
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
		return __( 'PHP version', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'PHP is the engine your store runs on. Versions that no longer receive security fixes are an open door for attackers, and newer versions are also faster.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Ask your hosting provider to switch the site to a supported PHP version (test on a staging copy first).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		if ( ! preg_match( '/^(\d+)\.(\d+)/', $this->version, $m ) ) {
			return Result::error(
				/* translators: %s: PHP version string. */
				sprintf( __( 'Could not read the PHP version "%s".', 'store-audit-for-woocommerce' ), $this->version )
			);
		}
		$branch = $m[1] . '.' . $m[2];

		if ( version_compare( $branch, '8.0', '<' ) ) {
			return Result::critical(
				/* translators: %s: PHP branch, e.g. 7.4. */
				sprintf( __( 'PHP %s reached end of life years ago and no longer gets security fixes.', 'store-audit-for-woocommerce' ), $branch )
			);
		}

		if ( ! isset( self::EOL_DATES[ $branch ] ) ) {
			// A branch newer than our table: assume it is supported.
			return Result::ok(
				/* translators: %s: PHP version. */
				sprintf( __( 'PHP %s is a current version.', 'store-audit-for-woocommerce' ), $branch )
			);
		}

		$eol      = new DateTimeImmutable( self::EOL_DATES[ $branch ], new DateTimeZone( 'UTC' ) );
		$eol_text = $eol->format( 'j M Y' );
		$today    = $this->today->format( 'Y-m-d' );

		if ( $today > $eol->format( 'Y-m-d' ) ) {
			return Result::critical(
				/* translators: 1: PHP branch, 2: date. */
				sprintf( __( 'PHP %1$s reached end of life on %2$s', 'store-audit-for-woocommerce' ), $branch, $eol_text )
			);
		}

		// Roughly six months before the end of support.
		if ( $today >= $eol->modify( '-183 days' )->format( 'Y-m-d' ) ) {
			return Result::warning(
				/* translators: 1: PHP branch, 2: date. */
				sprintf( __( 'PHP %1$s stops getting security fixes on %2$s', 'store-audit-for-woocommerce' ), $branch, $eol_text )
			);
		}

		return Result::ok(
			/* translators: 1: PHP branch, 2: date. */
			sprintf( __( 'PHP %1$s is supported until %2$s', 'store-audit-for-woocommerce' ), $branch, $eol_text )
		);
	}
}
