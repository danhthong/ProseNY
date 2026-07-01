<?php
/**
 * Strategy refusal tests for the AI Procedural Assistant.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\AI_Intake_Interpreter;
use ProSe\Core\Ai_Intake\AI_Settings;
use ProSe\Core\Ai_Intake\Ai_Provider_Interface;
use ProSe\Core\Ai_Intake\Conversation_Engine;
use ProSe\Core\Ai_Intake\Stub_Ai_Provider;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class StrategyRefusalTest
 */
class StrategyRefusalTest extends TestCase {

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
	 * Role guidance forbids legal strategy recommendations.
	 */
	public function test_role_guidance_forbids_strategy(): void {
		$guidance = Conversation_Engine::role_guidance();

		$this->assertStringContainsString( 'Never give legal strategy', $guidance );
		$this->assertStringContainsString( 'procedural_navigator', $guidance );
	}

	/**
	 * Sole custody strategy question receives procedural-only reply.
	 */
	public function test_sole_custody_strategy_refusal(): void {
		$state = array(
			'facts' => array(
				'issue'         => array( 'value' => 'custody', 'confidence' => 1.0, 'confirmed' => true ),
				'county'        => array( 'value' => 'Queens', 'confidence' => 1.0, 'confirmed' => true ),
				'child_count'   => array( 'value' => 1, 'confidence' => 1.0, 'confirmed' => true ),
				'spouse_agrees' => array( 'value' => false, 'confidence' => 1.0, 'confirmed' => true ),
			),
			'workflow' => 'custody_nyc',
		);

		$result = $this->interpreter->interpret( 'Should I ask for sole custody?', $state );

		$reply = strtolower( (string) ( $result['question'] ?? '' ) );

		$this->assertStringNotContainsString( 'you should ask for sole custody', $reply );
		$this->assertStringNotContainsString( 'i recommend', $reply );
		$this->assertTrue(
			str_contains( $reply, 'cannot recommend' )
			|| str_contains( $reply, 'explain' )
			|| str_contains( $reply, 'procedure' )
		);
	}

	/**
	 * Procedural navigator context is injected when workflow is resolved.
	 */
	public function test_procedural_navigator_injected_in_converse_payload(): void {
		$capturing = new Capturing_Stub_Provider();
		$interpreter = new AI_Intake_Interpreter( $capturing );

		$interpreter->interpret(
			'What happens next?',
			array(
				'facts' => array(
					'issue'         => array( 'value' => 'divorce', 'confidence' => 1.0, 'confirmed' => true ),
					'county'        => array( 'value' => 'Queens', 'confidence' => 1.0, 'confirmed' => true ),
					'children'      => array( 'value' => false, 'confidence' => 1.0, 'confirmed' => true ),
					'spouse_agrees' => array( 'value' => true, 'confidence' => 1.0, 'confirmed' => true ),
				),
				'workflow' => 'uncontested_divorce_no_children_nyc',
			)
		);

		$this->assertStringContainsString( 'procedural_navigator', $capturing->last_user_payload );
		$this->assertStringContainsString( 'next_steps', $capturing->last_user_payload );
	}

	/**
	 * Procedural roadmap context is injected when issue is known.
	 */
	public function test_procedural_roadmap_injected_in_converse_payload(): void {
		$capturing   = new Capturing_Stub_Provider();
		$interpreter = new AI_Intake_Interpreter( $capturing );

		$interpreter->interpret(
			'I want a divorce in Queens.',
			array(
				'facts' => array(
					'issue'  => array( 'value' => 'divorce', 'confidence' => 1.0, 'confirmed' => true ),
					'county' => array( 'value' => 'Queens', 'confidence' => 1.0, 'confirmed' => true ),
				),
			)
		);

		$this->assertStringContainsString( 'procedural_roadmap', $capturing->last_user_payload );
		$this->assertStringContainsString( '"show":true', str_replace( ' ', '', $capturing->last_user_payload ) );
	}
}

/**
 * Stub provider that captures the latest user payload for assertions.
 */
final class Capturing_Stub_Provider implements Ai_Provider_Interface {

	/**
	 * Latest user message content sent to the provider.
	 *
	 * @var string
	 */
	public string $last_user_payload = '';

	/**
	 * Wrapped stub provider.
	 *
	 * @var Stub_Ai_Provider
	 */
	private Stub_Ai_Provider $inner;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->inner = new Stub_Ai_Provider();
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return $this->inner->name();
	}

	/**
	 * {@inheritDoc}
	 */
	public function complete( array $messages, array $options = array() ): array {
		foreach ( array_reverse( $messages ) as $message ) {
			if ( 'user' === ( $message['role'] ?? '' ) ) {
				$this->last_user_payload = (string) ( $message['content'] ?? '' );
				break;
			}
		}

		return $this->inner->complete( $messages, $options );
	}
}
