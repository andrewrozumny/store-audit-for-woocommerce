<?php
/**
 * Child theme and edited parent theme check.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Checks\Plugins;

use StoreAudit\Checks\AbstractCheck;
use StoreAudit\Checks\Section;
use StoreAudit\Result;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A child theme keeps your edits safe from theme updates. Edited parent themes lose them.
 */
final class PluginsThemeChild extends AbstractCheck {

	/**
	 * Maximum files listed.
	 */
	private const MAX_FILES = 20;

	/**
	 * Files newer than style.css by more than this many seconds count as edited.
	 */
	private const TOLERANCE = 86400;

	/**
	 * Context: array{template:string,stylesheet:string,name:string}.
	 *
	 * @var callable
	 */
	private $theme;

	/**
	 * File lister: receives the parent theme directory path, returns array<string,int> relative file => mtime
	 * (only .php and .css files; must include 'style.css').
	 *
	 * @var callable
	 */
	private $files;

	/**
	 * Wordpress.org client.
	 *
	 * @var WporgApi
	 */
	private WporgApi $api;

	/**
	 * Parent theme directory.
	 *
	 * @var callable
	 */
	private $directory;

	/**
	 * Constructor.
	 *
	 * @param callable|null $theme     Theme context override for tests.
	 * @param callable|null $files     File lister override for tests.
	 * @param WporgApi|null $api       API client override for tests.
	 * @param callable|null $directory Parent theme directory override for tests.
	 */
	public function __construct( ?callable $theme = null, ?callable $files = null, ?WporgApi $api = null, ?callable $directory = null ) {
		$this->theme     = $theme ?? static function (): array {
			$parent = wp_get_theme( get_template() );
			return array(
				'template'   => get_template(),
				'stylesheet' => get_stylesheet(),
				'name'       => $parent->exists() ? $parent->get( 'Name' ) : get_template(),
			);
		};
		$this->files     = $files ?? array( $this, 'list_files' );
		$this->api       = $api ?? new WporgApi();
		$this->directory = $directory ?? static fn(): string => get_template_directory();
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'plugins_theme_child';
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
		return __( 'Child theme and theme edits', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'When a theme is updated, any edits made directly inside it are overwritten. A child theme keeps your changes in a separate place. This is a best guess: files that are newer than the theme itself look edited, but a plugin or an update can also cause that.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Ask your developer to move custom code into a child theme before the next theme update.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$theme      = Arr::to_array( ( $this->theme )() );
		$parent     = Arr::get_string( $theme, 'template' );
		$stylesheet = Arr::get_string( $theme, 'stylesheet' );
		$theme_name = Arr::get_string( $theme, 'name' );

		$lookup = $this->api->theme( $parent );
		$origin = WporgApi::FOUND === $lookup['status']
			? sprintf(
				/* translators: %s: version number. */
				__( 'wordpress.org theme, version %s', 'store-audit-for-woocommerce' ),
				$lookup['version']
			)
			: __( 'not listed on wordpress.org', 'store-audit-for-woocommerce' );

		$files  = Arr::to_array( ( $this->files )( Arr::to_string( ( $this->directory )() ) ) );
		$edited = array();
		if ( isset( $files['style.css'] ) ) {
			$baseline = Arr::to_int( $files['style.css'] );
			foreach ( $files as $relative => $mtime ) {
				if ( 'style.css' !== $relative && Arr::to_int( $mtime ) > $baseline + self::TOLERANCE ) {
					$edited[] = (string) $relative;
				}
			}
			sort( $edited );
		}

		if ( array() !== $edited ) {
			$shown = array_slice( $edited, 0, self::MAX_FILES );
			if ( count( $edited ) > self::MAX_FILES ) {
				$shown[] = sprintf(
					/* translators: %d: number of items not shown. */
					__( 'and %d more', 'store-audit-for-woocommerce' ),
					count( $edited ) - self::MAX_FILES
				);
			}
			return Result::warning(
				sprintf(
					/* translators: 1: theme name, 2: number of files, 3: where the theme comes from. */
					_n( 'The files of the parent theme "%1$s" look edited (%2$d file is newer than the theme; %3$s).', 'The files of the parent theme "%1$s" look edited (%2$d files are newer than the theme; %3$s).', count( $edited ), 'store-audit-for-woocommerce' ),
					$theme_name,
					count( $edited ),
					$origin
				),
				$shown
			);
		}

		if ( $parent === $stylesheet ) {
			return Result::info(
				sprintf(
					/* translators: 1: theme name, 2: where the theme comes from. */
					__( 'The active theme "%1$s" is used without a child theme (%2$s). Updates of the theme will overwrite any edits; a child theme keeps them.', 'store-audit-for-woocommerce' ),
					$theme_name,
					$origin
				)
			);
		}

		return Result::ok(
			sprintf(
				/* translators: 1: theme name, 2: where the theme comes from. */
				__( 'A child theme is active and the parent theme "%1$s" looks unedited (%2$s).', 'store-audit-for-woocommerce' ),
				$theme_name,
				$origin
			)
		);
	}

	/**
	 * List .php and .css files of a theme directory with their modification time (read only, capped).
	 *
	 * @param string $dir Theme directory.
	 * @return array<string,int>
	 */
	private function list_files( string $dir ): array {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$dir      = rtrim( $dir, '/\\' );
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);
		$count    = 0;
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			if ( ++$count > 3000 ) {
				break;
			}
			if ( ! in_array( strtolower( $file->getExtension() ), array( 'php', 'css' ), true ) ) {
				continue;
			}
			$relative         = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
			$out[ $relative ] = (int) $file->getMTime();
		}
		return $out;
	}
}
