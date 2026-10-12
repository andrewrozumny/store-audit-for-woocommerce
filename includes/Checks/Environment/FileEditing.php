<?php
/**
 * Theme and plugin file editor check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Environment;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme and plugin file editor.
 */
final class FileEditing extends AbstractCheck {

	/**
	 * Data to evaluate (tests) or null to read the live site.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $data Pre-collected data for tests; null reads the live site.
	 */
	public function __construct( ?array $data = null ) {
		$this->data = $data;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'environment_file_editing';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::ENVIRONMENT;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Theme and plugin file editor', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'WordPress has a built-in editor that lets anyone with an admin login change theme and plugin code. If an admin account is stolen, the attacker can put malicious code on your store in seconds.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php (or ask your developer or host).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		return $this->evaluate( $this->data ?? $this->collect() );
	}

	/**
	 * Read the live site.
	 *
	 * @return array<string,mixed>
	 */
	private function collect(): array {
		return array(
			'file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'file_mods' => ! wp_is_file_mod_allowed( 'capability_update_core' ),
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		if ( ! empty( $d['file_edit'] ) ) {
			return Result::ok( __( 'The built-in theme and plugin editor is switched off.', 'store-audit-for-woocommerce' ) );
		}
		if ( ! empty( $d['file_mods'] ) ) {
			return Result::ok( __( 'File changes from the dashboard are switched off (DISALLOW_FILE_MODS), which also disables the editor.', 'store-audit-for-woocommerce' ) );
		}
		return Result::warning( __( 'The built-in theme and plugin editor is available to every administrator.', 'store-audit-for-woocommerce' ) );
	}
}
