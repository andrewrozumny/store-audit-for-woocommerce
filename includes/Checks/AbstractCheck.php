<?php
/**
 * Base class with helpers for concrete checks.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers shared by checks. Concrete checks implement the rest of CheckInterface.
 */
abstract class AbstractCheck implements CheckInterface {

	/**
	 * Whether the details of this check name accounts, files or keys that must not leave the site in an export
	 * (printed report, Markdown, JSON). The full details stay on the Store Audit screen and in WP-CLI.
	 *
	 * @return bool True when the details are sensitive. Default false.
	 */
	public function is_sensitive(): bool {
		return false;
	}

	/**
	 * Human readable byte size.
	 *
	 * @param int $bytes Bytes.
	 * @return string Size such as "1.5 MB".
	 */
	protected function format_bytes( int $bytes ): string {
		$formatted = size_format( $bytes, 1 );
		return false === $formatted ? '0 B' : $formatted;
	}

	/**
	 * Whether the site declares itself a production environment.
	 *
	 * @return bool True when wp_get_environment_type() is "production".
	 */
	protected function is_production(): bool {
		return 'production' === wp_get_environment_type();
	}
}
