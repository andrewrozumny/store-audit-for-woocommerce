<?php
/**
 * FileEditing tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Environment;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Environment\FileEditing;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for FileEditing.
 *
 * @covers \StoreAudit\Checks\Environment\FileEditing
 */
final class FileEditingTest extends TestCase {

	/**
	 * Stubs for helpers the check uses.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ) {
				return round( (float) $bytes / 1048576, 1 ) . ' MB';
			}
		);
		Functions\when( 'human_time_diff' )->alias(
			static function ( $from, $to ) {
				return (string) ( (int) $to - (int) $from ) . ' seconds';
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	/**
	 * Run the check on prepared data.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	private function run_with( array $data ): \StoreAudit\Result {
		return ( new FileEditing( $data ) )->run();
	}

	/**
	 * Editor available is warning.
	 */
	public function test_editor_available_is_warning(): void {
		$result = $this->run_with(
			array(
				'file_edit' => false,
				'file_mods' => false,
			)
		);

		$this->assertSame( 'warning', $result->severity() );
	}

	/**
	 * Editor disabled is ok.
	 */
	public function test_editor_disabled_is_ok(): void {
		$result = $this->run_with(
			array(
				'file_edit' => true,
				'file_mods' => false,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * File mods disabled is ok.
	 */
	public function test_file_mods_disabled_is_ok(): void {
		$result = $this->run_with(
			array(
				'file_edit' => false,
				'file_mods' => true,
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new FileEditing();

		$this->assertSame( 'environment_file_editing', $check->id() );
		$this->assertSame( 'environment', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}
}
