<?php
/**
 * Backup and database dump files in public folders.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backup and database dump files in public folders.
 */
final class BackupsInWebroot extends AbstractSecurityCheck {

	/**
	 * Maximum directory entries inspected in one run.
	 */
	private const MAX_ENTRIES = 20000;

	/**
	 * Maximum seconds spent walking folders.
	 */
	private const MAX_SECONDS = 3.0;

	/**
	 * Folders backup plugins write to, relative to the site root. "*" stands for the random part of a name.
	 * They are walked completely, within the budget.
	 */
	private const BACKUP_FOLDERS = array(
		'wp-snapshots',
		'wp-content/ai1wm-backups',
		'wp-content/updraft',
		'wp-content/backups-dup-lite',
		'wp-content/backups-dup-pro',
		'wp-content/backup-db',
		'wp-content/backupwordpress-*',
		'wp-content/wpvividbackups',
		'wp-content/uploads/backwpup-*',
		'wp-content/uploads/wp-staging*',
		'wp-content/uploads/pb_backupbuddy',
	);

	/**
	 * Folder inside uploads that WooCommerce protects for downloadable products.
	 */
	private const PROTECTED_UPLOADS = 'woocommerce_uploads';

	/**
	 * Site root path.
	 *
	 * @var string|null
	 */
	private ?string $root;

	/**
	 * Uploads base directory.
	 *
	 * @var string|null
	 */
	private ?string $uploads;

	/**
	 * The wp-content directory.
	 *
	 * @var string|null
	 */
	private ?string $content;

	/**
	 * Media library lookup: receives paths such as "2024/05/file.zip", returns those that are attachments.
	 *
	 * @var callable
	 */
	private $attached;

	/**
	 * Entry budget.
	 *
	 * @var int
	 */
	private int $max_entries;

	/**
	 * Clock returning seconds with decimals.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param string|null   $root        WordPress root path (tests).
	 * @param string|null   $uploads     Uploads base directory (tests).
	 * @param string|null   $content     wp-content directory (tests).
	 * @param callable|null $attached    Media library lookup (tests).
	 * @param int|null      $max_entries Entry budget (tests).
	 * @param callable|null $clock       Seconds clock (tests).
	 */
	public function __construct( ?string $root = null, ?string $uploads = null, ?string $content = null, ?callable $attached = null, ?int $max_entries = null, ?callable $clock = null ) {
		$this->root        = $root;
		$this->uploads     = $uploads;
		$this->content     = $content;
		$this->attached    = $attached ?? array( $this, 'read_attachments' );
		$this->max_entries = $max_entries ?? self::MAX_ENTRIES;
		$this->clock       = $clock ?? static fn(): float => microtime( true );
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'security_backups_in_webroot';
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Backups in public folders', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Backup archives and database dumps left in the site folders can often be downloaded by anyone who guesses the file name. They contain customer data and passwords.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Move backups out of the web folder (or into a protected storage) and delete old copies.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$root = $this->root;
		if ( null === $root ) {
			$root = Arr::to_string( ABSPATH );
		}
		$content = $this->content ?? ( defined( 'WP_CONTENT_DIR' ) ? Arr::to_string( WP_CONTENT_DIR ) : trailingslashit( $root ) . 'wp-content' );
		$uploads = $this->uploads;
		if ( null === $uploads ) {
			$info    = wp_upload_dir( null, false );
			$uploads = $info['basedir'];
		}

		$walk = new BackupWalk( $this->max_entries, self::MAX_SECONDS, $this->clock );
		$walk->flat( $root, '' );
		$walk->flat( $content, 'wp-content/' );
		foreach ( $this->backup_folders( $root, $content, $uploads ) as $label => $dir ) {
			$walk->deep( $dir, $label . '/', array() );
		}
		if ( '' !== $uploads ) {
			$walk->deep( $uploads, 'uploads/', array( self::PROTECTED_UPLOADS ) );
		}

		$found = $this->without_media( $walk->found() );

		$lines    = array();
		$has_dump = false;
		foreach ( $found as $name => $size ) {
			$lines[] = $name . ' (' . $this->format_bytes( $size ) . ')';
			if ( str_contains( strtolower( $name ), '.sql' ) ) {
				$has_dump = true;
			}
		}
		$notes = array();
		if ( $walk->partial() ) {
			$notes[] = __( 'Partially scanned: the folders are large, so only the first part was looked at.', 'store-audit-for-woocommerce' );
		}

		if ( array() === $found ) {
			if ( array() !== $walk->unreadable() ) {
				return Result::info(
					__( 'Some folders could not be read, so backup files in them could not be looked for.', 'store-audit-for-woocommerce' ),
					array_merge(
						array_map(
							static fn( string $label ): string => sprintf(
								/* translators: %s: folder name. */
								__( 'Could not read: %s', 'store-audit-for-woocommerce' ),
								'' === $label ? '/' : $label
							),
							$walk->unreadable()
						),
						$notes
					)
				);
			}
			if ( array() !== $notes ) {
				return Result::info( __( 'No backup or database dump files found in the part that was scanned.', 'store-audit-for-woocommerce' ), $notes );
			}
			return Result::ok( __( 'No backup or database dump files found in the site root, wp-content or uploads.', 'store-audit-for-woocommerce' ) );
		}

		$summary = sprintf(
			/* translators: %d: number of files. */
			_n( '%d backup or dump file sits in a public folder.', '%d backup or dump files sit in public folders.', count( $found ), 'store-audit-for-woocommerce' ),
			count( $found )
		);
		$details = array_merge( $this->cap_list( $lines ), $notes );
		return $has_dump ? Result::critical( $summary, $details ) : Result::warning( $summary, $details );
	}

