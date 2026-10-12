<?php
/**
 * Tests for reading the stored report back.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit;

use StoreAudit\Report;
use StoreAudit\Result;

/**
 * The stored option may hold anything: another plugin, a failed update or a manual edit can change it.
 *
 * @covers \StoreAudit\Report
 */
final class StoredReportTest extends TestCase {

	use OptionStoreTrait;

	/**
	 * Stub the option functions.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stub_option_store();
	}

	/**
	 * Values that are not a usable report at all.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function unusable_values(): array {
		return array(
			'string'                   => array( 'garbage' ),
			'number'                   => array( 12345 ),
			'true'                     => array( true ),
			'empty array'              => array( array() ),
			'array without results'    => array( array( 'generated_at' => 'x' ) ),
			'list instead of a report' => array( array( 1, 2, 3 ) ),
			'results is a string'      => array( array( 'results' => 'text' ) ),
			'results is a number'      => array( array( 'results' => 7 ) ),
			'results is empty'         => array( array( 'results' => array() ) ),
			'results hold no rows'     => array( array( 'results' => array( 'x', 5, null ) ) ),
			'incomplete class'         => array( unserialize( 'O:12:"UnknownThing":1:{s:1:"a";i:1;}' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Builds the object a missing class leaves behind.
		);
	}

	/**
	 * Unusable values read back as "no report", never as a broken report.
	 *
	 * @dataProvider unusable_values
	 *
	 * @param mixed $stored Stored value.
	 */
	public function test_unusable_values_read_as_no_report( mixed $stored ): void {
		$this->options[ Report::OPTION ] = $stored;
		$this->assertNull( Report::get_last() );
	}

	/**
	 * Rows with a wrong shape are repaired or dropped so every consumer can rely on the types.
	 */
	public function test_rows_are_normalised(): void {
		$this->options[ Report::OPTION ] = array(
			'generated_at' => array( 'not a string' ),
			'site_url'     => 'https://shop.example.com',
			'results'      => array(
				array(
					'id'       => 'a',
					'section'  => 'security',
					'title'    => 'Title A',
					'severity' => 'bogus',
					'summary'  => 'Sum',
					'details'  => 'not a list',
				),
				'junk',
				array(
					'id'       => 'b',
					'section'  => 'security',
					'title'    => array( 'x' ),
					'severity' => Result::WARNING,
					'summary'  => 'Warn',
					'details'  => array( 'one', array( 'nested' ), 2 ),
				),
			),
		);

		$report = Report::get_last();

		$this->assertNotNull( $report );
		$this->assertSame( '', $report['generated_at'] );
		$rows = $report['results'];
		$this->assertIsArray( $rows );
		$this->assertCount( 2, $rows );
		$this->assertIsArray( $rows[0] );
		$this->assertSame( Result::ERROR, $rows[0]['severity'] );
		$this->assertSame( array(), $rows[0]['details'] );
		$this->assertIsArray( $rows[1] );
		$this->assertSame( '', $rows[1]['title'] );
		$this->assertSame( array( 'one', '2' ), $rows[1]['details'] );
		$score = $report['score'];
		$this->assertIsArray( $score );
		$this->assertIsInt( $score['overall'] );
		$this->assertIsArray( $score['sections'] );
	}

	/**
	 * A good report, including its stored score, comes back unchanged.
	 */
	public function test_valid_report_is_returned_as_stored(): void {
		$stored                          = array(
			'generated_at'   => '2026-10-12T08:30:00Z',
			'site_url'       => 'https://shop.example.com',
			'plugin_version' => '0.1.0',
			'wp_version'     => '7.1',
			'wc_version'     => '11.2',
			'score'          => array(
				'overall'    => 40,
				'checked'    => 1,
				'total'      => 1,
				'incomplete' => false,
				'sections'   => array(
					'security' => array(
						'label'      => 'Security',
						'score'      => 40,
						'counts'     => array(
							'critical' => 1,
							'warning'  => 0,
							'info'     => 0,
							'ok'       => 0,
							'error'    => 0,
						),
						'checked'    => 1,
						'total'      => 1,
						'incomplete' => false,
					),
				),
			),
			'results'        => array(
				array(
					'id'          => 'a',
					'section'     => 'security',
					'title'       => 'A',
					'severity'    => Result::CRITICAL,
					'summary'     => 'Bad',
					'details'     => array( 'detail' ),
					'explanation' => 'Why',
					'fix_hint'    => 'Fix',
					'duration_ms' => 4,
					'sensitive'   => false,
				),
			),
		);
		$this->options[ Report::OPTION ] = $stored;

		$this->assertSame( $stored, Report::get_last() );
	}

	/**
	 * A broken score is recomputed from the rows instead of being passed to the page.
	 */
	public function test_broken_score_is_recomputed(): void {
		$this->options[ Report::OPTION ] = array(
			'score'   => array(
				'overall'  => 'high',
				'sections' => 'none',
			),
			'results' => array(
				array(
					'id'       => 'a',
					'section'  => 'security',
					'title'    => 'A',
					'severity' => Result::WARNING,
					'summary'  => 'Warn',
				),
			),
		);

		$report = Report::get_last();

		$this->assertNotNull( $report );
		$score = $report['score'];
		$this->assertIsArray( $score );
		$this->assertSame( 90, $score['overall'] );
	}

	/**
	 * The sensitive flag survives normalising; a missing flag means not sensitive.
	 */
	public function test_sensitive_flag_is_kept(): void {
		$this->options[ Report::OPTION ] = array(
			'results' => array(
				array(
					'id'        => 'a',
					'section'   => 'security',
					'title'     => 'A',
					'severity'  => Result::WARNING,
					'sensitive' => true,
				),
				array(
					'id'       => 'b',
					'section'  => 'security',
					'title'    => 'B',
					'severity' => Result::OK,
				),
			),
		);

		$report = Report::get_last();

		$this->assertNotNull( $report );
		$this->assertTrue( $report['results'][0]['sensitive'] );
		$this->assertFalse( $report['results'][1]['sensitive'] );
	}

	/**
	 * A score saved by an older version has no checked/total/incomplete fields and is worked out again.
	 */
	public function test_score_from_an_older_version_is_recomputed(): void {
		$this->options[ Report::OPTION ] = array(
			'score'   => array(
				'overall'  => 100,
				'sections' => array(
					'security' => array(
						'label'  => 'Security',
						'score'  => 100,
						'counts' => array( 'critical' => 1 ),
					),
				),
			),
			'results' => array(
				array(
					'id'       => 'a',
					'section'  => 'security',
					'title'    => 'A',
					'severity' => Result::CRITICAL,
					'summary'  => 'Public wp-config',
				),
			),
		);

		$report = Report::get_last();

		$this->assertNotNull( $report );
		$score = $report['score'];
		$this->assertIsArray( $score );
		$this->assertSame( 40, $score['overall'] );
		$this->assertSame( 1, $score['total'] );
		$this->assertFalse( $score['incomplete'] );
	}
}
