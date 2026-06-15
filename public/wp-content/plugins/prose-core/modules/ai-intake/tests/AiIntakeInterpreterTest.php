<?php
/**
 * AI Intake Interpreter tests (offline stub provider).
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\AI_Settings;
use ProSe\Core\Ai_Intake\Escalation_Detector;
use ProSe\Core\Ai_Intake\Intake_State;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class AiIntakeInterpreterTest
 */
class AiIntakeInterpreterTest extends TestCase {

	/**
	 * Stub provider.
	 *
	 * @var Stub_Ai_Provider
	 */
	private Stub_Ai_Provider $provider;

	/**
	 * Interpreter.
	 *
	 * @var AI_Intake_Interpreter
	 */
	private AI_Intake_Interpreter $interpreter;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		AI_Settings::clear_cache();
		$GLOBALS['prose_test_options'] = array();

		$this->provider    = new Stub_Ai_Provider();
		$this->interpreter = new AI_Intake_Interpreter( $this->provider );
	}

	/**
	 * Divorce intake extracts issue and asks next question.
	 */
	public function test_divorce_intake(): void {
		$result = $this->interpreter->interpret( 'I need a divorce.' );

		$this->assertArrayHasKey( 'fact_updates', $result );
		$this->assertSame( 'ask_question', $result['next_action'] );
		$this->assertNotEmpty( $result['question'] );
	}

	/**
	 * Bulk extraction from a single message.
	 */
	public function test_bulk_fact_extraction(): void {
		$result = $this->interpreter->interpret(
			'I live in Queens, my spouse agrees to the divorce, and we have two children.'
		);

		$facts = $result['state']['facts'] ?? array();

		$this->assertArrayHasKey( 'county', $facts );
		$this->assertSame( 'Queens', $facts['county']['value'] );
		$this->assertArrayHasKey( 'spouse_agrees', $facts );
		$this->assertTrue( $facts['spouse_agrees']['value'] );
		$this->assertArrayHasKey( 'child_count', $facts );
		$this->assertSame( 2, $facts['child_count']['value'] );
	}

	/**
	 * Pending field helps interpret short county answer.
	 */
	public function test_pending_field_county_short_answer(): void {
		$state = array(
			'pending_field' => 'county',
			'facts'         => array(),
		);

		$result = $this->interpreter->interpret( 'queens', $state );

		$facts = $result['state']['facts'] ?? array();
		$this->assertArrayHasKey( 'county', $facts );
		$this->assertSame( 'Queens', $facts['county']['value'] );
	}

	/**
	 * Pending field helps interpret short numeric answer.
	 */
	public function test_pending_field_child_count_short_answer(): void {
		$state = array(
			'pending_field' => 'child_count',
			'facts'         => array(),
		);

		$result = $this->interpreter->interpret( 'two', $state );

		$facts = $result['state']['facts'] ?? array();
		$this->assertArrayHasKey( 'child_count', $facts );
		$this->assertSame( 2, $facts['child_count']['value'] );
	}

	/**
	 * Short contextual answer keeps the conversation going naturally.
	 */
	public function test_short_answer_continues_conversation(): void {
		$state = array(
			'pending_field' => 'child_count',
			'facts'         => array(),
		);

		$result = $this->interpreter->interpret( 'no', $state );

		$this->assertNotEmpty( $result['question'] );
		$this->assertArrayHasKey( 'next_action', $result );
	}

	/**
	 * Contradictory custody facts are flagged.
	 */
	public function test_contradictory_custody_zero_children(): void {
		$state = Intake_State::from_array(
			array(
				'facts' => array(
					'issue'       => array( 'value' => 'custody', 'confidence' => 0.95, 'confirmed' => true ),
					'child_count' => array( 'value' => 0, 'confidence' => 0.95, 'confirmed' => true ),
				),
			)
		);

		$checker = new \ProSe\Core\Ai_Intake\Consistency_Checker();
		$items   = $checker->check( $state );

		$this->assertNotEmpty( $items );
		$this->assertSame( 'child_count', $items[0]['field'] );
	}

	/**
	 * Priority-ordered missing fields prefer county.
	 */
	public function test_required_field_priority(): void {
		$provider = new \ProSe\Core\Ai_Intake\Required_Fields_Provider();
		$state    = Intake_State::from_array(
			array(
				'workflow' => 'uncontested_divorce_children_nyc',
				'facts'    => array(),
			)
		);

		$resolved = $provider->resolve( $state, 'divorce' );
		$missing  = $provider->missing_prioritized( $resolved['fields'], $state );

		$this->assertNotEmpty( $missing );
		$this->assertSame( 'county', $missing[0]['field'] );
	}

	/**
	 * Escalation after repeated uncertainty.
	 */
	public function test_escalation_needs_review(): void {
		$state = Intake_State::from_array(
			array(
				'pending_field'          => 'county',
				'clarification_attempts' => array( 'county' => 2 ),
			)
		);

		$detector = new Escalation_Detector();
		$result   = $detector->detect( "I don't know", $state, 0.3 );

		$this->assertTrue( $result['needs_review'] );
	}

	/**
	 * System prompt override mode.
	 */
	public function test_system_prompt_override(): void {
		$settings = new AI_Settings();
		$settings->update(
			array(
				'system_prompt'      => 'Custom only prompt.',
				'system_prompt_mode' => 'override',
			)
		);

		$this->assertSame( 'Custom only prompt.', $settings->system_prompt() );
	}

	/**
	 * System prompt append mode.
	 */
	public function test_system_prompt_append(): void {
		$settings = new AI_Settings();
		$settings->update(
			array(
				'system_prompt'      => 'Extra rule.',
				'system_prompt_mode' => 'append',
			)
		);

		$this->assertStringContainsString( AI_Settings::DEFAULT_SYSTEM_PROMPT, $settings->system_prompt() );
		$this->assertStringContainsString( 'Extra rule.', $settings->system_prompt() );
	}

	/**
	 * Intake state never overwrites confirmed facts.
	 */
	public function test_confirmed_facts_not_overwritten(): void {
		$state = Intake_State::from_array(
			array(
				'facts' => array(
					'county' => array(
						'value'      => 'Queens',
						'confidence' => 1.0,
						'confirmed'  => true,
					),
				),
			)
		);

		$applied = $state->merge_updates(
			array(
				'county' => array(
					'value'      => 'Kings',
					'confidence' => 0.99,
				),
			)
		);

		$this->assertEmpty( $applied );
		$this->assertSame( 'Queens', $state->plain_facts()['county'] );
	}

	/**
	 * Conversation summary fallback is built from facts.
	 */
	public function test_conversation_summary_fallback(): void {
		$state  = Intake_State::from_array(
			array(
				'facts' => array(
					'county' => array( 'value' => 'Queens', 'confidence' => 0.95, 'confirmed' => true ),
				),
			)
		);
		$memory = new \ProSe\Core\Ai_Intake\Conversation_Memory();
		$summary = $memory->fallback_summary( $state );

		$this->assertStringContainsString( 'county: Queens', $summary );
	}

	/**
	 * Uncontested divorce with children progresses intake.
	 */
	public function test_uncontested_divorce_with_children(): void {
		$result = $this->interpreter->interpret(
			'We both agree to divorce and we have two children in Queens.',
			array(),
			array()
		);

		$facts = $result['state']['facts'] ?? array();
		$this->assertArrayHasKey( 'spouse_agrees', $facts );
		$this->assertArrayHasKey( 'child_count', $facts );
		$this->assertArrayHasKey( 'county', $facts );
		$this->assertSame( 'ask_question', $result['next_action'] );
	}

	/**
	 * Uncertain/low-confidence input still yields a natural reply (no dead end).
	 */
	public function test_low_confidence_still_replies(): void {
		$result = $this->interpreter->interpret(
			'Queens maybe',
			array( 'pending_field' => 'county' )
		);

		$this->assertNotEmpty( $result['question'] );
		$this->assertNotSame( 'needs_review', $result['next_action'] );
	}

	/**
	 * Complete intake when all required fields are filled.
	 */
	public function test_intake_completion(): void {
		$catalog  = new Workflow_Catalog();
		$workflow = $catalog->by_key( 'uncontested_divorce_no_children_nyc' );
		$this->assertIsArray( $workflow );

		$facts = array(
			'county'                    => array( 'value' => 'Queens', 'confidence' => 0.99, 'confirmed' => true ),
			'marriage_date'             => array( 'value' => '2010-01-01', 'confidence' => 0.99, 'confirmed' => true ),
			'separation_date'           => array( 'value' => '2024-01-01', 'confidence' => 0.99, 'confirmed' => true ),
			'grounds_for_divorce'       => array( 'value' => 'irretrievable breakdown', 'confidence' => 0.99, 'confirmed' => true ),
			'plaintiff_information'     => array( 'value' => 'Jane Doe', 'confidence' => 0.99, 'confirmed' => true ),
			'defendant_information'     => array( 'value' => 'John Doe', 'confidence' => 0.99, 'confirmed' => true ),
			'has_minor_children'        => array( 'value' => false, 'confidence' => 0.99, 'confirmed' => true ),
			'marital_property_resolved' => array( 'value' => true, 'confidence' => 0.99, 'confirmed' => true ),
			'children'                  => array( 'value' => false, 'confidence' => 0.99, 'confirmed' => true ),
			'spouse_agrees'             => array( 'value' => true, 'confidence' => 0.99, 'confirmed' => true ),
		);

		$result = $this->interpreter->interpret(
			'All set.',
			array(
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'facts'    => $facts,
			)
		);

		$this->assertSame( 'intake_complete', $result['intent'] );
		$this->assertSame( 100, $result['completion'] );
		$this->assertContains( $result['next_action'], array( 'complete_intake', 'guidance' ) );
		$this->assertNotEmpty( $result['question'] );
	}

	/**
	 * User corrections can overwrite confirmed child facts.
	 */
	public function test_child_correction_after_completion(): void {
		$facts = array(
			'minor_children_involved' => array( 'value' => false, 'confidence' => 0.99, 'confirmed' => true ),
			'children'                => array( 'value' => false, 'confidence' => 0.99, 'confirmed' => true ),
			'has_minor_children'      => array( 'value' => false, 'confidence' => 0.99, 'confirmed' => true ),
			'child_count'             => array( 'value' => 0, 'confidence' => 0.99, 'confirmed' => true ),
			'county'                  => array( 'value' => 'Queens', 'confidence' => 0.99, 'confirmed' => true ),
		);

		$result = $this->interpreter->interpret(
			'oh sorry one children',
			array(
				'workflow' => 'order_of_protection_nyc',
				'facts'    => $facts,
			)
		);

		$this->assertNotSame( 'needs_review', $result['next_action'] ?? '' );
		$this->assertNotSame( 'error', $result['next_action'] ?? '' );
		$this->assertEmpty( $result['contradictions'] ?? array() );
		$this->assertNotEmpty( $result['question'] );
		$this->assertSame( true, $result['state']['facts']['minor_children_involved']['value'] ?? null );
		$this->assertSame( 1, $result['state']['facts']['child_count']['value'] ?? null );
	}

	/**
	 * Rich first-message bulk extraction including marriage date.
	 */
	public function test_rich_divorce_message_extracts_marriage_date(): void {
		$message = 'Hi, I need help getting a divorce in New York. I live in Queens County and my wife also lives in Queens. We were married on June 15, 2015. We have two children together, ages 8 and 12. My wife agrees to the divorce and we have already discussed custody and support arrangements.';

		$extractor = new \ProSe\Core\Ai_Intake\Fact_Extractor();
		$state     = Intake_State::from_array( array() );
		$provider  = new Stub_Ai_Provider();
		$catalog   = new Workflow_Catalog();
		$workflow  = $catalog->by_key( 'uncontested_divorce_children_nyc' );
		$defs      = is_array( $workflow ) ? ( $workflow['required_fields'] ?? array() ) : array();

		$result = $extractor->extract( $message, $state, $defs, array( 'summary' => '', 'recent' => array() ), $provider );

		$this->assertArrayHasKey( 'marriage_date', $result['updates'] );
		$this->assertSame( '2015-06-15', $result['updates']['marriage_date']['value'] );
		$this->assertArrayHasKey( 'county', $result['updates'] );
		$this->assertArrayHasKey( 'spouse_agrees', $result['updates'] );
		$this->assertArrayHasKey( 'child_count', $result['updates'] );

		$interpreted = $this->interpreter->interpret( $message );
		$facts       = $interpreted['state']['facts'] ?? array();

		$this->assertArrayHasKey( 'marriage_date', $facts );
		$this->assertNotSame( 'marriage_date', $interpreted['pending_field'] ?? '' );
	}
}
