<?php
/**
 * Check result value object.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable result of a single check. Build one with a named constructor: critical(), warning(), info(), ok() or error().
 */
final class Result {

	// Severities. ERROR means "could not be checked" and is not a finding about the store.
	public const CRITICAL = 'critical';
	public const WARNING  = 'warning';
	public const INFO     = 'info';
	public const OK       = 'ok';
	public const ERROR    = 'error';

	/**
	 * Constructor.
	 *
	 * @param string   $severity  One of the severity constants.
	 * @param string   $summary   Concrete finding.
	 * @param string[] $details   Extra lines, e.g. plugin names.
	 * @param string   $fix_hint  Optional override of the check's fix hint.
	 */
	private function __construct(
		private readonly string $severity,
		private readonly string $summary,
		private readonly array $details = array(),
		private readonly string $fix_hint = ''
	) {
	}

	/**
	 * All valid severities.
	 *
	 * @return list<string> The severity constants.
	 */
	public static function severities(): array {
		return array( self::CRITICAL, self::WARNING, self::INFO, self::OK, self::ERROR );
	}

	/**
	 * Critical finding.
	 *
	 * @param string   $summary  Summary.
	 * @param string[] $details  Details.
	 * @param string   $fix_hint Fix hint override.
	 * @return self The critical finding.
	 */
	public static function critical( string $summary, array $details = array(), string $fix_hint = '' ): self {
		return new self( self::CRITICAL, $summary, $details, $fix_hint );
	}

	/**
	 * Warning finding.
	 *
	 * @param string   $summary  Summary.
	 * @param string[] $details  Details.
	 * @param string   $fix_hint Fix hint override.
	 * @return self The warning finding.
	 */
	public static function warning( string $summary, array $details = array(), string $fix_hint = '' ): self {
		return new self( self::WARNING, $summary, $details, $fix_hint );
	}

	/**
	 * Informational finding.
	 *
	 * @param string   $summary  Summary.
	 * @param string[] $details  Details.
	 * @param string   $fix_hint Fix hint override.
	 * @return self The informational finding.
	 */
	public static function info( string $summary, array $details = array(), string $fix_hint = '' ): self {
		return new self( self::INFO, $summary, $details, $fix_hint );
	}

	/**
	 * Everything is fine.
	 *
	 * @param string   $summary Summary.
	 * @param string[] $details Details.
	 * @return self The "all fine" result.
	 */
	public static function ok( string $summary, array $details = array() ): self {
		return new self( self::OK, $summary, $details );
	}

	/**
	 * The check could not be performed.
	 *
	 * @param string   $summary Summary.
	 * @param string[] $details Details.
	 * @return self The "could not be checked" result.
	 */
	public static function error( string $summary, array $details = array() ): self {
		return new self( self::ERROR, $summary, $details );
	}

	/**
	 * Severity.
	 *
	 * @return string One of the severity constants.
	 */
	public function severity(): string {
		return $this->severity;
	}

	/**
	 * Summary.
	 *
	 * @return string The concrete finding, in store-owner language.
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * Details.
	 *
	 * @return list<string> Extra lines, such as plugin names.
	 */
	public function details(): array {
		return array_values( $this->details );
	}

	/**
	 * Fix hint override (empty when none).
	 *
	 * @return string The override, or an empty string when the check's own hint applies.
	 */
	public function fix_hint(): string {
		return $this->fix_hint;
	}

	/**
	 * Array form.
	 *
	 * @return array{severity:string,summary:string,details:list<string>,fix_hint:string}
	 */
	public function to_array(): array {
		return array(
			'severity' => $this->severity,
			'summary'  => $this->summary,
			'details'  => array_values( $this->details ),
			'fix_hint' => $this->fix_hint,
		);
	}
}
