<?php
/**
 * Stand-in for the end of a JSON response.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

/**
 * Thrown by the stubbed wp_send_json_* functions.
 */
final class JsonResponse extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param bool  $success Whether it was a success response.
	 * @param mixed $payload Response data.
	 * @param int   $status  HTTP status.
	 */
	public function __construct( public readonly bool $success, public readonly mixed $payload, public readonly int $status ) {
		parent::__construct( 'json' );
	}
}
