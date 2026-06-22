<?php
/**
 * Proactive guidance behavior tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\Conversation_Engine;

/**
 * Class ProactiveGuidanceTest
 */
class ProactiveGuidanceTest extends TestCase {

	/**
	 * Role guidance forbids roadmap rendering in prose.
	 */
	public function test_role_guidance_forbids_roadmap_in_prose(): void {
		$guidance = Conversation_Engine::role_guidance();

		$this->assertStringContainsString( 'NEVER render roadmap content', $guidance );
		$this->assertStringContainsString( 'procedural_roadmap', $guidance );
		$this->assertStringContainsString( 'Never use mandatory language', $guidance );
	}

	/**
	 * Role guidance requires soft-language follow-up behavior.
	 */
	public function test_role_guidance_requires_soft_language(): void {
		$guidance = Conversation_Engine::role_guidance();

		$this->assertStringContainsString( 'you may wish to consider', $guidance );
		$this->assertStringContainsString( 'suggested_next_question', $guidance );
	}
}
