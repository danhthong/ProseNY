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
		$this->assertArrayHasKey( 'stage_context', $response );
		$this->assertTrue( $response['stage_context']['forms_visible'], 'Forms unlock once routing resolves the workflow.' );
	}

	/**
	 * Overlap routing is exposed to the workspace context panel.
	 */
	public function test_overlap_court_routing_in_context(): void {
		$session = array(
			'session_id'   => 'test-session',
			'case_profile' => array(
				'facts'               => array(),
				'workflow'            => 'uncontested_divorce_no_children_nyc',
				'court'               => 'supreme_court',
				'courts'              => array( 'supreme_court', 'family_court' ),
				'overlap'             => true,
				'overlap_reason'      => 'divorce_and_order_of_protection',
				'routing_explanation' => 'Both courts may apply.',
				'progress'            => 20,
			),
		);

		$state = $this->mapper->map_session_state( $session );

		$this->assertArrayHasKey( 'court_routing', $state );
		$this->assertTrue( $state['court_routing']['overlap'] );
		$this->assertContains( 'family_court', $state['court_routing']['courts'] );
	}

	/**
	 * Completed intake with procedural question returns a procedure card.
	 */
	public function test_procedural_card_after_intake_complete(): void {
		$session = array(
			'session_id'   => 'test-session',
			'case_profile' => array(
				'facts'    => array(
					'county'        => 'Queens',
					'children'      => false,
					'spouse_agrees' => true,
				),
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'progress' => 100,
			),
			'actions' => array(
				'intake_complete'    => true,
				'workflow_resolved'  => true,
				'workflow'           => 'uncontested_divorce_no_children_nyc',
				'issue'              => 'divorce',
			),
			'last_interpret' => array(
				'completion' => 100,
				'workflow'   => 'uncontested_divorce_no_children_nyc',
			),
		);

		$response = $this->mapper->map_message_response(
			$session,
			array(
				'success' => true,
				'result'  => $session['last_interpret'],
			),
			array(),
			'What happens next after I file?'
		);

		$this->assertArrayHasKey( 'card', $response );
		$this->assertSame( 'procedure', $response['card']['type'] );
		$this->assertNotEmpty( $response['next_steps'] );
		$this->assertTrue( $response['stage_context']['forms_visible'] );
		$this->assertSame( 'commencement', $response['stage_context']['current_stage']['id'] );
	}

	/**
	 * Required forms appear once routing resolves the workflow, even mid-intake.
	 */
	public function test_required_forms_visible_during_personal_intake(): void {
		$session = array(
			'session_id'   => 'test-session',
			'case_profile' => array(
				'facts'    => array(
					'spouse_agrees' => true,
					'children'      => false,
				),
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'progress' => 50,
			),
			'last_interpret' => array(
				'completion'     => 50,
				'workflow'       => 'uncontested_divorce_no_children_nyc',
				'missing_fields' => array( 'spouse_name', 'marriage_date' ),
			),
		);

		$state = $this->mapper->map_session_state( $session );

		$this->assertNotEmpty( $state['required_forms'] );
		$this->assertTrue( $state['stage_context']['forms_visible'] );
		$this->assertContains( 'UD-1', $state['required_forms'] );
	}
}
