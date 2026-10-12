<?php
/**
 * Result of a self probe.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outcome of one HTTP request the plugin made to its own site.
 */
final class ProbeResponse {

	/**
	 * Constructor.
	 *
	 * @param bool                 $reachable Whether an HTTP answer was received at all.
	 * @param int                  $status    HTTP status code (0 when unreachable).
	 * @param array<string,string> $headers   Response headers, lower-case names.
	 * @param string               $body      Body snippet (limited).
	 * @param string               $error     Failure reason when unreachable.
	 */
	public function __construct(
		private readonly bool $reachable,
		private readonly int $status = 0,
		private readonly array $headers = array(),
		private readonly string $body = '',
		private readonly string $error = ''
	) {
	}

	/**
	 * A failed probe.
	 *
	 * @param string $error Reason.
	 */
	public static function failure( string $error ): self {
		return new self( false, 0, array(), '', $error );
	}

	/**
	 * Whether the site answered.
	 */
	public function reachable(): bool {
		return $this->reachable;
	}

	/**
	 * HTTP status.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Headers (lower-case names).
	 *
	 * @return array<string,string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * Body snippet.
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Failure reason.
	 */
	public function error(): string {
		return $this->error;
	}
}
