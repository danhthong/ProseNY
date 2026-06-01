<?php
/**
 * JsonLogic unit tests.
 *
 * @package ProseCore
 */

namespace Prose\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prose\Core\Rules\JsonLogic;

final class JsonLogicTest extends TestCase {

	public function test_equality(): void {
		$logic = new JsonLogic();
		$data  = array( 'case' => array( 'county' => 'Queens' ) );

		$result = $logic->apply(
			array( '==' => array( array( 'var' => 'case.county' ), 'Queens' ) ),
			$data
		);

		$this->assertTrue( $result );
	}

	public function test_all_conditions(): void {
		$logic = new JsonLogic();
		$data  = array(
			'case' => array(
				'contested' => true,
				'children'  => true,
			),
		);

		$result = $logic->apply(
			array(
				'all' => array(
					array( '==' => array( array( 'var' => 'case.contested' ), true ) ),
					array( '==' => array( array( 'var' => 'case.children' ), true ) ),
				),
			),
			$data
		);

		$this->assertTrue( $result );
	}
}
