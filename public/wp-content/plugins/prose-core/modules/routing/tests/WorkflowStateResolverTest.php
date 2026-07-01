<?php
/**
 * Workflow State Resolver tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Intake\Case_Actions_Resolver;
use ProSe\Core\Routing\Workflow_Catalog;
use ProSe\Core\Routing\Workflow_State_Resolver;

/**
 * Class WorkflowStateResolverTest
 */
class WorkflowStateResolverTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Incomplete intake caps runaway stored nodes but honors user-confirmed progression.
	 */
	public function test_incomplete_intake_caps_node_at_entry(): void {
		$resolver = new Workflow_State_Resolver();
		$workflow = 'uncontested_divorce_no_children_nyc';
		$definition = ( new Workflow_Catalog() )->by_key( $workflow );
		$required   = is_array( $definition['required_fields'] ?? null ) ? $definition['required_fields'] : array();
		$state      = $resolver->resolve(
			array(
				'workflow'             => $workflow,
				'facts'                => array(
					'children'      => false,
					'spouse_agrees' => true,
				),
				'procedural_node'      => Vocabulary::NODE_1010_JUDGMENT,
				'required_field_defs'  => $required,
				'completed_stage_count' => 0,
			)
		);

		$this->assertFalse( $state['intake_complete'] );
		$this->assertSame( 'commencement', $state['current_stage']['id'] ?? '' );
		$this->assertNotSame( Vocabulary::NODE_1010_JUDGMENT, $state['procedural_node'] ?? '' );
	}

	/**
	 * User-confirmed stage completion advances the effective node without full intake.
	 */
	public function test_incomplete_intake_honors_user_stage_progression(): void {
		$resolver = new Workflow_State_Resolver();
		$workflow = 'uncontested_divorce_no_children_nyc';
		$definition = ( new Workflow_Catalog() )->by_key( $workflow );
		$required   = is_array( $definition['required_fields'] ?? null ) ? $definition['required_fields'] : array();
		$state      = $resolver->resolve(
			array(
				'workflow'              => $workflow,
				'facts'                 => array(
					'children'      => false,
					'spouse_agrees' => true,
				),
				'procedural_node'       => Vocabulary::NODE_1002_SERVICE_COMPLETE,
				'required_field_defs'   => $required,
				'completed_stage_count' => 1,
			)
		);

		$this->assertFalse( $state['intake_complete'] );
		$this->assertSame( Vocabulary::NODE_1002_SERVICE_COMPLETE, $state['procedural_node'] ?? '' );
		$this->assertSame( 'service', $state['current_stage']['id'] ?? '' );
	}

	/**
	 * Boolean children facts do not imply a numeric child count in summary display.
	 */
	public function test_children_summary_shows_yes_for_boolean(): void {
		$resolver = new Case_Actions_Resolver();
		$method   = new ReflectionMethod( Case_Actions_Resolver::class, 'children_summary' );
		$method->setAccessible( true );
		$value    = $method->invoke( $resolver, array( 'children' => true ) );

		$this->assertSame( 'Yes', $value );
	}
}
