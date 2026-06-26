<?php
/**
 * Domain Scope Guard tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\AI_Intake_Service;
use ProSe\Core\Ai_Intake\AI_Settings;
use ProSe\Core\Ai_Intake\Domain_Scope_Guard;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Ai_Intake\Supported_Issue_Catalog;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class DomainScopeGuardTest
 */
class DomainScopeGuardTest extends TestCase {

	/**
	 * Guard instance.
	 *
	 * @var Domain_Scope_Guard
	 */
	private Domain_Scope_Guard $guard;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
		AI_Settings::clear_cache();
		$GLOBALS['prose_test_options'] = array();
		$this->guard = new Domain_Scope_Guard();
	}

	/**
	 * Clearly unrelated topics are blocked.
	 */
	public function test_blocks_unrelated_weather(): void {
		$result = $this->guard->assess( 'What is the weather in Albany today?' );

		$this->assertFalse( $result['supported'] );
		$this->assertLessThan( Supported_Issue_Catalog::CONFIDENCE_THRESHOLD, $result['confidence'] );
		$this->assertNotEmpty( $result['message'] );
	}

	/**
	 * Divorce-related messages are allowed.
	 */
	public function test_allows_divorce_message(): void {
		$result = $this->guard->assess( 'I need a divorce in Queens.' );

		$this->assertTrue( $result['supported'] );
		$this->assertGreaterThanOrEqual( Supported_Issue_Catalog::CONFIDENCE_THRESHOLD, $result['confidence'] );
	}

	/**
	 * Child custody messages are allowed.
	 */
	public function test_allows_custody_message(): void {
		$result = $this->guard->assess( 'Help me with child custody in family court.' );

		$this->assertTrue( $result['supported'] );
	}

	/**
	 * Hybrid messages remain supported.
	 */
	public function test_hybrid_divorce_and_immigration(): void {
		$result = $this->guard->assess( 'I need a divorce and also have questions about immigration.' );

		$this->assertTrue( $result['supported'] );
		$this->assertTrue( $result['hybrid'] );
		$this->assertContains( 'immigration', $result['out_of_scope_topics'] );
	}

	/**
	 * Active intake bypasses the guard for short answers.
	 */
	public function test_bypasses_when_pending_field_set(): void {
		$result = $this->guard->assess(
			'queens',
			array( 'pending_field' => 'county' )
		);

		$this->assertTrue( $result['supported'] );
		$this->assertTrue( $result['bypassed'] );
	}

	/**
	 * Service does not invoke OpenAI for blocked topics.
	 */
	public function test_service_skips_openai_for_unrelated_topic(): void {
		$provider = new Stub_Ai_Provider();
		$service  = new AI_Intake_Service( $provider );

		$response = $service->interpret( 'Who won the football game last night?' );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['supported'] );
		$this->assertSame( 'domain_restricted', $response['result']['next_action'] );
		$this->assertSame( 'out_of_scope', $response['result']['intent'] );
		$this->assertArrayNotHasKey( 'fact_updates', $response['result'] );
	}

	/**
	 * Service invokes interpreter for divorce topics.
	 */
	public function test_service_calls_interpreter_for_divorce_topic(): void {
		$provider = new Stub_Ai_Provider();
		$service  = new AI_Intake_Service( $provider );

		$response = $service->interpret( 'I need a divorce.' );

		$this->assertTrue( $response['success'] );
		$this->assertArrayNotHasKey( 'supported', $response );
		$this->assertSame( 'ask_question', $response['result']['next_action'] );
	}

	/**
	 * Issue types are derived from all workflow repository entries.
	 */
	public function test_issue_types_include_all_workflow_repository_types(): void {
		$catalog = new Supported_Issue_Catalog();
		$types   = $catalog->issue_types();

		$this->assertContains( 'divorce', $types );
		$this->assertContains( 'custody', $types );
		$this->assertContains( 'child_support', $types );
		$this->assertContains( 'visitation', $types );
		$this->assertContains( 'order_of_protection', $types );
		$this->assertContains( 'family_offense', $types );
		$this->assertContains( 'adoption', $types );
		$this->assertContains( 'paternity', $types );
		$this->assertContains( 'guardianship', $types );
		$this->assertGreaterThanOrEqual( 9, count( $types ) );
	}

	/**
	 * Order of protection entry is allowed on first message.
	 */
	public function test_allows_order_of_protection_message(): void {
		$result = $this->guard->assess( 'I need an order of protection in Queens.' );

		$this->assertTrue( $result['supported'] );
		$this->assertGreaterThanOrEqual( Supported_Issue_Catalog::CONFIDENCE_THRESHOLD, $result['confidence'] );
		$this->assertNotContains( 'orders of protection', $result['out_of_scope_topics'] );
	}

	/**
	 * Family offense / abuse phrasing is allowed.
	 */
	public function test_allows_family_offense_abuse_message(): void {
		$result = $this->guard->assess( 'My husband abused me.' );

		$this->assertTrue( $result['supported'] );
	}

	/**
	 * Received court papers / OSC starters are allowed.
	 */
	public function test_allows_received_osc_message(): void {
		$result = $this->guard->assess( 'I received an OSC from the court.' );

		$this->assertTrue( $result['supported'] );
	}

	/**
	 * Got court papers phrasing is allowed.
	 */
	public function test_allows_got_court_papers_message(): void {
		$result = $this->guard->assess( 'I got court papers in the mail.' );

		$this->assertTrue( $result['supported'] );
	}

	/**
	 * Ambiguous "not sure which forms" starters are allowed.
	 */
	public function test_allows_not_sure_forms_message(): void {
		$result = $this->guard->assess( 'I am not sure which court forms I need.' );

		$this->assertTrue( $result['supported'] );
	}

	/**
	 * Adoption entry is allowed.
	 */
	public function test_allows_adoption_message(): void {
		$result = $this->guard->assess( 'I want to adopt a child.' );

		$this->assertTrue( $result['supported'] );
	}

	/**
	 * Mid-intake county answer bypasses guard without keywords.
	 */
	public function test_bypasses_borough_name_without_keywords(): void {
		$result = $this->guard->assess(
			'Brooklyn',
			array( 'pending_field' => 'county' )
		);

		$this->assertTrue( $result['supported'] );
		$this->assertTrue( $result['bypassed'] );
	}

	/**
	 * Bulk fact answers during active intake stay in scope without repeating "divorce".
	 */
	public function test_allows_fact_dump_during_active_intake(): void {
		$result = $this->guard->assess(
			"We were married on June 1, 2010 in Queens, NY.\nI've lived in New York for over one year (1-year state residency).\nWe separated on January 1, 2024.\nGrounds: irretrievable breakdown.\nPlaintiff: Jane Doe. Defendant: John Doe.",
			array(
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'facts'    => array(
					'issue'         => array( 'value' => 'divorce' ),
					'county'        => array( 'value' => 'Queens' ),
					'spouse_agrees' => array( 'value' => true ),
				),
			),
			array(
				array(
					'role'    => 'user',
					'content' => 'I want a divorce in Queens.',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Do you and your spouse agree on major issues?',
				),
			)
		);

		$this->assertTrue( $result['supported'] );
		$this->assertFalse( $result['bypassed'] );
	}

	/**
	 * Service invokes interpreter for mid-intake fact dumps.
	 */
	public function test_service_calls_interpreter_for_intake_fact_dump(): void {
		$provider = new Stub_Ai_Provider();
		$service  = new AI_Intake_Service( $provider );

		$response = $service->interpret(
			'We were married on June 1, 2010 in Queens, NY. NY residency over one year. Separated January 1, 2024.',
			array(
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'facts'    => array(
					'issue'  => array( 'value' => 'divorce' ),
					'county' => array( 'value' => 'Queens' ),
				),
			),
			array(
				array(
					'role'    => 'user',
					'content' => 'I want a divorce in Queens.',
				),
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertArrayNotHasKey( 'supported', $response );
		$this->assertContains( $response['result']['next_action'], array( 'ask_question', 'guidance' ) );
	}

	/**
	 * Off-topic weather is blocked even during an active intake conversation.
	 */
	public function test_blocks_weather_during_active_intake(): void {
		$result = $this->guard->assess(
			'how the weather today in Lam Dong',
			array(
				'workflow' => 'custody_nyc',
				'facts'    => array(
					'issue'  => array( 'value' => 'custody', 'confirmed' => true ),
					'county' => array( 'value' => 'Queens', 'confirmed' => true ),
				),
			),
			array(
				array(
					'role'    => 'user',
					'content' => 'Help me with child custody in Queens.',
				),
				array(
					'role'    => 'assistant',
					'content' => 'How many children are involved?',
				),
			)
		);

		$this->assertFalse( $result['supported'] );
		$this->assertContains( 'weather', $result['out_of_scope_topics'] );
	}

	/**
	 * General-knowledge math is blocked during active intake.
	 */
	public function test_blocks_math_during_active_intake(): void {
		$result = $this->guard->assess(
			'can you give me 1 + 1 ?',
			array(
				'workflow'      => 'custody_nyc',
				'pending_field' => 'child_count',
			),
			array(
				array(
					'role'    => 'user',
					'content' => 'Help me with child custody in Queens.',
				),
			)
		);

		$this->assertFalse( $result['supported'] );
		$this->assertContains( 'general knowledge', $result['out_of_scope_topics'] );
	}

	/**
	 * Service skips OpenAI for off-topic messages during active intake.
	 */
	public function test_service_skips_openai_for_weather_during_intake(): void {
		$provider = new Stub_Ai_Provider();
		$service  = new AI_Intake_Service( $provider );

		$response = $service->interpret(
			'how the weather today in Lam Dong',
			array(
				'workflow' => 'custody_nyc',
				'facts'    => array(
					'issue' => array( 'value' => 'custody', 'confirmed' => true ),
				),
			),
			array(
				array(
					'role'    => 'user',
					'content' => 'Help me with child custody in Queens.',
				),
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['supported'] );
		$this->assertSame( 'domain_restricted', $response['result']['next_action'] );
		$this->assertSame( 'custody_nyc', $response['result']['state']['workflow'] ?? '' );
	}

	/**
	 * Procedural follow-ups remain supported during active intake.
	 */
	public function test_allows_procedural_follow_up_during_intake(): void {
		$result = $this->guard->assess(
			'What happens next?',
			array( 'workflow' => 'custody_nyc' ),
			array(
				array(
					'role'    => 'user',
					'content' => 'Help me with child custody in Queens.',
				),
			)
		);

		$this->assertTrue( $result['supported'] );
	}

	/**
	 * Service returns English-only guidance for Vietnamese messages.
	 */
	public function test_service_blocks_vietnamese_with_language_message(): void {
		$provider = new Stub_Ai_Provider();
		$service  = new AI_Intake_Service( $provider );

		$response = $service->interpret(
			'chúng tôi đồng thuận ly hôn và con tôi sẽ nuôi',
			array(
				'case_profile' => array(
					'facts' => array(
						'children' => 1,
					),
				),
			),
			array(
				array(
					'role'    => 'user',
					'content' => 'I want a divorce in Queens.',
				),
			)
		);

		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['supported'] );
		$this->assertSame( 'language_restricted', $response['result']['next_action'] );
		$this->assertStringContainsString( 'English only', $response['result']['question'] );
		$this->assertStringContainsString( 'tiếng Anh', $response['result']['question'] );
	}

	/**
	 * Service invokes interpreter for order of protection topics.
	 */
	public function test_service_calls_interpreter_for_order_of_protection(): void {
		$provider = new Stub_Ai_Provider();
		$service  = new AI_Intake_Service( $provider );

		$response = $service->interpret( 'I need an order of protection in Queens.' );

		$this->assertTrue( $response['success'] );
		$this->assertArrayNotHasKey( 'supported', $response );
		$this->assertContains( $response['result']['next_action'], array( 'ask_question', 'guidance' ) );
	}

	/**
	 * Service invokes interpreter for ambiguous form starters.
	 */
	public function test_service_calls_interpreter_for_not_sure_forms(): void {
		$provider = new Stub_Ai_Provider();
		$service  = new AI_Intake_Service( $provider );

		$response = $service->interpret( 'I am not sure which court forms I need.' );

		$this->assertTrue( $response['success'] );
		$this->assertArrayNotHasKey( 'supported', $response );
		$this->assertSame( 'ask_question', $response['result']['next_action'] );
	}
}
