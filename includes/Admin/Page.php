<?php
/**
 * Admin page under the WooCommerce menu.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Admin;

use StoreAudit\Ajax;
use StoreAudit\Checks\Section;
use StoreAudit\Report;
use StoreAudit\Support\Arr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Store Audit page.
 */
final class Page {

	public const SLUG = 'store-audit';

	/**
	 * Page hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add submenu under WooCommerce.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Store Audit', 'store-audit-for-woocommerce' ),
			__( 'Store Audit', 'store-audit-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
		if ( is_string( $hook ) ) {
			$this->hook_suffix = $hook;
		}
	}

	/**
	 * Enqueue assets, only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'store-audit-admin', STORE_AUDIT_URL . 'resources/admin.css', array(), STORE_AUDIT_VERSION );
		wp_enqueue_script( 'store-audit-admin', STORE_AUDIT_URL . 'resources/admin.js', array(), STORE_AUDIT_VERSION, true );

		$last = Report::get_last();
		if ( null !== $last ) {
			$last['generated_at_local'] = Report::local_date( Arr::get_string( $last, 'generated_at' ) );
		}

		$config = array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( Ajax::NONCE_ACTION ),
			'lastReport' => $last,
			'sections'   => Section::labels(),
			'strings'    => $this->strings(),
		);
		wp_add_inline_script(
			'store-audit-admin',
			'window.storeAuditConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Strings used by the script.
	 *
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'running'         => __( 'Running...', 'store-audit-for-woocommerce' ),
			'copied'          => __( 'Report copied to the clipboard.', 'store-audit-for-woocommerce' ),
			'copyFailed'      => __( 'Copying was blocked by the browser. The report is selected below, press Ctrl+C (Cmd+C on Mac) to copy it.', 'store-audit-for-woocommerce' ),
			'copyError'       => __( 'The report could not be loaded. Run the audit again.', 'store-audit-for-woocommerce' ),
			'summaryCritical' => __( 'Critical', 'store-audit-for-woocommerce' ),
			'summaryWarnings' => __( 'Warnings', 'store-audit-for-woocommerce' ),
			'summaryInfo'     => __( 'Info', 'store-audit-for-woocommerce' ),
			'summaryPassed'   => __( 'Passed', 'store-audit-for-woocommerce' ),
			'summaryError'    => __( 'Not checked', 'store-audit-for-woocommerce' ),
			/* translators: %d: number of passed checks in a section. */
			'passedChecks'    => __( 'Passed checks (%d)', 'store-audit-for-woocommerce' ),
			'run'             => __( 'Run audit', 'store-audit-for-woocommerce' ),
			'runAgain'        => __( 'Run again', 'store-audit-for-woocommerce' ),
			'lastRun'         => __( 'Last run:', 'store-audit-for-woocommerce' ),
			/* translators: 1: check title, 2: current number, 3: total number. */
			'checking'        => __( 'Checking: %1$s (%2$s of %3$s)', 'store-audit-for-woocommerce' ),
			'finishing'       => __( 'Building the report...', 'store-audit-for-woocommerce' ),
			'couldNot'        => __( 'Could not check', 'store-audit-for-woocommerce' ),
			'failedStart'     => __( 'The audit could not be started. Reload the page and try again.', 'store-audit-for-woocommerce' ),
			'failedEnd'       => __( 'The report could not be built. Please run the audit again.', 'store-audit-for-woocommerce' ),
			'score'           => __( 'Store health score', 'store-audit-for-woocommerce' ),
			'outOf'           => __( 'out of 100', 'store-audit-for-woocommerce' ),
			'whatIsThis'      => __( 'What is this and why it matters', 'store-audit-for-woocommerce' ),
			'whatToDo'        => __( 'What to do', 'store-audit-for-woocommerce' ),
			'noResults'       => __( 'No checks are registered yet.', 'store-audit-for-woocommerce' ),
			'critical'        => __( 'Critical', 'store-audit-for-woocommerce' ),
			'warning'         => __( 'Warning', 'store-audit-for-woocommerce' ),
			'info'            => __( 'Info', 'store-audit-for-woocommerce' ),
			'ok'              => __( 'OK', 'store-audit-for-woocommerce' ),
			'error'           => __( 'Not checked', 'store-audit-for-woocommerce' ),
		);
	}

	/**
	 * Render the page shell. The script fills in the rest.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'store-audit-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$has_report = null !== Report::get_last();
		?>
		<div class="wrap store-audit">
			<h1><?php echo esc_html__( 'Store Audit', 'store-audit-for-woocommerce' ); ?></h1>
			<p class="store-audit-intro">
				<?php echo esc_html__( 'Checks your store for security, settings, performance and environment problems. It only reads data and never changes anything on your site.', 'store-audit-for-woocommerce' ); ?>
			</p>
			<div class="store-audit-controls">
				<button type="button" class="button button-primary button-hero" id="store-audit-run"><?php echo esc_html__( 'Run audit', 'store-audit-for-woocommerce' ); ?></button>
				<span class="store-audit-last" id="store-audit-last"></span>
			</div>
			<div class="store-audit-progress" id="store-audit-progress" hidden>
				<div class="store-audit-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="store-audit-progress-fill"></span></div>
				<p class="store-audit-progress-text" id="store-audit-progress-text" aria-live="polite"></p>
			</div>
			<div class="store-audit-actions" id="store-audit-actions"<?php echo $has_report ? '' : ' hidden'; ?>>
				<a class="button" id="store-audit-download" href="<?php echo esc_url( Exports::url( 'store_audit_download' ) ); ?>"><?php echo esc_html__( 'Download JSON', 'store-audit-for-woocommerce' ); ?></a>
				<a class="button" id="store-audit-print" href="<?php echo esc_url( Exports::url( 'store_audit_print' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Print / Save as PDF', 'store-audit-for-woocommerce' ); ?></a>
				<button type="button" class="button" id="store-audit-copy"><?php echo esc_html__( 'Copy report as Markdown', 'store-audit-for-woocommerce' ); ?></button>
				<span class="store-audit-notice" id="store-audit-notice" role="status" aria-live="polite"></span>
			</div>
			<div class="store-audit-fallback" id="store-audit-fallback" hidden>
				<label for="store-audit-fallback-text" class="screen-reader-text"><?php echo esc_html__( 'Report as Markdown', 'store-audit-for-woocommerce' ); ?></label>
				<textarea id="store-audit-fallback-text" class="large-text code" rows="12" readonly></textarea>
			</div>
			<div class="store-audit-report" id="store-audit-report"></div>
			<div class="store-audit-footer" id="store-audit-footer"<?php echo $has_report ? '' : ' hidden'; ?>>
			<?php
			echo wp_kses(
				Report::attribution_html(),
				array(
					'a' => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
				)
			);
			?>
																	</div>
		</div>
		<?php
	}
}
