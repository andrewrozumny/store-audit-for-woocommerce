<?php
/**
 * Admin page tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use StoreAudit\Admin\Page;
use StoreAudit\Tests\Unit\OptionStoreTrait;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for the data the page hands to admin.js.
 *
 * @covers \StoreAudit\Admin\Page
 */
final class PageTest extends TestCase {

	use OptionStoreTrait;

	/**
	 * Inline script captured from wp_add_inline_script.
	 *
	 * @var string
	 */
	private string $inline = '';

	/**
	 * Stub WordPress.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_option_store();
		Functions\when( 'add_submenu_page' )->justReturn( 'woocommerce_page_store-audit' );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'abc123' );
		Functions\when( 'wp_date' )->justReturn( '12 Oct 2026' );
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, int $options = 0 ) => json_encode( $data, $options ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( string $handle, string $code ): bool {
				unset( $handle );
				$this->inline = $code;
				return true;
			}
		);
	}

	/**
	 * Names from the site end up in the report JSON that is printed inside a script element. A closing
	 * script tag or a comment opener inside a string must not survive as raw characters.
	 */
	public function test_inline_config_cannot_close_the_script_element(): void {
		$this->options['store_audit_last_report'] = array(
			'generated_at' => '2026-10-12T08:30:00Z',
			'results'      => array(
				array(
					'id'       => 'a',
					'section'  => 'plugins',
					'title'    => 'Inactive plugins',
					'severity' => 'warning',
					'summary'  => '</script><script>alert(1)</script><!-- &amp; \'q\'',
					'details'  => array( '"onmouseover="alert(1)' ),
				),
			),
		);
		$page                                     = new Page();
		$page->add_menu();
		$page->enqueue( 'woocommerce_page_store-audit' );

		$this->assertNotSame( '', $this->inline, 'the config was not printed' );
		$this->assertStringNotContainsString( '</script', strtolower( $this->inline ) );
		$this->assertStringNotContainsString( '<script', strtolower( $this->inline ) );
		$this->assertStringNotContainsString( '<!--', $this->inline );
		$json = preg_replace( '/^window\.storeAuditConfig = (.*);$/s', '$1', $this->inline );
		$this->assertIsString( $json );
		$config = json_decode( $json, true );
		$this->assertIsArray( $config, 'the printed config is not valid JSON any more' );
		$this->assertIsArray( $config['lastReport'] );
		$this->assertSame( '</script><script>alert(1)</script><!-- &amp; \'q\'', $config['lastReport']['results'][0]['summary'] );
	}

	/**
	 * The script sorts by the severity order PHP defines, so the order is not written down twice.
	 */
	public function test_config_carries_the_severity_order_from_php(): void {
		$page = new Page();
		$page->add_menu();
		$page->enqueue( 'woocommerce_page_store-audit' );

		$json = preg_replace( '/^window\.storeAuditConfig = (.*);$/s', '$1', $this->inline );
		$this->assertIsString( $json );
		$config = json_decode( $json, true );
		$this->assertIsArray( $config );
		$this->assertSame( array( 'critical', 'warning', 'error', 'info', 'ok' ), $config['severities'] );
		$this->assertSame( \StoreAudit\ReportView::severity_order(), $config['severities'] );
	}

	/**
	 * The menu item stays under WooCommerce but needs manage_options.
	 */
	public function test_menu_needs_manage_options(): void {
		$args = array();
		Functions\when( 'add_submenu_page' )->alias(
			static function ( ...$call ) use ( &$args ): string {
				$args = $call;
				return 'woocommerce_page_store-audit';
			}
		);

		( new Page() )->add_menu();

		$this->assertSame( 'woocommerce', $args[0] );
		$this->assertSame( 'manage_options', $args[3] );
	}

	/**
	 * A shop manager who opens the address by hand gets 403.
	 */
	public function test_page_refuses_shop_manager(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'manage_options' !== $cap );
		Functions\expect( 'wp_die' )->once()->with( \Mockery::type( 'string' ), '', array( 'response' => 403 ) )->andThrow( new \RuntimeException( 'died' ) );

		$this->expectException( \RuntimeException::class );
		( new Page() )->render();
	}
}
