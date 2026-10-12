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
 * A single audit check. Checks must be read-only: they may read the database, options and files, but never write.
 * Implement this interface (usually by extending AbstractCheck) and add the object through the `store_audit_checks` filter.
 */
interface CheckInterface {

	/**
	 * Unique snake_case id prefixed by section, e.g. 'environment_php_version'.
	 *
	 * @return string Lower-case letters a-z, digits and underscores only; the registry rejects anything else.
	 */
	public function id(): string;

	/**
	 * One of the Section::* constants.
	 *
	 * @return string Id of the section the check belongs to.
	 */
	public function section(): string;

	/**
	 * Short translated title.
	 *
	 * @return string Title shown in the report.
	 */
	public function title(): string;

	/**
	 * What this is and why it matters, in store-owner language (1-3 sentences).
	 *
	 * @return string Translated explanation.
	 */
	public function explanation(): string;

	/**
	 * One line: what to do.
	 *
	 * @return string Translated hint. A Result may override it with its own.
	 */
	public function fix_hint(): string;

	/**
	 * Run the check. Must not change anything on the site.
	 *
	 * @return Result The finding. Do not throw to signal a problem with the site; return Result::error() when the check could not run.
	 */
	public function run(): Result;
}
