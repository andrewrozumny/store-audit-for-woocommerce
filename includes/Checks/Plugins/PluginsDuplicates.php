<?php
/**
 * Duplicate plugins check.
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
 * Two active plugins doing the same job fight each other and slow the store down.
 */
final class PluginsDuplicates extends AbstractCheck {

	/**
	 * Known plugin slugs per category. Extend here or with the store_audit_duplicate_groups filter.
	 * Autoptimize is deliberately not a cache plugin and is left out.
	 */
	private const GROUPS = array(
		'SEO'      => array( 'wordpress-seo', 'seo-by-rank-math', 'all-in-one-seo-pack', 'autodescription', 'wp-seopress', 'slim-seo' ),
		'Caching'  => array( 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-fastest-cache', 'sg-cachepress', 'breeze', 'cache-enabler', 'hummingbird-performance' ),
		'Security' => array( 'wordfence', 'better-wp-security', 'sucuri-scanner', 'all-in-one-wp-security-and-firewall', 'ithemes-security-pro', 'defender-security', 'wp-cerber', 'solid-security' ),
		'Backup'   => array( 'updraftplus', 'backwpup', 'duplicator', 'all-in-one-wp-migration', 'backup-backup', 'jetpack-backup' ),
		'SMTP'     => array( 'wp-mail-smtp', 'post-smtp', 'fluent-smtp', 'easy-wp-smtp' ),
	);

	/**
	 * Plugin inventory.
	 *
	 * @var PluginInventory
	 */
	private PluginInventory $inventory;

	/**
	 * Groups override.
	 *
	 * @var array<string,list<string>>|null
	 */
	private ?array $groups;

	/**
	 * Constructor.
	 *
	 * @param PluginInventory|null            $inventory Inventory override for tests.
	 * @param array<string,list<string>>|null $groups    Groups override for tests (skips the filter).
	 */
	public function __construct( ?PluginInventory $inventory = null, ?array $groups = null ) {
		$this->inventory = $inventory ?? new PluginInventory();
		$this->groups    = $groups;
	}

	/**
	 * Check id.
	 */
	public function id(): string {
		return 'plugins_duplicates';
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
		return __( 'Plugins that do the same job', 'store-audit-for-woocommerce' );
	}

	/**
	 * Explanation.
	 */
	public function explanation(): string {
		return __( 'Running two plugins for the same job (two SEO plugins, two caching plugins and so on) can break pages, produce duplicate output and slow the store down.', 'store-audit-for-woocommerce' );
	}

	/**
	 * Fix hint.
	 */
	public function fix_hint(): string {
		return __( 'Keep one plugin per job and deactivate the others (check settings first, then switch over).', 'store-audit-for-woocommerce' );
	}

	/**
	 * Groups in use.
	 *
	 * @return array<string,list<string>>
	 */
	private function groups(): array {
		if ( null !== $this->groups ) {
			return $this->groups;
		}
		$groups = $this->filtered_groups();
		if ( ! is_array( $groups ) ) {
			return self::GROUPS;
		}
		$clean = array();
		foreach ( $groups as $label => $slugs ) {
			if ( is_string( $label ) ) {
				$clean[ $label ] = Arr::to_string_list( $slugs );
			}
		}
		return $clean;
	}

	/**
	 * Run the groups through the public filter. Third-party code may return anything, so the result is untyped.
	 */
	private function filtered_groups(): mixed {
		/**
		 * Filters the categories of plugins that should not be active together.
		 *
		 * @param array<string,list<string>> $groups Category label => plugin slugs.
		 */
		return apply_filters( 'store_audit_duplicate_groups', self::GROUPS );
	}

	/**
	 * Run.
	 */
	public function run(): Result {
		$installed = $this->inventory->installed();
		$by_slug   = array();
		foreach ( $this->inventory->active() as $file ) {
			$by_slug[ WporgApi::slug_from_file( $file ) ] = PluginInventory::name_of( $installed, $file );
		}

		$lines = array();
		foreach ( $this->groups() as $label => $slugs ) {
			$found = array();
			foreach ( $slugs as $slug ) {
				if ( isset( $by_slug[ $slug ] ) ) {
					$found[] = $by_slug[ $slug ];
				}
			}
			if ( count( $found ) >= 2 ) {
				$lines[] = sprintf(
					/* translators: 1: category such as SEO, 2: plugin names. */
					__( '%1$s: %2$s', 'store-audit-for-woocommerce' ),
					$label,
					implode( ', ', $found )
				);
			}
		}

		if ( array() === $lines ) {
			return Result::ok( __( 'No overlapping plugins found.', 'store-audit-for-woocommerce' ) );
		}

		return Result::warning(
			sprintf(
				/* translators: %d: number of categories. */
				_n( 'Several active plugins overlap in %d category.', 'Several active plugins overlap in %d categories.', count( $lines ), 'store-audit-for-woocommerce' ),
				count( $lines )
			),
			DetailList::cap( $lines )
		);
	}
}
