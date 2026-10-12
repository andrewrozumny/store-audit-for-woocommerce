<?php
/**
 * Must-use plugins check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Plugins;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;
use StoreAudit\Support\DetailList;
use StoreAudit\Support\NotCheckedException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Must-use plugins load silently, so forgotten ones are easy to miss.
 */
final class PluginsMu extends AbstractCheck {

	/**
	 * File names of must-use plugins that hosts install themselves (WP Engine, Kinsta, GoDaddy, Pantheon,
	 * WordPress.com). They are not the owner's forgotten code and are not judged by age.
	 */
	private const HOST_FILES = '/^(wpengine-common|kinsta-mu-plugins|gd-system-plugin|pantheon(-mu-plugin)?|wpcomsh(-loader)?)(\.php|\/)/i';

	/**
	 * Entries: array<string,array{name:string,mtime:int}> keyed by file name.
	 *
	 * @var \Closure
	 */
	private \Closure $source;

	/**
	 * Current unix time.
	 *
	 * @var int
	 */
	private int $now;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Data source override for tests.
	 * @param int|null      $now    Current unix time override for tests.
	 */
	public function __construct( ?callable $source = null, ?int $now = null ) {
		$this->source = \Closure::fromCallable( $source ?? array( $this, 'read_mu_plugins' ) );
		$this->now    = $now ?? time();
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'plugins_mu';
	}

	/**
	 * Section.
	 */
	public function section(): string {
		return Section::PLUGINS;
	}

	/**
	 * Title.
	 */
	public function title(): string {
		return __( 'Must-use plugins', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Must-use plugins are loaded automatically and cannot be switched off from the Plugins screen. Hosts and developers use them, but old forgotten ones keep running unnoticed and may no longer be maintained.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Ask your developer or host what each old must-use plugin does, and remove the ones that are not needed.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$items = Arr::to_array( ( $this->source )() );
		if ( array() === $items ) {
			return Result::ok( __( 'No must-use plugins are installed.', 'store-audit-for-woocommerce' ) );
		}

		$cutoff = strtotime( '-2 years', $this->now );
		$lines  = array();
		$hosted = array();
		$old    = 0;
		foreach ( $items as $file => $raw_item ) {
			$item = Arr::to_string_keyed( $raw_item );
			$name = Arr::get_string( $item, 'name' );
			if ( $this->is_host_plugin( (string) $file, $name ) ) {
				unset( $items[ $file ] );
				$hosted[] = sprintf(
					/* translators: 1: plugin name, 2: file name. */
					__( '%1$s (%2$s): installed by your host', 'store-audit-for-woocommerce' ),
					$name,
					$file
				);
				continue;
			}
			$mtime   = Arr::get_int( $item, 'mtime' );
			$is_old  = $mtime > 0 && $mtime < $cutoff;
			$old    += $is_old ? 1 : 0;
			$lines[] = self::entry_line( $name, (string) $file, $is_old ? $mtime : 0 );
		}

		if ( array() === $lines ) {
			return Result::ok( __( 'The only must-use plugins are the ones your host installs, which is normal.', 'store-audit-for-woocommerce' ), DetailList::cap( $hosted ) );
		}

		if ( $old > 0 ) {
			return Result::warning(
				sprintf(
					/* translators: 1: number of old must-use plugins, 2: total number. */
					_n( '%1$d of %2$d must-use plugins has not been touched for over 2 years.', '%1$d of %2$d must-use plugins have not been touched for over 2 years.', $old, 'store-audit-for-woocommerce' ),
					$old,
					count( $items )
				),
				DetailList::cap( $lines )
			);
		}

		return Result::info(
			sprintf(
				/* translators: %d: number of must-use plugins. */
				_n( '%d must-use plugin is installed.', '%d must-use plugins are installed.', count( $items ), 'store-audit-for-woocommerce' ),
				count( $items )
			),
			DetailList::cap( $lines )
		);
	}

	/**
	 * Detail line for one must-use plugin; an old one carries the date its file last changed.
	 *
	 * @param string $name  Plugin name.
	 * @param string $file  File name.
	 * @param int    $mtime File modification time when the plugin counts as old, else 0.
	 */
	private static function entry_line( string $name, string $file, int $mtime ): string {
		if ( $mtime > 0 ) {
			return sprintf(
				/* translators: 1: plugin name, 2: file name, 3: date. */
				__( '%1$s (%2$s): file last changed %3$s, old must-use plugin, check if still needed', 'store-audit-for-woocommerce' ),
				$name,
				$file,
				gmdate( 'j M Y', $mtime )
			);
		}
		return sprintf(
			/* translators: 1: plugin name, 2: file name. */
			__( '%1$s (%2$s)', 'store-audit-for-woocommerce' ),
			$name,
			$file
		);
	}

	/**
	 * Whether a must-use plugin is one a hosting provider installs.
	 *
	 * @param string $file File name relative to the mu-plugins folder.
	 * @param string $name Plugin name.
	 */
	private function is_host_plugin( string $file, string $name ): bool {
		if ( 1 === preg_match( self::HOST_FILES, $file ) ) {
			return true;
		}
		return 'mu-plugin.php' === strtolower( $file ) && str_starts_with( strtolower( $name ), 'wp engine' );
	}

	/**
	 * Read must-use plugins from WordPress and the mu-plugins directory.
	 *
	 * @return array<string,array{name:string,mtime:int}>
	 * @throws NotCheckedException When the plugin functions are not loaded.
	 */
	private function read_mu_plugins(): array {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			throw new NotCheckedException( esc_html__( 'the WordPress plugin functions are not loaded.', 'store-audit-for-woocommerce' ) );
		}
		$out = array();
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? rtrim( WPMU_PLUGIN_DIR, '/\\' ) : '';

		foreach ( get_mu_plugins() as $file => $data ) {
			$name = isset( $data['Name'] ) && is_string( $data['Name'] ) && '' !== $data['Name'] ? $data['Name'] : (string) $file;
			$time = '' !== $dir && file_exists( $dir . '/' . $file ) ? filemtime( $dir . '/' . $file ) : 0;

			$out[ (string) $file ] = array(
				'name'  => $name,
				'mtime' => false === $time ? 0 : $time,
			);
		}

		// Also list loose PHP files that get_mu_plugins() may skip (no plugin header).
		if ( '' !== $dir && is_dir( $dir ) ) {
			$found = glob( $dir . '/*.php' );
			foreach ( false === $found ? array() : $found as $path ) {
				$file = basename( $path );
				if ( ! isset( $out[ $file ] ) ) {
					$time         = filemtime( $path );
					$out[ $file ] = array(
						'name'  => $file,
						'mtime' => false === $time ? 0 : $time,
					);
				}
			}
		}

		ksort( $out );
		return $out;
	}
}
