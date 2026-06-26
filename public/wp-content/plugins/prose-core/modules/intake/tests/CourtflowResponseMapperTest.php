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
	 * Session state hydrates persisted roadmap.
	 */
	public function test_session_state_includes_persisted_roadmap(): void {
		$roadmap = array(
			'show'          => true,
			'mode'          => 'intake',
			'current_stage' => array(
				'id'    => 'intake_divorce',
				'label' => 'Initial Divorce Intake',
			),
		);

		$state = $this->mapper->map_session_state(
			array(
				'session_id'   => 'test-session',
				'case_profile' => array(
					'facts'   => array( 'issue' => 'divorce' ),
					'roadmap' => $roadmap,
				),
			)
		);

		$this->assertArrayHasKey( 'roadmap', $state );
		$this->assertTrue( $state['roadmap']['show'] );
		$this->assertFalse( $state['roadmap_changed'] );
	}

	/**
	 * Message response emits roadmap only when it changed.
	 */
	public function test_message_response_emits_roadmap_when_changed(): void {
		$roadmap = array(
			'show'          => true,
			'mode'          => 'intake',
			'current_stage' => array( 'label' => 'Initial Divorce Intake' ),
		);

		$session = array(
			'session_id'   => 'test-session',
			'case_profile' => array(
				'facts'    => array( 'issue' => 'divorce' ),
				'workflow' => '',
				'progress' => 10,
			),
		);

		$changed = $this->mapper->map_message_response(
			$session,
			array(
				'success' => true,
				'result'  => array(
					'completion'      => 10,
					'question'        => 'Thanks for sharing.',
					'roadmap_changed' => true,
					'roadmap'         => $roadmap,
				),
			)
		);

		$this->assertTrue( $changed['roadmap_changed'] );
		$this->assertSame( $roadmap, $changed['roadmap'] );

		$unchanged = $this->mapper->map_message_response(
			$session,
			array(
				'success' => true,
				'result'  => array(
					'completion'      => 10,
					'question'        => 'Tell me more.',
					'roadmap_changed' => false,
				),
			)
		);

		$this->assertFalse( $unchanged['roadmap_changed'] );
		$this->assertArrayNotHasKey( 'roadmap', $unchanged );
	}

	/**
	 * Completed intake procedural questions no longer return chat cards.
	 */
	public function test_procedural_question_does_not_return_chat_card(): void {
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
				'intake_complete'   => true,
				'workflow_resolved' => true,
				'workflow'          => 'uncontested_divorce_no_children_nyc',
				'issue'             => 'divorce',
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

		$this->assertArrayNotHasKey( 'card', $response );
		$this->assertNotEmpty( $response['next_steps'] );
		$this->assertTrue( $response['stage_context']['forms_visible'] );
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

	/**
	 * Divorce session state includes lifecycle and matter map payloads.
	 */
	public function test_divorce_session_includes_lifecycle_and_matter_map(): void {
		$session = array(
			'session_id'   => 'test-session',
			'case_profile' => array(
				'facts'    => array(
					'issue'              => 'divorce',
					'county'             => 'Queens',
					'has_minor_children' => true,
					'custody_dispute'    => true,
				),
				'workflow' => 'uncontested_divorce_children_nyc',
				'progress' => 100,
			),
			'last_interpret' => array(
				'completion' => 100,
				'workflow'   => 'uncontested_divorce_children_nyc',
			),
			'actions' => array(
				'intake_complete' => true,
			),
		);

		$fields = $this->mapper->map_lifecycle_fields( $session );

		$this->assertArrayHasKey( 'lifecycle', $fields );
		$this->assertArrayHasKey( 'matter_map', $fields );
		$this->assertTrue( $fields['lifecycle']['show'] );
		$this->assertTrue( $fields['matter_map']['show'] );
		$this->assertGreaterThan( 1, count( $fields['matter_map']['tracks'] ) );
	}
}
