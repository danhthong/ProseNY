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
}
