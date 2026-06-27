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
	}
}
