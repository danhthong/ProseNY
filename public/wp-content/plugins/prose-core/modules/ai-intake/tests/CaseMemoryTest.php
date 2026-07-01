<?php
/**
 * Tests for Case_Memory.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\Case_Memory;
use ProSe\Core\Ai_Intake\Intake_State;

/**
 * Class CaseMemoryTest
 */
class CaseMemoryTest extends TestCase {

	/**
	 * Case memory exposes semantic missing topics, not scripted questions.
	 */
	public function test_builds_conversation_safe_missing_information(): void {
		$state = Intake_State::from_array(
			array(
				'facts' => array(
					'issue' => array(
						'value'      => 'divorce',
						'confidence' => 0.95,
					),
				),
			)
		);

		$memory = Case_Memory::build(
			$state,
			array(
				'all'          => array(
					array(
						'field'    => 'children',
						'priority' => 95,
						'type'     => 'boolean',
					),
				),
				'conversation' => array(
					array(
						'field' => 'children',
						'key'   => 'children',
						'topic' => 'whether you have any children under 21',
						'type'  => 'boolean',
					),
				),
				'resolved'     => array(),
				'completion'   => 10,
			),
			array( 'forms_visible' => false )
		);

		$this->assertSame( 'gathering', $memory['routing_status'] );
		$this->assertArrayHasKey( 'missing_information', $memory );
		$this->assertSame(
			'whether you have any children under 21',
			$memory['missing_information'][0]['topic']
		);
		$this->assertArrayNotHasKey( 'question', $memory['missing_information'][0] );
	}
}
