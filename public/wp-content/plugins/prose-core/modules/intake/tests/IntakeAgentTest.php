<?php
/**
 * Intake Agent end-to-end tests (deterministic, no LLM).
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Intake_Agent;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class IntakeAgentTest
 */
class IntakeAgentTest extends TestCase {

	/**
	 * Intake agent.
	 *
	 * @var Intake_Agent
	 */
	private Intake_Agent $agent;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		$this->agent   = new Intake_Agent();
		$this->catalog = new Workflow_Catalog();
	}

	/**
	 * Resolving phrases for each of the 11 validation workflows.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function workflow_provider(): array {
		return array(
			'uncontested_no_children' => array(
				'We both agree to divorce without children.',
				'uncontested_divorce_no_children_nyc',
				array( 'facts' => array( 'children' => false, 'spouse_agrees' => true ) ),
			),
			'uncontested_with_children' => array(
				'I want a divorce and we have two children.',
				'uncontested_divorce_children_nyc',
				array(),
			),
			'contested' => array(
				'My spouse will not agree to the divorce.',
				'contested_divorce_nyc',
				array( 'facts' => array( 'children' => true ) ),
			),
			'default_divorce' => array(
				'My spouse did not respond to the divorce papers.',
				'default_divorce_nyc',
				array( 'facts' => array( 'spouse_responded' => false ) ),
			),
			'custody' => array(
				'I want custody of my son.',
				'custody_nyc',
				array(),
			),
			'visitation' => array(
				'I want visitation with my child.',
				'visitation_nyc',
				array(),
			),
			'child_support' => array(
				'My ex is not paying child support.',
				'child_support_nyc',
				array(),
			),
			'family_offense' => array(
				'My husband abused me.',
				'family_offense_nyc',
				array(),
			),
			'order_of_protection' => array(
				'I need an order of protection.',
				'order_of_protection_nyc',
				array(),
			),
			'paternity' => array(
				'I need to establish paternity.',
				'paternity_nyc',
				array(),
			),
			'guardianship' => array(
				'I want to become the legal guardian of my niece.',
				'guardianship_nyc',
				array(),
			),
			'adoption' => array(
				'I want to adopt a child.',
				'adoption_nyc',
				array(),
			),
		);
	}

	/**
	 * Each workflow resolves and asks a workflow-defined next question.
	 *
	 * @dataProvider workflow_provider
	 *
	 * @param string               $message      Message.
	 * @param string               $expected     Expected workflow.
	 * @param array<string, mixed> $case_profile Prior profile.
	 */
	public function test_workflow_resolves_and_asks_metadata_question( string $message, string $expected, array $case_profile ): void {
		$result = $this->agent->process( $message, $case_profile );

		$this->assertSame( $expected, $result['workflow'], 'Workflow should resolve.' );
		$this->assertIsArray( $result['missing_fields'] );
		$this->assertNotEmpty( $result['missing_fields'], 'Fresh intake should have missing required fields.' );

		// Next question must come from workflow metadata for the first missing field.
		$expected_question = $this->question_for( $expected, $result['missing_fields'][0] );
		$this->assertSame( $expected_question, $result['next_question'], 'Next question must be workflow-defined.' );

		$this->assertGreaterThanOrEqual( 0, $result['completion'] );
		$this->assertLessThanOrEqual( 100, $result['completion'] );
	}

	/**
	 * Success criteria: divorce + two children.
	 */
	public function test_success_criteria_divorce_with_two_children(): void {
		$result = $this->agent->process( 'I want a divorce and we have two children.' );

		$this->assertSame( 'uncontested_divorce_children_nyc', $result['workflow'] );
		$this->assertSame( 2, $result['facts_extracted']['child_count'] );
		$this->assertTrue( $result['facts_extracted']['has_minor_children'] );
		$this->assertContains( 'county', $result['missing_fields'] );
		$this->assertSame( 'In which NYC county are you filing?', $result['next_question'] );
	}

	/**
	 * County extraction maps a borough to its official county.
	 */
	public function test_county_extracted_from_borough(): void {
		$result = $this->agent->process( 'I live in Brooklyn.' );

		$this->assertSame( 'Kings', $result['facts_extracted']['county'] );
	}

	/**
	 * Spouse agreement is captured and merged into the profile.
	 */
	public function test_spouse_agreement_merged(): void {
		$result = $this->agent->process( 'My spouse agrees.' );

		$this->assertTrue( $result['case_profile']['facts']['spouse_agrees'] );
	}

	/**
	 * Null/empty values never overwrite populated facts.
	 */
	public function test_null_safe_merge_preserves_existing_facts(): void {
		$result = $this->agent->process(
			'I live in Queens.',
			array( 'facts' => array( 'children' => true, 'child_count' => 2 ) )
		);

		$this->assertTrue( $result['case_profile']['facts']['children'] );
		$this->assertSame( 2, $result['case_profile']['facts']['child_count'] );
		$this->assertSame( 'Queens', $result['case_profile']['facts']['county'] );
	}

	/**
	 * Later values override earlier values across turns.
	 */
	public function test_later_values_override_earlier(): void {
		$result = $this->agent->process(
			'Actually we have three children.',
			array( 'facts' => array( 'child_count' => 2 ) )
		);

		$this->assertSame( 3, $result['case_profile']['facts']['child_count'] );
	}

	/**
	 * Completion grows as required fields are filled.
	 */
	public function test_completion_increases_with_facts(): void {
		$first = $this->agent->process( 'I want a divorce and we have two children.' );

		// Carry the resolved profile forward and fill every required field.
		$profile          = $first['case_profile'];
		$profile['facts'] = array_merge(
			is_array( $profile['facts'] ?? null ) ? $profile['facts'] : array(),
			array(
				'county'                    => 'Kings',
				'marriage_location'         => 'Brooklyn, NY',
				'residency_qualification'   => '1_year_state',
				'marriage_date'             => '2010-06-05',
				'separation_date'           => '2020-01-01',
				'grounds_for_divorce'       => 'irretrievable breakdown',
				'plaintiff_information'     => 'Jane Doe',
				'defendant_information'     => 'John Doe',
				'has_minor_children'        => true,
				'child_count'               => 2,
				'child_names'               => array( 'A', 'B' ),
				'child_birth_dates'         => array( '2012-01-01', '2014-01-01' ),
				'custody_arrangement'       => 'joint',
				'child_support_terms'       => 'agreed',
				'marital_property_resolved' => true,
			)
		);

		$second = $this->agent->process( 'Here are the details.', $profile );

		$this->assertGreaterThan( $first['completion'], $second['completion'] );
		$this->assertSame( 100, $second['completion'] );
		$this->assertSame( 'complete_intake', $second['next_action'] );
		$this->assertNotEmpty( $second['next_question'], 'Completion guidance when intake complete.' );
		$this->assertStringContainsString( 'Uncontested Divorce Children', $second['next_question'] );
		$this->assertSame( array(), $second['missing_fields'] );
	}

	/**
	 * A new session receives a UUID conversation id.
	 */
	public function test_new_session_receives_uuid(): void {
		$result = $this->agent->process( 'I want a divorce.' );

		$this->assertArrayHasKey( 'conversation_id', $result );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$result['conversation_id']
		);
		$this->assertSame( $result['conversation_id'], $result['case_profile']['conversation_id'] );
	}

	/**
	 * The conversation id is preserved and returned across multiple turns.
	 */
	public function test_conversation_id_stable_across_turns(): void {
		$turn1 = $this->agent->process( 'I want a divorce and we have two children.' );
		$id    = $turn1['conversation_id'];

		$this->assertNotSame( '', $id );

		$turn2 = $this->agent->process( 'I live in Brooklyn.', $turn1['case_profile'] );
		$this->assertSame( $id, $turn2['conversation_id'] );

		$turn3 = $this->agent->process( 'My grounds are irretrievable breakdown.', $turn2['case_profile'] );
		$this->assertSame( $id, $turn3['conversation_id'] );
	}

	/**
	 * Multi-turn intake answers the pending question and advances.
	 */
	public function test_pending_field_answer_advances_intake(): void {
		$turn1 = $this->agent->process( 'I want a divorce and we have two children.' );
		$this->assertSame( 'county', $turn1['case_profile']['pending_field'] );

		$turn2 = $this->agent->process( 'Brooklyn', $turn1['case_profile'] );
		$this->assertSame( 'Kings', $turn2['case_profile']['facts']['county'] );
		$this->assertNotContains( 'county', $turn2['missing_fields'] );
	}

	/**
	 * Divorce intake must not complete after only the children routing answer.
	 */
	public function test_divorce_does_not_complete_after_children_only(): void {
		$turn1 = $this->agent->process( 'I want divorce' );
		$this->assertSame( 'Do you have any children under 21?', $turn1['next_question'] );
		$this->assertSame( '', (string) ( $turn1['workflow'] ?? '' ) );

		$turn2 = $this->agent->process( 'No', $turn1['case_profile'] );

		$this->assertSame( 'uncontested_divorce_no_children_nyc', $turn2['workflow'] );
		$this->assertNotEmpty( $turn2['missing_fields'], 'Workflow required fields should remain.' );
		$this->assertNotSame( '', $turn2['next_question'], 'Intake must continue after children answer.' );
		$this->assertLessThan( 100, $turn2['completion'] );
		$this->assertSame( 'In which NYC county are you filing?', $turn2['next_question'] );
		$this->assertFalse( $turn2['case_profile']['facts']['has_minor_children'] );
	}

	/**
	 * Custody intake skips child detail questions when there are no children.
	 */
	public function test_custody_skips_child_details_when_no_children(): void {
		$turn1 = $this->agent->process( 'Help me with child custody' );
		$this->assertSame( 'custody_nyc', $turn1['workflow'] );
		$this->assertSame( 'In which NYC county are you filing?', $turn1['next_question'] );

		$turn2 = $this->agent->process( 'Queen', $turn1['case_profile'] );
		$this->assertSame( 'Queens', $turn2['case_profile']['facts']['county'] );
		$this->assertSame( 'How many children are involved?', $turn2['next_question'] );

		$turn3 = $this->agent->process( 'No', $turn2['case_profile'] );
		$this->assertSame( 0, $turn3['case_profile']['facts']['child_count'] );
		$this->assertNotContains( 'child_names', $turn3['missing_fields'] );
		$this->assertNotContains( 'child_birth_dates', $turn3['missing_fields'] );
		$this->assertSame(
			'What is your full legal name and contact information?',
			$turn3['next_question']
		);

		$turn4 = $this->agent->process( 'I mean no children', $turn3['case_profile'] );
		$this->assertSame( 0, $turn4['case_profile']['facts']['child_count'] );
		$this->assertNotContains( 'child_names', $turn4['missing_fields'] );
		$this->assertNotContains( 'child_birth_dates', $turn4['missing_fields'] );
	}

	/**
	 * Explicit new-matter request resets a completed divorce session.
	 */
	public function test_matter_switch_from_completed_divorce_to_custody(): void {
		$profile = array(
			'workflow'                  => 'uncontested_divorce_children_nyc',
			'issue'                     => 'divorce',
			'intake_complete_announced' => true,
			'facts'                     => array(
				'county'                    => 'Queens',
				'active_divorce'            => true,
				'marriage_date'             => '2010-01-01',
				'separation_date'           => '2024-01-01',
				'grounds_for_divorce'       => 'irretrievable breakdown',
				'plaintiff_information'     => 'Jane Doe',
				'defendant_information'     => 'John Doe',
				'has_minor_children'        => true,
				'child_count'               => 1,
				'child_names'               => 'Sam Doe',
				'child_birth_dates'         => '2018-01-01',
				'custody_arrangement'       => 'joint',
				'child_support_terms'       => 'agreed',
				'marital_property_resolved' => true,
			),
		);

		$result = $this->agent->process( 'Help me with child custody', $profile );

		$this->assertSame( 'custody_nyc', $result['workflow'] );
		$this->assertLessThan( 100, $result['completion'] );
		$this->assertNotSame( 'intake_complete', $result['intent'] ?? '' );
	}

	/**
	 * Bulk intake message captures marriage year from natural phrasing.
	 */
	public function test_bulk_message_extracts_married_year(): void {
		$result = $this->agent->process(
			'Resident 5 years in Brooklyn; married 2016; one child; agreement on all issues.'
		);

		$this->assertSame( '2016-01-01', $result['case_profile']['facts']['marriage_date'] ?? null );
	}

	/**
	 * DD/MM/YYYY answer fills pending marriage_date.
	 */
	public function test_pending_marriage_date_accepts_dd_mm_yyyy(): void {
		$turn1 = $this->agent->process( 'I want a divorce and we have one child.' );
		$turn2 = $this->agent->process( 'Brooklyn', $turn1['case_profile'] );

		$profile = $turn2['case_profile'];
		$profile['pending_field'] = 'marriage_date';

		$turn3 = $this->agent->process( '21/12/2016', $profile );

		$this->assertSame( '2016-12-21', $turn3['case_profile']['facts']['marriage_date'] ?? null );
		$this->assertNotContains( 'marriage_date', $turn3['missing_fields'] );
	}

	/**
	 * Queens answer to pending marriage_location does not overwrite filing county.
	 */
	public function test_pending_marriage_location_accepts_borough(): void {
		$profile = array(
			'workflow'      => 'uncontested_divorce_children_nyc',
			'pending_field' => 'marriage_location',
			'facts'         => array(
				'county'        => 'Kings',
				'marriage_date' => '2016-12-21',
				'child_count'   => 1,
				'spouse_agrees' => true,
			),
		);

		$result = $this->agent->process( 'queens', $profile );

		$this->assertSame( 'Queens, NY', $result['case_profile']['facts']['marriage_location'] ?? null );
		$this->assertSame( 'Kings', $result['case_profile']['facts']['county'] ?? null );
		$this->assertNotContains( 'marriage_location', $result['missing_fields'] );
	}

	/**
	 * Ambiguous openers still receive a clarifying question (never blank).
	 */
	public function test_ambiguous_opener_returns_clarifying_question(): void {
		$result = $this->agent->process( 'We own two houses.' );

		$this->assertNotSame( '', trim( (string) $result['next_question'] ) );
	}

	/**
	 * Spouse refusal routes to contested divorce after children are answered.
	 */
	public function test_contested_divorce_when_spouse_refuses(): void {
		$turn1 = $this->agent->process( 'I want a divorce but my wife refuses.' );
		$turn2 = $this->agent->process( 'No children.', $turn1['case_profile'] );

		$this->assertSame( 'contested_divorce_nyc', $turn2['workflow'] );
	}

	/**
	 * Residency duration must not be mistaken for a child count.
	 */
	public function test_residency_months_not_parsed_as_children(): void {
		$turn1 = $this->agent->process( 'I want a divorce.' );
		$turn2 = $this->agent->process( 'I have only lived here 2 months.', $turn1['case_profile'] );

		$this->assertSame( 'ineligible', $turn2['case_profile']['facts']['residency_qualification'] ?? null );
		$this->assertNotTrue( $turn2['case_profile']['facts']['has_minor_children'] ?? null );
	}

	/**
	 * Default divorce opening resolves workflow.
	 */
	public function test_default_divorce_never_responded_opener(): void {
		$result = $this->agent->process( 'My spouse never responded.' );

		$this->assertSame( 'default_divorce_nyc', $result['workflow'] );
		$this->assertNotSame( '', trim( (string) $result['next_question'] ) );
	}

	/**
	 * Every response carries a conversation id.
	 */
	public function test_every_response_has_conversation_id(): void {
		foreach ( self::workflow_provider() as $row ) {
			$result = $this->agent->process( $row[0], $row[2] );
			$this->assertNotSame( '', (string) $result['conversation_id'] );
		}
	}

	/**
	 * Look up the question for a field key within a workflow definition.
	 *
	 * @param string $workflow Workflow key.
	 * @param string $field    Field key.
	 * @return string
	 */
	private function question_for( string $workflow, string $field ): string {
		$definition = $this->catalog->by_key( $workflow );
		$fields     = is_array( $definition['required_fields'] ?? null ) ? $definition['required_fields'] : array();

		foreach ( $fields as $entry ) {
			if ( (string) ( $entry['key'] ?? '' ) === $field ) {
				return (string) ( $entry['question'] ?? '' );
			}
		}

		return '';
	}
}
