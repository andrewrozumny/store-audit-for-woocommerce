<?php
/**
 * Folder walk for BackupsInWebroot.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Security;

use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks for backup and dump files in folders without ever running past an entry or time budget.
 */
final class BackupWalk {

	/**
	 * File name pattern of backups and database dumps.
	 */
	private const BACKUP_FILE = '/\.(sql|sql\.gz|sql\.zip|gz|tgz|tar|zip|7z|rar|wpress|bak|daf)$/i';

	/**
	 * Files found: label => size in bytes.
	 *
	 * @var array<string,int>
	 */
	private array $found = array();

	/**
	 * Labels of folders that exist but cannot be read.
	 *
	 * @var list<string>
	 */
	private array $unreadable = array();

	/**
	 * Entries still allowed.
	 *
	 * @var int
	 */
	private int $left;

	/**
	 * Whether the budget ran out before every folder was seen.
	 *
	 * @var bool
	 */
	private bool $partial = false;

	/**
	 * Clock returning seconds with decimals.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Time after which the walk stops.
	 *
	 * @var float
	 */
	private float $deadline;

	/**
	 * Constructor.
	 *
	 * @param int      $max_entries Maximum entries inspected.
	 * @param float    $max_seconds Maximum seconds.
	 * @param callable $clock       Clock.
	 */
	public function __construct( int $max_entries, float $max_seconds, callable $clock ) {
		$this->left     = $max_entries;
		$this->clock    = $clock;
		$this->deadline = Arr::to_float( call_user_func( $clock ) ) + $max_seconds;
	}

	/**
	 * Files found, label => size.
	 *
	 * @return array<string,int>
	 */
	public function found(): array {
		return $this->found;
	}

	/**
	 * Folders that could not be read.
	 *
	 * @return list<string>
	 */
	public function unreadable(): array {
		return $this->unreadable;
	}

	/**
	 * Whether the walk was cut short.
	 */
	public function partial(): bool {
		return $this->partial;
	}

	/**
	 * Backup-like files directly inside a folder, without going deeper.
	 *
	 * @param string $dir    Folder.
	 * @param string $prefix Label prefix.
	 */
	public function flat( string $dir, string $prefix ): void {
		$this->visit( $dir, $prefix, array(), false );
	}

	/**
	 * Backup-like files in a folder and all folders below it.
	 *
	 * @param string   $dir     Folder.
	 * @param string   $prefix  Label prefix.
	 * @param string[] $skip    Names of top-level sub folders that are not looked into.
	 */
	public function deep( string $dir, string $prefix, array $skip ): void {
		$this->visit( $dir, $prefix, $skip, true );
	}

	/**
	 * Walk a folder with an explicit stack, so a deep tree cannot exhaust the call stack.
	 *
	 * @param string   $dir       Folder.
	 * @param string   $prefix    Label prefix.
	 * @param string[] $skip      Top-level sub folders to skip.
	 * @param bool     $recursive Whether to go into sub folders.
	 */
	private function visit( string $dir, string $prefix, array $skip, bool $recursive ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$stack = array(
			array( $dir, $prefix ),
		);
		while ( array() !== $stack ) {
			$current = array_pop( $stack );
			$entries = is_readable( $current[0] ) ? scandir( $current[0] ) : false;
			if ( false === $entries ) {
				$this->unreadable[] = $current[1];
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( $this->left <= 0 || Arr::to_float( call_user_func( $this->clock ) ) > $this->deadline ) {
					$this->partial = true;
					return;
				}
				--$this->left;
				$path = rtrim( $current[0], '/\\' ) . '/' . $entry;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( $recursive && ! ( $current[1] === $prefix && in_array( $entry, $skip, true ) ) ) {
						$stack[] = array( $path, $current[1] . $entry . '/' );
					}
					continue;
				}
				if ( 1 === preg_match( self::BACKUP_FILE, $entry ) && is_file( $path ) ) {
					$size                                = filesize( $path );
					$this->found[ $current[1] . $entry ] = false === $size ? 0 : $size;
				}
			}
		}
	}
}