	/**
	 * Existing backup plugin folders keyed by their label.
	 *
	 * @param string $root    Site root.
	 * @param string $content wp-content directory.
	 * @param string $uploads Uploads directory.
	 * @return array<string,string>
	 */
	private function backup_folders( string $root, string $content, string $uploads ): array {
		$folders = array();
		foreach ( self::BACKUP_FOLDERS as $pattern ) {
			// The folders are written relative to the site root, while wp-content and uploads may live elsewhere.
			if ( str_starts_with( $pattern, 'wp-content/uploads/' ) ) {
				$base  = $uploads;
				$local = substr( $pattern, strlen( 'wp-content/uploads/' ) );
				$label = 'uploads/';
			} elseif ( str_starts_with( $pattern, 'wp-content/' ) ) {
				$base  = $content;
				$local = substr( $pattern, strlen( 'wp-content/' ) );
				$label = 'wp-content/';
			} else {
				$base  = $root;
				$local = $pattern;
				$label = '';
			}
			if ( '' === $base ) {
				continue;
			}
			$matches = str_contains( $local, '*' ) ? glob( trailingslashit( $base ) . $local, GLOB_ONLYDIR ) : array( trailingslashit( $base ) . $local );
			foreach ( false === $matches ? array() : $matches as $dir ) {
				if ( is_dir( $dir ) ) {
					$folders[ $label . basename( $dir ) ] = $dir;
				}
			}
		}
		return $folders;
	}

	/**
	 * Drop zip files that are items of the media library: one lookup for all candidates.
	 *
	 * @param array<string,int> $found File label => size.
	 * @return array<string,int>
	 */
	private function without_media( array $found ): array {
		$candidates = array();
		foreach ( array_keys( $found ) as $label ) {
			if ( 1 === preg_match( '#^uploads/((?:19|20)\d{2}/\d{2}/[^/]+\.zip)$#i', $label, $match ) ) {
				$candidates[ $label ] = $match[1];
			}
		}
		if ( array() === $candidates ) {
			return $found;
		}
		$attached = Arr::to_string_list( call_user_func( $this->attached, array_values( $candidates ) ) );
		foreach ( $candidates as $label => $relative ) {
			if ( in_array( $relative, $attached, true ) ) {
				unset( $found[ $label ] );
			}
		}
		return $found;
	}

	/**
	 * Which of the given upload paths are media library items.
	 *
	 * @param string[] $paths Paths relative to the uploads folder.
	 * @return list<string>
	 */
	private function read_attachments( array $paths ): array {
		$db  = Db::wpdb();
		$out = array();
		foreach ( array_chunk( $paths, 500 ) as $chunk ) {
			$ph = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only audit lookup; the placeholders are generated and every path goes through prepare().
			$rows = $db->get_col( $db->prepare( "SELECT meta_value FROM %i WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$ph})", array_merge( array( $db->postmeta ), $chunk ) ) );
			// phpcs:enable
			foreach ( $rows as $row ) {
				$out[] = Arr::to_string( $row );
			}
		}
		return $out;
	}

	/**
	 * Details name accounts, files or keys, so exports do not carry them.
	 */
	public function is_sensitive(): bool {
		return true;
	}
}
