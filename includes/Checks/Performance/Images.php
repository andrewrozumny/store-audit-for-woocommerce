<?php
/**
 * Image quality and format check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Performance;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\ActivePlugins;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image quality and format.
 */
final class Images extends AbstractCheck {

	/**
	 * Image optimizers that turn images into WebP or AVIF and serve them through rewrite rules or the page markup.
	 * The media library keeps the original files, so the library alone cannot show their work.
	 */
	private const OPTIMIZERS = array(
		'shortpixel-image-optimiser' => 'ShortPixel',
		'imagify'                    => 'Imagify',
		'ewww-image-optimizer'       => 'EWWW Image Optimizer',
		'optimole-wp'                => 'Optimole',
		'webp-converter-for-media'   => 'Converter for Media',
		'webp-express'               => 'WebP Express',
	);

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
		return 'performance_images';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::PERFORMANCE;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Image quality and format', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Images without saved dimensions make the page jump while it loads, and old formats like JPEG and PNG are heavier than WebP or AVIF, so mobile shoppers wait longer.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Regenerate thumbnails to restore missing sizes, and use an image optimization plugin that serves WebP or AVIF.', 'store-audit-for-woocommerce' );
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
		$db = Db::wpdb();
		// Latest 50 images only, ordered by primary key.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only audit query on the newest 50 attachments; WordPress has no API for it.
		$rows = $db->get_results(
			$db->prepare(
				"SELECT p.post_mime_type AS mime, m.meta_value AS meta FROM %i p LEFT JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND p.post_mime_type <> %s ORDER BY p.ID DESC LIMIT 50",
				$db->posts,
				$db->postmeta,
				'image/%',
				'image/svg+xml'
			),
			ARRAY_A
		);

		$list = array();
		foreach ( Arr::to_array( $rows ) as $row ) {
			$row    = Arr::to_string_keyed( $row );
			$meta   = isset( $row['meta'] ) ? maybe_unserialize( Arr::to_string( $row['meta'] ) ) : null;
			$list[] = array(
				'mime' => Arr::get_string( $row, 'mime' ),
				'meta' => is_array( $meta ) ? $meta : null,
			);
		}
		$optimizer = '';
		foreach ( ActivePlugins::slugs() as $slug ) {
			if ( isset( self::OPTIMIZERS[ $slug ] ) ) {
				$optimizer = self::OPTIMIZERS[ $slug ];
				break;
			}
		}
		return array(
			'images'    => $list,
			'optimizer' => $optimizer,
		);
	}

	/**
	 * Turn data into a result.
	 *
	 * @param array<string,mixed> $d Data.
	 */
	private function evaluate( array $d ): Result {
		$images = array();
		foreach ( Arr::get_array( $d, 'images' ) as $image ) {
			$images[] = Arr::to_string_keyed( $image );
		}
		$total = count( $images );
		if ( 0 === $total ) {
			return Result::info( __( 'There are no images in the media library to check.', 'store-audit-for-woocommerce' ) );
		}

		$optimizer = Arr::get_string( $d, 'optimizer' );
		$modern    = array( 'image/webp', 'image/avif' );
		$is_new    = static function ( array $image ) use ( $modern ): bool {
			if ( in_array( $image['mime'] ?? '', $modern, true ) ) {
				return true;
			}
			$meta = Arr::get_array( $image, 'meta' );
			if ( array() !== array_intersect( $modern, array_keys( Arr::get_array( $meta, 'sources' ) ) ) ) {
				return true;
			}
			foreach ( is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array() as $size ) {
				if ( ! is_array( $size ) ) {
					continue;
				}
				if ( in_array( $size['mime-type'] ?? '', $modern, true ) ) {
					return true;
				}
				if ( array() !== array_intersect( $modern, array_keys( Arr::get_array( $size, 'sources' ) ) ) ) {
					return true;
				}
			}
			return false;
		};

		$no_dims = 0;
		$old     = 0;
		foreach ( $images as $image ) {
			$meta = Arr::get_array( $image, 'meta' );
			if ( Arr::get_int( $meta, 'width' ) <= 0 || Arr::get_int( $meta, 'height' ) <= 0 ) {
				++$no_dims;
			}
			if ( ! $is_new( $image ) ) {
				++$old;
			}
		}

		$sample = sprintf(
			/* translators: %d: number of images sampled. */
			_n( 'Of the latest %d image', 'Of the latest %d images', $total, 'store-audit-for-woocommerce' ),
			$total
		);
		$dims = sprintf(
			/* translators: %d: number of images without saved dimensions. */
			_n( '%d has no saved dimensions', '%d have no saved dimensions', $no_dims, 'store-audit-for-woocommerce' ),
			$no_dims
		);
		$details = array();
		if ( $no_dims > 0 ) {
			$details[] = __( 'Images without dimensions cause the page to jump while loading (layout shift).', 'store-audit-for-woocommerce' );
		}

		if ( '' !== $optimizer ) {
			// The optimizer serves WebP or AVIF outside the media library, so the library cannot show it.
			$summary = sprintf(
				/* translators: 1: sample size, for example "Of the latest 23 images", 2: images without saved dimensions, 3: name of the image optimizer plugin. */
				__( '%1$s, %2$s. Images are served as WebP by %3$s, not checked.', 'store-audit-for-woocommerce' ),
				$sample,
				$dims,
				$optimizer
			);
			return $no_dims > $total / 10 ? Result::warning( $summary, $details ) : Result::info( $summary, $details );
		}

		$summary = sprintf(
			/* translators: 1: sample size, for example "Of the latest 23 images", 2: images without size information, 3: images without WebP or AVIF. */
			__( '%1$s, %2$s and %3$s.', 'store-audit-for-woocommerce' ),
			$sample,
			$dims,
			sprintf(
				/* translators: %d: number of images without a WebP or AVIF version. */
				_n( '%d has no WebP or AVIF version', '%d have no WebP or AVIF version', $old, 'store-audit-for-woocommerce' ),
				$old
			)
		);
		if ( $old > $total / 2 ) {
			$details[] = __( 'Pages load slower on mobile when images are not WebP or AVIF.', 'store-audit-for-woocommerce' );
		}

		if ( $no_dims > $total / 10 ) {
			return Result::warning( $summary, $details );
		}
		if ( $old > $total / 2 ) {
			return Result::info( $summary, $details );
		}
		return Result::ok( $summary, $details );
	}
}
