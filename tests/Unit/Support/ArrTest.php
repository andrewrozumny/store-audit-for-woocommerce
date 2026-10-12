<?php
/**
 * Arr tests.
 *
 * @package StoreAudit
 */

declare(strict_types=1);

namespace StoreAudit\Tests\Unit\Support;

use StoreAudit\Support\Arr;
use StoreAudit\Tests\Unit\TestCase;

/**
 * Tests for Arr.
 *
 * @covers \StoreAudit\Support\Arr
 */
final class ArrTest extends TestCase {

	/**
	 * Strings pass through, scalars convert, everything else falls back.
	 */
	public function test_to_string(): void {
		$this->assertSame( 'a', Arr::to_string( 'a' ) );
		$this->assertSame( '5', Arr::to_string( 5 ) );
		$this->assertSame( '1.5', Arr::to_string( 1.5 ) );
		$this->assertSame( '', Arr::to_string( array( 'x' ) ) );
		$this->assertSame( '', Arr::to_string( null ) );
		$this->assertSame( 'd', Arr::to_string( new \stdClass(), 'd' ) );
	}

	/**
	 * Integers follow PHP string conversion; arrays and null fall back.
	 */
	public function test_to_int(): void {
		$this->assertSame( 7, Arr::to_int( 7 ) );
		$this->assertSame( 7, Arr::to_int( '7' ) );
		$this->assertSame( 7, Arr::to_int( 7.9 ) );
		$this->assertSame( 0, Arr::to_int( 'abc' ) );
		$this->assertSame( 12, Arr::to_int( '12abc' ) );
		$this->assertSame( 3, Arr::to_int( array(), 3 ) );
		$this->assertSame( 0, Arr::to_int( null ) );
	}

	/**
	 * Floats accept numbers and numeric strings.
	 */
	public function test_to_float(): void {
		$this->assertSame( 2.5, Arr::to_float( '2.5' ) );
		$this->assertSame( 2.0, Arr::to_float( 2 ) );
		$this->assertSame( 1.0, Arr::to_float( array(), 1.0 ) );
	}

	/**
	 * Lists keep only strings.
	 */
	public function test_to_string_list(): void {
		$this->assertSame( array( 'a', 'b' ), Arr::to_string_list( array( 'a', 1, 'b', array() ) ) );
		$this->assertSame( array(), Arr::to_string_list( 'a' ) );
	}

	/**
	 * Keyed readers handle missing keys and wrong types.
	 */
	public function test_getters(): void {
		$data = array(
			's' => 'text',
			'i' => '12',
			'a' => array( 1 ),
			'b' => 'yes',
			'z' => '0',
			'n' => null,
			'f' => '1.25',
		);
		$this->assertSame( 'text', Arr::get_string( $data, 's' ) );
		$this->assertSame( 'z', Arr::get_string( $data, 'missing', 'z' ) );
		$this->assertSame( '', Arr::get_string( $data, 'a' ) );
		$this->assertSame( 12, Arr::get_int( $data, 'i' ) );
		$this->assertSame( 0, Arr::get_int( $data, 'n' ) );
		$this->assertSame( 1.25, Arr::get_float( $data, 'f' ) );
		$this->assertSame( array( 1 ), Arr::get_array( $data, 'a' ) );
		$this->assertSame( array(), Arr::get_array( $data, 's' ) );
		$this->assertTrue( Arr::get_bool( $data, 'b' ) );
		$this->assertFalse( Arr::get_bool( $data, 'n' ) );
		$this->assertFalse( Arr::get_bool( $data, 'z' ) );
		$this->assertFalse( Arr::get_bool( $data, 'missing' ) );
	}
}
