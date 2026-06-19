<?php
/**
 * CourtFlow response mapper tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Rest\Courtflow_Response_Mapper;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class CourtflowResponseMapperTest
 */
class CourtflowResponseMapperTest extends TestCase {

	/**
	 * Mapper under test.
	 *
	 * @var Courtflow_Response_Mapper
	 */
	private Courtflow_Response_Mapper $mapper;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->mapper = new Courtflow_Response_Mapper();
	}

	/**
	 * Empty session exposes baseline workspace state.
	 */
	public function test_empty_session_state_shape(): void {
		$state = $this->mapper->map_session_state(
			array(
				'session_id'   => 'test-session',
				'case_profile' => array( 'facts' => array() ),
			)
		);

		$this->assertArrayHasKey( 'facts', $state );
		$this->assertArrayHasKey( 'case', $state['facts'] );
		$this->assertArrayHasKey( 'requirements', $state );
		$this->assertSame( 0, $state['requirements']['completeness'] );
		$this->assertFalse( $state['requirements']['ready_to_generate'] );
		$this->assertArrayHasKey( 'current_node', $state );
		$this->assertSame( 'intake_basics', $state['current_node']['slug'] );
	}

	/**
	 * Message response includes assistant text and captured facts.
	 */
	public function test_message_response_maps_reply_and_captured_facts(): void {
		$session = array(
			'session_id'   => 'test-session',
			'case_profile' => array(
				'facts'    => array(),
				'workflow' => 'uncontested_divorce_children_nyc',
				'progress' => 40,
			),
			'last_interpret' => array(
				'completion' => 40,
				'workflow'   => 'uncontested_divorce_children_nyc',
				'question'   => 'In which NYC county are you filing?',
				'state'      => array(
					'facts' => array(
						'county' => array(
							'value'      => 'Queens',
							'confidence' => 0.95,
							'confirmed'  => true,
						),
					),
				),
			),
		);

		$response = $this->mapper->map_message_response(
			$session,
			array(
				'success' => true,
				'result'  => $session['last_interpret'],
			),
			array(
				'county' => array(
					'value'      => 'Queens',
					'confidence' => 0.95,
				),
			)
		);

		$this->assertSame( 'In which NYC county are you filing?', $response['message'] );
		$this->assertNotEmpty( $response['newly_captured'] );
		$this->assertSame( 'case.county', $response['newly_captured'][0]['path'] );
		$this->assertSame( 'Queens', $response['newly_captured'][0]['value'] );
		$this->assertSame( 40, $response['requirements']['completeness'] );
		$this->assertNotEmpty( $response['required_forms'] );
	}
}
