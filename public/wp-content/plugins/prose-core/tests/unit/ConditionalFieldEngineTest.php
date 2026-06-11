<?php
/**
 * Tests for the Conditional Field Engine: conditional visibility and
 * conditional validation.
 *
 * A CONDITIONAL field becomes REQUIRED only when its condition holds. When the
 * condition is false the field is hidden, excluded from completeness, and
 * excluded from validation.
 *
 * Runs database-free against Case_State.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Documents\Conditional_Field_Resolver;
use ProSe\Core\Forms\Documents\Conditional_Validation_Service;
use ProSe\Core\Forms\Documents\Document_Generation_Service;
use ProSe\Core\Forms\Documents\Field_Catalog;
use ProSe\Core\Forms\Engine\Case_Service;
use ProSe\Core\Forms\Engine\Case_State;
use ProSe\Core\Forms\Engine\Condition_Evaluator;

/**
 * Class ConditionalFieldEngineTest
 */
class ConditionalFieldEngineTest extends TestCase {

	/**
	 * @return Document_Generation_Service
	 */
	private function generation(): Document_Generation_Service {
		return new Document_Generation_Service();
	}

	/**
	 * Build a divorce case state with court/county metadata.
	 *
	 * @param array<string, mixed> $answers Intake answers.
	 * @return Case_State
	 */
	private function divorce_case( array $answers ): Case_State {
		$state = ( new Case_Service() )->create_case( Vocabulary::WF_UNCONTESTED_DIVORCE, $answers );
		$state->set_county( Vocabulary::COUNTY_NEW_YORK );
		$state->set_court_routing( Vocabulary::ROUTE_SUPREME_COURT );

		return $state;
	}

	/**
	 * Build a family-court case state with court/county metadata.
	 *
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $answers  Intake answers.
	 * @return Case_State
	 */
	private function family_case( string $workflow, array $answers ): Case_State {
		$state = ( new Case_Service() )->create_case( $workflow, $answers );
		$state->set_county( Vocabulary::COUNTY_KINGS );
		$state->set_court_routing( Vocabulary::ROUTE_FAMILY_COURT );

		return $state;
	}

	/**
	 * Condition evaluator gains numeric comparison operators.
	 */
	public function test_evaluator_numeric_operators(): void {
		$evaluator = new Condition_Evaluator();
		$group     = array(
			'all' => array(
				array(
					'type'  => 'answer',
					'key'   => 'children_count',
					'op'    => 'gt',
					'value' => 0,
				),
			),
		);

		$this->assertTrue( $evaluator->evaluate( $group, array( 'answers' => array( 'children_count' => 2 ) ) ) );
		$this->assertFalse( $evaluator->evaluate( $group, array( 'answers' => array( 'children_count' => 0 ) ) ) );
		// Absent answer must never satisfy "> 0".
		$this->assertFalse( $evaluator->evaluate( $group, array( 'answers' => array() ) ) );
	}

	/**
	 * Resolver and validation service agree on the active/hidden split.
	 */
	public function test_resolver_active_and_hidden_split(): void {
		$resolver   = new Conditional_Field_Resolver();
		$validation = new Conditional_Validation_Service( $resolver );

		$active_state = $this->divorce_case(
			array(
				'petitioner_name' => 'Jane Doe',
				'respondent_name' => 'John Doe',
				'grounds'         => 'DRL_170_1',
			)
		);

		$this->assertTrue( $resolver->is_active( 'UD-2', 'fault_allegations', $active_state ) );
		$this->assertContains( 'fault_allegations', $validation->active_fields( 'UD-2', $active_state ) );
		$this->assertNotContains( 'fault_allegations', $validation->hidden_fields( 'UD-2', $active_state ) );
		$this->assertSame(
			Field_Catalog::CLASS_REQUIRED,
			$resolver->resolved_class( 'UD-2', 'fault_allegations', $active_state )
		);

		$inactive_state = $this->divorce_case(
			array(
				'petitioner_name' => 'Jane Doe',
				'respondent_name' => 'John Doe',
			)
		);

		$this->assertFalse( $resolver->is_active( 'UD-2', 'fault_allegations', $inactive_state ) );
		$this->assertContains( 'fault_allegations', $validation->hidden_fields( 'UD-2', $inactive_state ) );
		$this->assertSame(
			Field_Catalog::CLASS_CONDITIONAL,
			$resolver->resolved_class( 'UD-2', 'fault_allegations', $inactive_state )
		);
	}

	/**
	 * Acceptance: condition TRUE -> field required and visible.
	 *
	 * grounds = DRL_170_1 -> fault_allegations required.
	 */
	public function test_condition_true_makes_field_required(): void {
		$gen   = $this->generation();
		$state = $this->divorce_case(
			array(
				'petitioner_name' => 'Jane Doe',
				'respondent_name' => 'John Doe',
				'marriage_date'   => '2010-06-01',
				'grounds'         => 'DRL_170_1',
			)
		);

		$doc   = $gen->assemble_form( $state, 'UD-2', Vocabulary::PKG_CONTESTED_COMMENCEMENT );
		$field = $doc->field( 'fault_allegations' );

		$this->assertNotNull( $field );
		$this->assertTrue( $field->is_required() );
		$this->assertTrue( $field->is_visible() );
		$this->assertFalse( $field->is_resolved() );
		$this->assertSame( Field_Catalog::CLASS_REQUIRED, $field->field_class() );
		$this->assertContains( 'fault_allegations', $doc->validation()->missing_conditional() );
		$this->assertFalse( $doc->is_valid() );
	}

