<?php
/**
 * AI event context tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Ai_Intake\Ai_Event_Context;

/**
 * Class AiEventContextTest
 */
class AiEventContextTest extends TestCase {

	/**
	 * Explicit client events normalize with metadata.
	 */
	public function test_normalize_explicit_event_array(): void {
		$event = Ai_Event_Context::normalize(
			array(
				'type'     => 'forms_downloaded',
				'workflow' => 'uncontested_divorce_no_children_nyc',
			)
		);

		$this->assertSame( Ai_Event_Context::TYPE_FORMS_DOWNLOADED, $event['type'] );
		$this->assertSame( 'uncontested_divorce_no_children_nyc', $event['meta']['workflow'] );
	}

	/**
	 * Stage guidance requests resolve to completion confirmation.
	 */
	public function test_resolve_completion_confirmation_from_stage_guidance_flag(): void {
		$event = Ai_Event_Context::resolve(
			array(
				'state'   => array(
					'stage_guidance_only' => true,
					'completed_stage'     => 'service',
				),
				'message' => '',
			)
		);

		$this->assertSame( Ai_Event_Context::TYPE_COMPLETION_CONFIRMATION, $event['type'] );
		$this->assertSame( 'service', $event['meta']['completed_stage'] );
	}

	/**
	 * First workflow resolution on a turn maps to workflow_selected.
	 */
	public function test_resolve_workflow_selected_when_workflow_newly_resolved(): void {
		$event = Ai_Event_Context::resolve(
			array(
				'state'             => array(),
				'message'           => 'We agree on everything.',
				'workflow_at_entry' => '',
				'workflow_now'      => 'uncontested_divorce_no_children_nyc',
			)
		);

		$this->assertSame( Ai_Event_Context::TYPE_WORKFLOW_SELECTED, $event['type'] );
		$this->assertSame( 'uncontested_divorce_no_children_nyc', $event['meta']['workflow'] );
	}

	/**
	 * Case summary phrasing maps to case_summary_requested.
	 */
	public function test_resolve_case_summary_from_message(): void {
		$event = Ai_Event_Context::resolve(
			array(
				'state'   => array(),
				'message' => 'Can you give me a case summary?',
			)
		);

		$this->assertSame( Ai_Event_Context::TYPE_CASE_SUMMARY_REQUESTED, $event['type'] );
	}

	/**
	 * Event instructions remind the model that download is not filing.
	 */
	public function test_forms_downloaded_instructions_distinguish_filing(): void {
		$instructions = Ai_Event_Context::instructions_for( Ai_Event_Context::TYPE_FORMS_DOWNLOADED );

		$this->assertStringContainsString( 'Downloaded', $instructions );
		$this->assertStringContainsString( 'filed', strtolower( $instructions ) );
	}

	/**
	 * Unknown event types fall back to user_message.
	 */
	public function test_unknown_type_falls_back_to_user_message(): void {
		$event = Ai_Event_Context::normalize( 'not_a_real_event' );

		$this->assertSame( Ai_Event_Context::TYPE_USER_MESSAGE, $event['type'] );
	}
}
