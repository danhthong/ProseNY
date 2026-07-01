<?php
/**
 * Proactive guidance behavior tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\Case_Manager_Presenter;
use ProSe\Core\Ai_Intake\Conversation_Engine;

/**
 * Class ProactiveGuidanceTest
 */
class ProactiveGuidanceTest extends TestCase {

	/**
	 * Role guidance positions the assistant as a case manager.
	 */
	public function test_role_guidance_defines_case_manager(): void {
		$guidance = Conversation_Engine::role_guidance();

		$this->assertStringContainsString( 'Case Manager', $guidance );
		$this->assertStringContainsString( 'Why this matters', $guidance );
		$this->assertStringContainsString( 'Next step', $guidance );
		$this->assertStringContainsString( 'missing_information', $guidance );
	}

	/**
	 * Role guidance forbids duplicate deterministic blocks in prose.
	 */
	public function test_role_guidance_defers_snapshot_to_presenter(): void {
		$guidance = Conversation_Engine::role_guidance();

		$this->assertStringContainsString( 'Do NOT include AI Assessment', $guidance );
		$this->assertStringContainsString( 'Stage Timeline', $guidance );
		$this->assertStringContainsString( 'Do NOT render procedural roadmap step lists', $guidance );
	}

	/**
	 * Role guidance requires soft-language follow-up behavior.
	 */
	public function test_role_guidance_requires_soft_language(): void {
		$guidance = Conversation_Engine::role_guidance();

		$this->assertStringContainsString( 'Never use mandatory language', $guidance );
		$this->assertStringContainsString( 'paraphrase naturally', $guidance );
		$this->assertStringContainsString( 'explain WHY it matters', $guidance );
	}

	/**
	 * Shared case manager instructions are available to other services.
	 */
	public function test_shared_case_manager_instructions(): void {
		$this->assertStringContainsString( 'personalized guidance', strtolower( Case_Manager_Presenter::ROLE_INSTRUCTIONS ) );
		$this->assertStringContainsString( 'Common mistakes', Case_Manager_Presenter::ROLE_INSTRUCTIONS );
	}
}
