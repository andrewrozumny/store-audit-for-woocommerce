<?php
/**
 * Exception whose message is safe to show in the report.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by a check that cannot run for a known reason. The message is a translated sentence without paths or
 * server details, so the runner may print it in the report. Every other exception is reported in general terms.
 */
final class NotCheckedException extends \RuntimeException {
}
