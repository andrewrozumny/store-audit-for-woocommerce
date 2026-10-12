<?php
/**
 * Report view tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use StoreAudit\ReportView;

/**
 * Tests for the grouping and ordering shared by the printed report and the Markdown export.
 *
 * @covers \StoreAudit\ReportView
 */
final class ReportViewTest extends TestCase {

	/**
	 * Problems come worst first and passed checks last.
	 */
	public function test_severity_order_lists_issues_worst_first_then_ok(): void {
		$this->assertSame( array( 'critical', 'warning', 'error', 'info', 'ok' ), ReportView::severity_order() );
	}

	/**
	 * One split gives both the sorted problems and the titles of the passed checks.
	 */
	public function test_split_section_separates_problems_from_passed_checks(): void {
		$split = ReportView::split_section(
			array(
				array(
					'severity' => 'info',
					'title'    => 'I',
				),
				array(
					'severity' => 'ok',
					'title'    => 'Fine',
				),
				array(
					'severity' => 'critical',
					'title'    => 'C',
				),
				array(
					'severity' => 'warning',
					'title'    => 'W',
				),
			)
		);

		$this->assertSame( array( 'C', 'W', 'I' ), array_column( $split['issues'], 'title' ) );
		$this->assertSame( array( 'Fine' ), $split['passed'] );
	}

	/**
	 * Rows keep their report order inside one severity.
	 */
	public function test_sort_issues_is_stable_inside_a_severity(): void {
		$sorted = ReportView::sort_issues(
			array(
				array(
					'severity' => 'warning',
					'title'    => 'first',
				),
				array(
					'severity' => 'critical',
					'title'    => 'crit',
				),
				array(
					'severity' => 'warning',
					'title'    => 'second',
				),
			)
		);

		$this->assertSame( array( 'crit', 'first', 'second' ), array_column( $sorted, 'title' ) );
	}

	/**
	 * A section with a null score reads as incomplete.
	 */
	public function test_section_score_text(): void {
		$this->assertSame( '55 / 100', ReportView::section_score_text( array( 'score' => 55 ) ) );
		$this->assertSame( '55', ReportView::section_score_text( array( 'score' => 55 ), false ) );
		$this->assertSame( 'incomplete', ReportView::section_score_text( array( 'score' => null ) ) );
	}
}
