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
	 * @param string               $foreign   Host of the address when it is served from another host, so it was not requested.
	 */
	public function __construct(
		private readonly bool $reachable,
		private readonly int $status = 0,
		private readonly array $headers = array(),
		private readonly string $body = '',
		private readonly string $error = '',
		private readonly string $foreign = ''
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
	 * A probe that was not sent because the address is served from another host (for example a CDN).
	 *
	 * @param string $host Host of the address.
	 */
	public static function foreign_host( string $host ): self {
		return new self(
			false,
			0,
			array(),
			'',
			/* translators: %s: host name. */
			sprintf( __( 'Not requested: the address is on another host (%s).', 'store-audit-for-woocommerce' ), $host ),
			$host
		);
	}

	/**
	 * Host of the address when it is served from another host and was not requested, otherwise an empty string.
	 */
	public function foreign(): string {
		return $this->foreign;
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
