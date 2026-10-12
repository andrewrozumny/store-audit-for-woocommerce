<?php
/**
 * Fake check whose details are sensitive.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Result;

/**
 * A check that reports account names in its details.
 */
final class SensitiveFakeCheck extends AbstractCheck {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'security_sensitive_fake';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return 'security';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return 'Sensitive fake';
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return 'Because.';
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return 'Fix it.';
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		return Result::warning( 'Two accounts', array( 'jdoe', 'admin' ) );
	}

	/**
	 * Sensitive.
	 */
	public function is_sensitive(): bool {
		return true;
	}
}
