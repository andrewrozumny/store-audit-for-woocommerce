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

		$this->assertSame( 40, $score['sections']['security']['score'] );
		$this->assertSame( 1, $score['sections']['security']['counts']['critical'] );
		$this->assertSame( 1, $score['sections']['security']['counts']['ok'] );
		$this->assertSame( 40, $score['overall'] );
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
		// Security (weight 30) at 80, performance (weight 10) at 100 -> (80*30 + 100*10) / 40 = 85.
		$score = Score::calculate(
			array(
				$this->row( 'security', 'warning' ),
				$this->row( 'security', 'warning' ),
				$this->row( 'performance', 'ok' ),
			)
		);

		$this->assertSame( 85, $score['overall'] );
	}

	/**
	 * A section where nothing ran keeps its place as incomplete and flags the report; it is not weighted.
	 */
	public function test_section_with_only_errors_is_incomplete(): void {
		$score = Score::calculate(
			array(
				$this->row( 'security', 'error' ),
				$this->row( 'plugins', 'warning' ),
			)
		);

		$this->assertNull( $score['sections']['security']['score'] );
		$this->assertTrue( $score['sections']['security']['incomplete'] );
		$this->assertArrayNotHasKey( 'performance', $score['sections'] );
		$this->assertSame( 90, $score['overall'] );
		$this->assertTrue( $score['incomplete'] );
		$this->assertSame( 1, $score['checked'] );
		$this->assertSame( 2, $score['total'] );
	}

	/**
	 * A whole Security section that did not run must not give a clean 100.
	 */
	public function test_security_all_error_is_not_a_clean_report(): void {
		$rows = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$rows[] = $this->row( 'security', 'error' );
		}
		for ( $i = 0; $i < 10; $i++ ) {
			$rows[] = $this->row( 'environment', 'ok' );
		}
		$score = Score::calculate( $rows );

		$this->assertTrue( $score['incomplete'] );
		$this->assertSame( 10, $score['checked'] );
		$this->assertSame( 18, $score['total'] );
		$this->assertSame( 'Incomplete: 10 of 18 checks ran', Score::incomplete_note( $score['checked'], $score['total'] ) );
	}

	/**
	 * More than half not checked hides the section score; exactly half does not.
	 */
	public function test_more_than_half_not_checked_hides_section_score(): void {
		$half = Score::calculate( array( $this->row( 'environment', 'ok' ), $this->row( 'environment', 'error' ) ) );
		$this->assertSame( 100, $half['sections']['environment']['score'] );
		$this->assertFalse( $half['incomplete'] );

		$most = Score::calculate( array( $this->row( 'environment', 'ok' ), $this->row( 'environment', 'error' ), $this->row( 'environment', 'error' ) ) );
		$this->assertNull( $most['sections']['environment']['score'] );
		$this->assertTrue( $most['incomplete'] );
	}

	/**
	 * One critical caps its section at 40 and the report at 49, however many checks pass.
	 */
	public function test_single_critical_caps_section_and_overall(): void {
		$rows = array( $this->row( 'security', 'critical' ) );
		for ( $i = 0; $i < 12; $i++ ) {
			$rows[] = $this->row( 'security', 'ok' );
		}
		foreach ( array( 'woocommerce', 'plugins', 'performance', 'environment' ) as $section ) {
			for ( $i = 0; $i < 5; $i++ ) {
				$rows[] = $this->row( $section, 'ok' );
			}
		}
		$score = Score::calculate( $rows );

		$this->assertSame( 40, $score['sections']['security']['score'] );
		$this->assertLessThanOrEqual( 49, $score['overall'] );
		$this->assertFalse( $score['incomplete'] );
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
		$this->assertFalse( $score['incomplete'] );
	}
}
