<?php
/**
 * Score tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use StoreAudit\Score;

/**
 * Tests for Score.
 *
 * @covers \StoreAudit\Score
 */
final class ScoreTest extends TestCase {

	/**
	 * Build a minimal result row.
	 *
	 * @param string $section  Section id.
	 * @param string $severity Severity.
	 * @return array{section:string,severity:string}
	 */
	private function row( string $section, string $severity ): array {
		return array(
			'section'  => $section,
			'severity' => $severity,
		);
	}

	/**
	 * Penalties are applied per severity.
	 */
	public function test_section_penalties(): void {
		$score = Score::calculate(
			array(
				$this->row( 'security', 'critical' ),
				$this->row( 'security', 'warning' ),
				$this->row( 'security', 'info' ),
				$this->row( 'security', 'ok' ),
			)
		);

		$this->assertSame( 63, $score['sections']['security']['score'] );
		$this->assertSame( 1, $score['sections']['security']['counts']['critical'] );
		$this->assertSame( 1, $score['sections']['security']['counts']['ok'] );
		$this->assertSame( 63, $score['overall'] );
	}

	/**
	 * A section never goes below zero.
	 */
	public function test_score_floors_at_zero(): void {
		$rows = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$rows[] = $this->row( 'security', 'critical' );
		}
		$score = Score::calculate( $rows );

		$this->assertSame( 0, $score['sections']['security']['score'] );
	}

	/**
	 * Overall is the weighted average of the included sections.
	 */
	public function test_overall_is_weighted(): void {
		// Security (weight 30) at 75, performance (weight 10) at 100 -> (75*30 + 100*10) / 40 = 81.25.
		$score = Score::calculate(
			array(
				$this->row( 'security', 'critical' ),
				$this->row( 'performance', 'ok' ),
			)
		);

		$this->assertSame( 81, $score['overall'] );
	}

	/**
	 * Sections with only errors (or no results) are left out.
	 */
	public function test_sections_without_real_results_are_excluded(): void {
		$score = Score::calculate(
			array(
				$this->row( 'security', 'error' ),
				$this->row( 'plugins', 'warning' ),
			)
		);

		$this->assertArrayNotHasKey( 'security', $score['sections'] );
		$this->assertArrayNotHasKey( 'performance', $score['sections'] );
		$this->assertSame( 90, $score['overall'] );
	}

	/**
	 * Errors do not reduce the score of a section that has other results.
	 */
	public function test_errors_do_not_penalise(): void {
		$score = Score::calculate(
			array(
				$this->row( 'environment', 'ok' ),
				$this->row( 'environment', 'error' ),
			)
		);

		$this->assertSame( 100, $score['sections']['environment']['score'] );
		$this->assertSame( 1, $score['sections']['environment']['counts']['error'] );
	}

	/**
	 * No usable results gives zero and no sections.
	 */
	public function test_empty_input(): void {
		$score = Score::calculate( array() );

		$this->assertSame( 0, $score['overall'] );
		$this->assertSame( array(), $score['sections'] );
	}
}
