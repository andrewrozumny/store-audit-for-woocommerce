<?php
/**
 * Images tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Checks\Performance;

use Brain\Monkey\Functions;
use StoreAudit\Checks\Performance\Images;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Images.
 *
 * @covers \StoreAudit\Checks\Performance\Images
 */
final class ImagesTest extends TestCase {

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
		return ( new Images( $data ) )->run();
	}

	/**
	 * No images is info.
	 */
	public function test_no_images_is_info(): void {
		$result = $this->run_with( array( 'images' => array() ) );

		$this->assertSame( 'info', $result->severity() );
	}

	/**
	 * Modern with dimensions is ok.
	 */
	public function test_modern_with_dimensions_is_ok(): void {
		$result = $this->run_with(
			array(
				'images' => array(
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
				),
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Mostly old formats is info.
	 */
	public function test_mostly_old_formats_is_info(): void {
		$result = $this->run_with(
			array(
				'images' => array(
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 800,
							'height' => 600,
							'sizes'  => array(
								'thumbnail' => array(
									'file'      => 'a-150.jpg',
									'mime-type' => 'image/jpeg',
								),
							),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 800,
							'height' => 600,
							'sizes'  => array(
								'thumbnail' => array(
									'file'      => 'a-150.jpg',
									'mime-type' => 'image/jpeg',
								),
							),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 800,
							'height' => 600,
							'sizes'  => array(
								'thumbnail' => array(
									'file'      => 'a-150.jpg',
									'mime-type' => 'image/jpeg',
								),
							),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 800,
							'height' => 600,
							'sizes'  => array(
								'thumbnail' => array(
									'file'      => 'a-150.jpg',
									'mime-type' => 'image/jpeg',
								),
							),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 800,
							'height' => 600,
							'sizes'  => array(
								'thumbnail' => array(
									'file'      => 'a-150.jpg',
									'mime-type' => 'image/jpeg',
								),
							),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 800,
							'height' => 600,
							'sizes'  => array(
								'thumbnail' => array(
									'file'      => 'a-150.jpg',
									'mime-type' => 'image/jpeg',
								),
							),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 800,
							'height' => 600,
							'sizes'  => array(
								'thumbnail' => array(
									'file'      => 'a-150.jpg',
									'mime-type' => 'image/jpeg',
								),
							),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 800,
							'height' => 600,
							'sizes'  => array(
								'thumbnail' => array(
									'file'      => 'a-150.jpg',
									'mime-type' => 'image/jpeg',
								),
							),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
				),
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'mobile', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Missing dimensions is warning.
	 */
	public function test_missing_dimensions_is_warning(): void {
		$result = $this->run_with(
			array(
				'images' => array(
					array(
						'mime' => 'image/jpeg',
						'meta' => null,
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => null,
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => null,
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'   => 800,
							'height'  => 600,
							'sources' => array( 'image/webp' => array( 'file' => 'a.webp' ) ),
						),
					),
				),
			)
		);

		$this->assertSame( 'warning', $result->severity() );
		$this->assertStringContainsString( '3 have no saved dimensions', $result->summary() . implode( ' ', $result->details() ) );
	}

	/**
	 * Webp in a size counts.
	 */
	public function test_webp_in_a_size_counts(): void {
		$result = $this->run_with(
			array(
				'images' => array(
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 1,
							'height' => 1,
							'sizes'  => array( 't' => array( 'mime-type' => 'image/webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 1,
							'height' => 1,
							'sizes'  => array( 't' => array( 'mime-type' => 'image/webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 1,
							'height' => 1,
							'sizes'  => array( 't' => array( 'mime-type' => 'image/webp' ) ),
						),
					),
					array(
						'mime' => 'image/jpeg',
						'meta' => array(
							'width'  => 1,
							'height' => 1,
							'sizes'  => array( 't' => array( 'mime-type' => 'image/webp' ) ),
						),
					),
				),
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Webp main file counts.
	 */
	public function test_webp_main_file_counts(): void {
		$result = $this->run_with(
			array(
				'images' => array(
					array(
						'mime' => 'image/webp',
						'meta' => array(
							'width'  => 1,
							'height' => 1,
						),
					),
					array(
						'mime' => 'image/webp',
						'meta' => array(
							'width'  => 1,
							'height' => 1,
						),
					),
					array(
						'mime' => 'image/webp',
						'meta' => array(
							'width'  => 1,
							'height' => 1,
						),
					),
					array(
						'mime' => 'image/webp',
						'meta' => array(
							'width'  => 1,
							'height' => 1,
						),
					),
				),
			)
		);

		$this->assertSame( 'ok', $result->severity() );
	}

	/**
	 * Metadata sanity.
	 */
	public function test_metadata(): void {
		$check = new Images();

		$this->assertSame( 'performance_images', $check->id() );
		$this->assertSame( 'performance', $check->section() );
		$this->assertNotSame( '', $check->title() );
		$this->assertNotSame( '', $check->explanation() );
		$this->assertNotSame( '', $check->fix_hint() );
		$this->assertDoesNotMatchRegularExpression( '/[\x{2013}\x{2014}]/u', $check->explanation() . $check->fix_hint() . $check->title() );
	}

	/**
	 * Ten JPEG images with dimensions and no WebP or AVIF in the media library.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function old_jpegs(): array {
		return array_fill(
			0,
			10,
			array(
				'mime' => 'image/jpeg',
				'meta' => array(
					'width'  => 800,
					'height' => 600,
				),
			)
		);
	}

	/**
	 * An optimizer that serves WebP through rewrite rules leaves nothing in the media library, so the missing
	 * WebP is not reported: the check says what it could not see.
	 */
	public function test_optimizer_serving_webp_replaces_the_warning_with_a_note(): void {
		$result = $this->run_with(
			array(
				'images'    => self::old_jpegs(),
				'optimizer' => 'ShortPixel',
			)
		);

		$this->assertSame( 'info', $result->severity() );
		$this->assertStringContainsString( 'served as WebP by ShortPixel, not checked', $result->summary() );
		$this->assertStringNotContainsString( 'no WebP', $result->summary() );
	}

	/**
	 * Missing dimensions are still reported next to an optimizer.
	 */
	public function test_optimizer_does_not_hide_missing_dimensions(): void {
		$images = array_fill(
			0,
			10,
			array(
				'mime' => 'image/jpeg',
				'meta' => null,
			)
		);

		$result = $this->run_with(
			array(
				'images'    => $images,
				'optimizer' => 'Imagify',
			)
		);

		$this->assertSame( 'warning', $result->severity() );
	}

	/**
	 * The live reader names the optimizer from the active plugins.
	 */
	public function test_live_reader_finds_the_optimizer(): void {
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant for the test.
		}
		$db           = \Mockery::mock( 'wpdb' );
		$db->posts    = 'wp_posts';
		$db->postmeta = 'wp_postmeta';
		$db->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $query ): string => $query );
		$db->shouldReceive( 'get_results' )->andReturn(
			array(
				array(
					'mime' => 'image/jpeg',
					'meta' => null,
				),
			)
		);
		$GLOBALS['wpdb'] = $db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double.
		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $fallback ) => 'active_plugins' === $name ? array( 'ewww-image-optimizer/ewww-image-optimizer.php' ) : $fallback
		);

		$result = ( new Images() )->run();
		unset( $GLOBALS['wpdb'] );

		$this->assertStringContainsString( 'EWWW Image Optimizer', $result->summary() );
	}
}
