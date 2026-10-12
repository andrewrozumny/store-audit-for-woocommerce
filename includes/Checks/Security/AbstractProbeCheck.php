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
	 * Result used when a probe got no answer. An address on another host (uploads on a CDN, REST on its own host)
	 * is simply not applicable; a site that cannot reach itself is "not checked", never a pass.
	 *
	 * @param ProbeResponse $response The failed probe.
	 */
	protected function unreachable( ProbeResponse $response ): Result {
		if ( '' !== $response->foreign() ) {
			return Result::info(
				sprintf(
					/* translators: %s: host name. */
					__( 'Not applicable: this address is served from another host (%s), so it cannot be checked from this site.', 'store-audit-for-woocommerce' ),
					$response->foreign()
				)
			);
		}
		return Result::error(
			__( 'Not checked: the site could not reach itself, so this check was skipped. A firewall, a security plugin or the host may block requests from the site to its own address.', 'store-audit-for-woocommerce' ),
			array( $response->error() )
		);
	}
}
