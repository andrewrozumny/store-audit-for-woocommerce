<?php
/**
 * Check contract.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks;

use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single audit check. Checks must be read-only.
 */
interface CheckInterface {

	/**
	 * Unique snake_case id prefixed by section, e.g. 'environment_php_version'.
	 */
	public function id(): string;

	/**
	 * One of the Section::* constants.
	 */
	public function section(): string;

	/**
	 * Short translated title.
	 */
	public function title(): string;

	/**
	 * What this is and why it matters, in store-owner language (1-3 sentences).
	 */
	public function explanation(): string;

	/**
	 * One line: what to do.
	 */
	public function fix_hint(): string;

	/**
	 * Run the check. Must not change anything on the site.
	 */
	public function run(): Result;
}
