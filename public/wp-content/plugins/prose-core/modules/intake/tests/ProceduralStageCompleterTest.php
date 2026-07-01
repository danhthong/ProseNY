<?php
/**
 * Procedural stage completer tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Procedural_Stage_Completer;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class ProceduralStageCompleterTest
 */
class ProceduralStageCompleterTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Completing commencement advances to the service node.
	 */
	public function test_commencement_completion_advances_to_service(): void {
		$completer = new Procedural_Stage_Completer();
		$result    = $completer->complete_current_stage(
			array(
				'workflow'        => 'uncontested_divorce_no_children_nyc',
				'procedural_node' => 'NODE_1001_DIVORCE_FILED',
				'facts'           => array(
					'spouse_agrees' => true,
					'children'      => false,
				),
				'progress' => 60,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['advanced'] );
		$this->assertSame( 'NODE_1002_SERVICE_COMPLETE', $result['case_profile']['procedural_node'] ?? '' );
		$this->assertSame( 'service', $result['stage_context']['current_stage']['id'] ?? '' );
		$this->assertNotEmpty( $result['message'] ?? '' );
		$this->assertStringContainsString( 'service', strtolower( (string) ( $result['message'] ?? '' ) ) );
	}

	/**
	 * Completing service advances when commencement was already confirmed with partial intake.
	 */
	public function test_service_completion_advances_with_partial_intake(): void {
		$completer = new Procedural_Stage_Completer();
		$result    = $completer->complete_current_stage(
			array(
				'workflow'        => 'uncontested_divorce_no_children_nyc',
				'procedural_node' => 'NODE_1002_SERVICE_COMPLETE',
				'facts'           => array(
					'county'        => 'Queens',
					'spouse_agrees' => true,
					'children'      => false,
				),
				'progress'            => 60,
				'completed_documents' => array(
					array(
						'stage_id'     => 'commencement',
						'stage_title'  => 'Starting the Case',
						'completed_at' => '2026-07-01 12:00:00',
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['advanced'] );
		$this->assertSame( 'NODE_1010_JUDGMENT', $result['case_profile']['procedural_node'] ?? '' );
		$this->assertSame( 'calendar', $result['stage_context']['current_stage']['id'] ?? '' );
		$this->assertSame( 'service', $result['completed_stage'] ?? '' );
		$this->assertStringContainsString( 'Final Papers', (string) ( $result['message'] ?? '' ) );
	}
}
