<?php
/**
 * Dashboard widget tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Admin;

use StoreAudit\Admin\DashboardWidget;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for the top-problems selection.
 *
 * @covers \StoreAudit\Admin\DashboardWidget
 */
final class DashboardWidgetTest extends TestCase {

	/**
	 * Row helper.
	 *
	 * @param string $title    Title.
	 * @param string $severity Severity.
	 * @return array<string,string>
	 */
	private function row( string $title, string $severity ): array {
		return array(
			'title'    => $title,
			'severity' => $severity,
			'summary'  => 's',
		);
	}

	/**
	 * Critical first, then warning, then error; info and ok never show up.
	 */
	public function test_top_three_by_severity(): void {
		$top = DashboardWidget::top_problems(
			array(
				$this->row( 'info', 'info' ),
				$this->row( 'warn A', 'warning' ),
				$this->row( 'ok', 'ok' ),
				$this->row( 'err', 'error' ),
				$this->row( 'crit', 'critical' ),
				$this->row( 'warn B', 'warning' ),
			)
		);

		$this->assertSame( array( 'crit', 'warn A', 'warn B' ), array_column( $top, 'title' ) );
	}

	/**
	 * Errors fill the remaining slots.
	 */
	public function test_errors_after_warnings(): void {
		$top = DashboardWidget::top_problems( array( $this->row( 'err', 'error' ), $this->row( 'warn', 'warning' ) ) );

		$this->assertSame( array( 'warn', 'err' ), array_column( $top, 'title' ) );
	}

	/**
	 * Nothing to show when everything is fine.
	 */
	public function test_none_when_clean(): void {
		$this->assertSame( array(), DashboardWidget::top_problems( array( $this->row( 'a', 'ok' ), $this->row( 'b', 'info' ) ) ) );
	}

	/**
	 * The widget is for administrators only; a shop manager does not get it.
	 */
	public function test_widget_needs_manage_options(): void {
		\Brain\Monkey\Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'manage_options' !== $cap );
		\Brain\Monkey\Functions\expect( 'wp_add_dashboard_widget' )->never();

		( new DashboardWidget() )->add_widget();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * An administrator gets the widget.
	 */
	public function test_widget_is_added_for_administrators(): void {
		\Brain\Monkey\Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'manage_options' === $cap );
		\Brain\Monkey\Functions\expect( 'wp_add_dashboard_widget' )->once();

		( new DashboardWidget() )->add_widget();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * "Good work" appears only when there is no critical, no warning and no failed check; the score of an
	 * incomplete report carries its note.
	 */
	public function test_good_work_needs_a_clean_report_and_incomplete_is_marked(): void {
		\Brain\Monkey\Functions\when( 'current_user_can' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'admin_url' )->returnArg();
		\Brain\Monkey\Functions\when( 'esc_attr' )->returnArg();
		\Brain\Monkey\Functions\when( 'esc_url' )->returnArg();
		\Brain\Monkey\Functions\when( 'wp_date' )->justReturn( '12 Oct 2026' );
		\Brain\Monkey\Functions\when( 'get_option' )->alias(
			static fn( string $name ) => 'store_audit_last_report' === $name ? array(
				'generated_at' => '2026-10-12T08:30:00Z',
				'score'        => array(
					'overall'    => 100,
					'checked'    => 1,
					'total'      => 3,
					'incomplete' => true,
					'sections'   => array(),
				),
				'results'      => array(
					array(
						'id'       => 'a',
						'section'  => 'security',
						'title'    => 'A',
						'severity' => 'ok',
						'summary'  => 's',
					),
					array(
						'id'       => 'b',
						'section'  => 'security',
						'title'    => 'B',
						'severity' => 'error',
						'summary'  => 'did not run',
					),
				),
			) : 'Y-m-d'
		);

		ob_start();
		( new DashboardWidget() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Good work', $html );
		$this->assertStringContainsString( 'did not run', $html );
		$this->assertStringContainsString( 'Incomplete: 1 of 3 checks ran', $html );
	}
}