	/**
	 * Acceptance: condition FALSE -> field ignored everywhere.
	 *
	 * grounds defaults (not fault) -> fault_allegations hidden and excluded.
	 */
	public function test_condition_false_field_ignored(): void {
		$gen   = $this->generation();
		$state = $this->divorce_case(
			array(
				'petitioner_name' => 'Jane Doe',
				'respondent_name' => 'John Doe',
				'marriage_date'   => '2010-06-01',
			)
		);

		$doc   = $gen->assemble_form( $state, 'UD-2', Vocabulary::PKG_CONTESTED_COMMENCEMENT );
		$field = $doc->field( 'fault_allegations' );

		$this->assertNotNull( $field );
		$this->assertFalse( $field->is_required() );
		$this->assertFalse( $field->is_visible() );
		$this->assertSame( Field_Catalog::CLASS_CONDITIONAL, $field->field_class() );
		$this->assertNotContains( 'fault_allegations', $doc->validation()->missing_conditional() );
		$this->assertArrayHasKey( 'fault_allegations', $doc->hidden_fields() );
		$this->assertArrayNotHasKey( 'fault_allegations', $doc->visible_fields() );
		// The (otherwise complete) verified complaint validates clean.
		$this->assertTrue( $doc->is_valid() );
	}

	/**
	 * children_count > 0 -> child_support_fields required.
	 */
	public function test_children_count_requires_child_support_fields(): void {
		$gen = $this->generation();

		$with = $this->divorce_case(
			array(
				'petitioner_name' => 'Jane Doe',
				'respondent_name' => 'John Doe',
				'has_children'    => true,
				'children_count'  => 2,
			)
		);

		$doc = $gen->assemble_form( $with, 'UD-8', Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN );
		$this->assertTrue( $doc->field( 'child_support_fields' )->is_required() );
		$this->assertTrue( $doc->field( 'child_support_fields' )->is_visible() );
		$this->assertContains( 'child_support_fields', $doc->validation()->missing_conditional() );

		$without = $this->divorce_case(
			array(
				'petitioner_name' => 'Jane Doe',
				'respondent_name' => 'John Doe',
				'has_children'    => false,
			)
		);

		$doc2 = $gen->assemble_form( $without, 'UD-8', Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN );
		$this->assertFalse( $doc2->field( 'child_support_fields' )->is_required() );
		$this->assertFalse( $doc2->field( 'child_support_fields' )->is_visible() );
		$this->assertNotContains( 'child_support_fields', $doc2->validation()->missing_conditional() );
	}

	/**
	 * Acceptance: package completeness updates with the condition.
	 *
	 * domestic_violence = YES -> protection_fields required on FC-7, so the
	 * order-of-protection package is no longer complete until the protection
	 * details are supplied.
	 */
	public function test_package_completeness_updates_with_condition(): void {
		$gen = $this->generation();

		// Condition false: protection_fields ignored, package is ready.
		$baseline = $this->family_case(
			Vocabulary::WF_ORDER_OF_PROTECTION,
			array(
				'petitioner_name'  => 'Sam Park',
				'respondent_name'  => 'Kim Park',
				'incident_date'    => '2026-01-10',
				'relief_requested' => 'Stay-away order of protection',
			)
		);

		$ready = $gen->generate_package( $baseline, Vocabulary::PKG_ORDER_OF_PROTECTION );
		$this->assertSame( 100, $ready->completeness()->completion_percentage() );
		$this->assertTrue( $ready->completeness()->is_ready_to_generate() );
		$this->assertFalse( $ready->document( 'FC-7' )->field( 'protection_fields' )->is_visible() );

		// Condition true but unmet: protection_fields required and missing.
		$flagged = $this->family_case(
			Vocabulary::WF_ORDER_OF_PROTECTION,
			array(
				'petitioner_name'   => 'Sam Park',
				'respondent_name'   => 'Kim Park',
				'incident_date'     => '2026-01-10',
				'relief_requested'  => 'Stay-away order of protection',
				'domestic_violence' => 'YES',
			)
		);

		$bundle = $gen->assemble_package( $flagged, Vocabulary::PKG_ORDER_OF_PROTECTION );
		$this->assertTrue( $bundle->document( 'FC-7' )->field( 'protection_fields' )->is_required() );
		$this->assertContains( 'protection_fields', $bundle->completeness()->missing_fields() );
		$this->assertContains( 'FC-7', $bundle->missing_forms() );
		$this->assertLessThan( 100, $bundle->completeness()->completion_percentage() );
		$this->assertFalse( $bundle->completeness()->is_ready_to_generate() );

		// Condition true and met: protection_fields supplied -> ready again.
		$satisfied = $this->family_case(
			Vocabulary::WF_ORDER_OF_PROTECTION,
			array(
				'petitioner_name'   => 'Sam Park',
				'respondent_name'   => 'Kim Park',
				'incident_date'     => '2026-01-10',
				'relief_requested'  => 'Stay-away order of protection',
				'domestic_violence' => 'YES',
				'protection_fields' => 'Refrain-from order; stay 100 yards away',
			)
		);

		$resolved = $gen->generate_package( $satisfied, Vocabulary::PKG_ORDER_OF_PROTECTION );
		$this->assertSame( 100, $resolved->completeness()->completion_percentage() );
		$this->assertTrue( $resolved->completeness()->is_ready_to_generate() );
		$this->assertTrue( $resolved->document( 'FC-7' )->field( 'protection_fields' )->is_required() );
		$this->assertTrue( $resolved->document( 'FC-7' )->field( 'protection_fields' )->is_resolved() );
	}
}
