<?php
/**
 * Base class for checks that probe the site over HTTP.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that look at the site from the outside through SelfProbe.
 */
abstract class AbstractProbeCheck extends AbstractSecurityCheck {

	/**
	 * Probe.
	 *
	 * @var SelfProbe
	 */
	protected SelfProbe $probe;

	/**
	 * Constructor.
	 *
	 * @param SelfProbe|null $probe Probe override for tests.
	 */
	public function __construct( ?SelfProbe $probe = null ) {
		$this->probe = $probe ?? new SelfProbe();
	}

	/**
	 * Result used when the site cannot call itself.
	 *
	 * @param ProbeResponse $response The failed probe.
	 */
	protected function unreachable( ProbeResponse $response ): Result {
		return Result::info(
			__( 'Could not reach the site from itself (loopback blocked), so this check was skipped.', 'store-audit-for-woocommerce' ),
			array( $response->error() )
		);
	}
}
