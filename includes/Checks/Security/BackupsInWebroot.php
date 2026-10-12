<?php
/**
 * Backup and database dump files in public folders.
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
 * Backup and database dump files in public folders.
 */
final class BackupsInWebroot extends AbstractSecurityCheck {

	/**
	 * Maximum directory entries inspected.
	 */
	private const MAX_ENTRIES = 2000;

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
	 * Constructor.
	 *
	 * @param string|null $root    WordPress root path (tests).
	 * @param string|null $uploads Uploads base directory (tests).
	 */
	public function __construct( ?string $root = null, ?string $uploads = null ) {
		$this->root    = $root;
		$this->uploads = $uploads;
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
			$root = (string) ABSPATH;
		}
		$uploads = $this->uploads;
		if ( null === $uploads ) {
			$info    = wp_upload_dir( null, false );
			$uploads = isset( $info['basedir'] ) ? (string) $info['basedir'] : '';
		}

		$budget = self::MAX_ENTRIES;
		$found  = $this->scan( $root, '', $budget );
		if ( '' !== $uploads ) {
			$found = array_merge( $found, $this->scan( $uploads, 'uploads/', $budget ) );
			foreach ( $this->year_folders( $uploads, $budget ) as $year ) {
				$found = array_merge( $found, $this->scan( trailingslashit( $uploads ) . $year, 'uploads/' . $year . '/', $budget ) );
			}
		}

		if ( array() === $found ) {
			return Result::ok( __( 'No backup or database dump files found in the site root or uploads.', 'store-audit-for-woocommerce' ) );
		}

		$lines    = array();
		$has_dump = false;
		foreach ( $found as $file ) {
			$lines[] = $file['name'] . ' (' . $this->format_bytes( $file['size'] ) . ')';
			if ( str_contains( strtolower( $file['name'] ), '.sql' ) ) {
				$has_dump = true;
			}
		}
		$summary = sprintf(
			/* translators: %d: number of files. */
			_n( '%d backup or dump file sits in a public folder.', '%d backup or dump files sit in public folders.', count( $found ), 'store-audit-for-woocommerce' ),
			count( $found )
		);
		return $has_dump ? Result::critical( $summary, $this->cap_list( $lines ) ) : Result::warning( $summary, $this->cap_list( $lines ) );
	}

	/**
	 * Backup-like files in the top level of a directory.
	 *
	 * @param string $dir    Directory.
	 * @param string $prefix Label prefix.
	 * @param int    $budget Remaining entries to inspect (by reference).
	 * @return list<array{name:string,size:int}>
	 */
	private function scan( string $dir, string $prefix, int &$budget ): array {
		$out     = array();
		$entries = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( false === $entries ) {
			return $out;
		}
		foreach ( $entries as $entry ) {
			if ( $budget <= 0 ) {
				break;
			}
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			--$budget;
			$path = trailingslashit( $dir ) . $entry;
			if ( ! is_file( $path ) || 1 !== preg_match( '/\.(zip|sql|sql\.gz|tar|tar\.gz|bak)$/i', $entry ) ) {
				continue;
			}
			$size  = filesize( $path );
			$out[] = array(
				'name' => $prefix . $entry,
				'size' => false === $size ? 0 : $size,
			);
		}
		return $out;
	}

	/**
	 * Year-named sub folders of the uploads directory.
	 *
	 * @param string $uploads Uploads directory.
	 * @param int    $budget  Remaining entries to inspect (by reference).
	 * @return list<string>
	 */
	private function year_folders( string $uploads, int &$budget ): array {
		$entries = is_dir( $uploads ) ? scandir( $uploads ) : false;
		$years   = array();
		if ( false === $entries ) {
			return $years;
		}
		foreach ( $entries as $entry ) {
			if ( $budget <= 0 ) {
				break;
			}
			if ( 1 === preg_match( '/^(19|20)\d{2}$/', $entry ) && is_dir( trailingslashit( $uploads ) . $entry ) ) {
				$years[] = $entry;
			}
		}
		return $years;
	}
}
