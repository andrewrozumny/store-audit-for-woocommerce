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
}
