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
	 * Human readable byte size.
	 *
	 * @param int $bytes Bytes.
	 */
	protected function format_bytes( int $bytes ): string {
		$formatted = size_format( $bytes, 1 );
		return false === $formatted ? '0 B' : $formatted;
	}

	/**
	 * Whether the site declares itself a production environment.
	 */
	protected function is_production(): bool {
		return 'production' === wp_get_environment_type();
	}
}
