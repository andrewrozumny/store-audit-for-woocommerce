<?php
/**
 * Base unit test case.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use Brain\Monkey\Functions;
use Yoast\WPTestUtils\BrainMonkey\TestCase as BrainMonkeyTestCase;

/**
 * Brain Monkey base with the i18n/escaping stubs every test needs.
 */
abstract class TestCase extends BrainMonkeyTestCase {

	/**
	 * Stub the WordPress helpers the plugin uses everywhere.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}
}
