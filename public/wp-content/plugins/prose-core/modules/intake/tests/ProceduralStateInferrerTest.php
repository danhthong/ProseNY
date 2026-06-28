<?php
/**
 * Procedural state inferrer tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Intake\Procedural_State_Inferrer;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class ProceduralStateInferrerTest
 */
class ProceduralStateInferrerTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Settlement agreement and filed papers update supplemental facts.
	 */
	public function test_settlement_and_filed_papers_extract_facts(): void {
		$inferrer = new Procedural_State_Inferrer();
		$message  = 'We both agree and already signed a settlement agreement. I filed the divorce papers in Brooklyn two weeks ago.';
		$updates  = $inferrer->supplemental_fact_updates( $message );

		$this->assertTrue( $updates['marital_property_resolved']['value'] ?? false );
		$this->assertTrue( $updates['active_divorce']['value'] ?? false );
	}

	/**
	 * Filed case advances node to service for uncontested divorce.
	 */
	public function test_filed_case_advances_to_service_node(): void {
		$inferrer = new Procedural_State_Inferrer();
		$node     = $inferrer->infer_procedural_node(
			'uncontested_divorce_children_nyc',
			Vocabulary::NODE_1001_DIVORCE_FILED,
			array(
				'active_divorce' => true,
				'case_status'    => 'FILED',
			),
			'I filed the divorce papers last week.'
		);

		$this->assertSame( Vocabulary::NODE_1002_SERVICE_COMPLETE, $node );
	}

	/**
	 * Seeking to start a divorce must not advance past commencement.
	 */
	public function test_seeking_divorce_stays_at_commencement_node(): void {
		$inferrer = new Procedural_State_Inferrer();
		$node     = $inferrer->infer_procedural_node(
			'uncontested_divorce_children_nyc',
			'',
			array( 'active_divorce' => true ),
			'I need to file for divorce in New York City'
		);

		$this->assertSame( '', $node );
	}
}
