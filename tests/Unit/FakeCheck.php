<?php
/**
 * Configurable check used by tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Result;

/**
 * A check whose behaviour is set by a closure.
 */
final class FakeCheck extends AbstractCheck {

	/**
	 * Constructor.
	 *
	 * @param string        $check_id  Id.
	 * @param string        $section   Section.
	 * @param callable|null $callback  Returns a Result; may throw.
	 */
	public function __construct(
		private readonly string $check_id = 'environment_fake',
		private readonly string $section = 'environment',
		private $callback = null
	) {
	}

	/**
	 * Id.
	 */
	public function id(): string {
		return $this->check_id;
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return $this->section;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return 'Fake';
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
		return null === $this->callback ? Result::ok( 'fine' ) : ( $this->callback )();
	}
}
