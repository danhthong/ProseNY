<?php
/**
 * Conversation restore enricher tests.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake\Tests;

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Conversation_Restore_Enricher;

/**
 * Class ConversationRestoreEnricherTest
 */
final class ConversationRestoreEnricherTest extends TestCase {

	/**
	 * Rebuild stage-complete user and assistant pairs after intake turns.
	 *
	 * @return void
	 */
	public function test_enrich_adds_stage_complete_user_and_assistant_turns(): void {
		$profile = $this->sample_profile();

		$conversation = array(
			array(
				'role'    => 'user',
				'content' => 'I need to file for divorce in New York City',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I can help you with that.',
			),
		);

		$enriched = ( new Conversation_Restore_Enricher() )->enrich( $conversation, $profile );

		$this->assertGreaterThanOrEqual( 6, count( $enriched ) );
		$this->assertSame( 'user', $enriched[2]['role'] ?? '' );
		$this->assertStringContainsString( 'I completed this step', (string) ( $enriched[2]['content'] ?? '' ) );
		$this->assertSame( 'assistant', $enriched[3]['role'] ?? '' );
		$this->assertNotSame( '', trim( (string) ( $enriched[3]['content'] ?? '' ) ) );
		$this->assertSame( 'user', $enriched[4]['role'] ?? '' );
		$this->assertSame( 'assistant', $enriched[5]['role'] ?? '' );
	}

	/**
	 * Prefer stored transcript assistant replies over regenerated guidance.
	 *
	 * @return void
	 */
	public function test_enrich_uses_stored_transcript_assistant_replies(): void {
		$profile = $this->sample_profile();
		$profile['stage_transition_transcript'] = array(
			array(
				'user'      => 'I completed this step — continue to Service of Process',
				'assistant' => 'Stored guidance for service.',
			),
			array(
				'user'      => 'I completed this step — continue to Final Papers & Calendar',
				'assistant' => 'Stored guidance for calendar.',
			),
		);

		$enriched = ( new Conversation_Restore_Enricher() )->enrich( array(), $profile );

		$this->assertSame( 'Stored guidance for service.', (string) ( $enriched[1]['content'] ?? '' ) );
		$this->assertSame( 'Stored guidance for calendar.', (string) ( $enriched[3]['content'] ?? '' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function sample_profile(): array {
		return array(
			'workflow' => 'uncontested_divorce_no_children_nyc',
			'facts'    => array(
				'spouse_agrees'             => true,
				'children'                  => false,
				'marital_property_resolved' => true,
			),
			'completed_documents' => array(
				array(
					'stage_id'    => 'commencement',
					'stage_title' => 'Starting the Case',
				),
				array(
					'stage_id'    => 'service',
					'stage_title' => 'Service of Process',
				),
			),
		);
	}
}
