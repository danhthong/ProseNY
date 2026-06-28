<?php
/**
 * Case Actions Resolver tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Intake\Case_Actions_Resolver;
use ProSe\Core\Routing\Workflow_Catalog;

/**
 * Class CaseActionsResolverTest
 */
class CaseActionsResolverTest extends TestCase {

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		Workflow_Catalog::reset_cache();
	}

	/**
	 * Issue alone is enough to show document actions mid-intake.
	 */
	public function test_shows_actions_when_issue_known_without_workflow(): void {
		$resolver = new Case_Actions_Resolver();
		$actions  = $resolver->resolve(
			array(
				'issue'    => 'divorce',
				'facts'    => array( 'county' => 'Queens' ),
				'progress' => 20,
			),
			array(
				'intent'     => 'gathering',
				'completion' => 20,
			)
		);

		$this->assertTrue( $actions['case_known'] );
		$this->assertFalse( $actions['show_documents'] );
		$this->assertFalse( $actions['intake_complete'] );
		$this->assertFalse( $actions['download_enabled'] );
	}

	/**
	 * Children alone does not unlock document download until routing completes.
	 */
	public function test_download_disabled_when_only_children_known(): void {
		$resolver = new Case_Actions_Resolver();
		$actions  = $resolver->resolve(
			array(
				'facts'    => array(
					'children' => 1,
				),
				'progress' => 10,
			),
			array(
				'intent'     => 'gathering',
				'completion' => 10,
			)
		);

		$this->assertTrue( $actions['case_known'] );
		$this->assertFalse( $actions['workflow_resolved'] );
		$this->assertFalse( $actions['download_enabled'] );
		$this->assertFalse( $actions['stage_context']['forms_visible'] );
	}

	/**
	 * Stored workflow unlocks download even when optional routing keys remain unset.
	 */
	public function test_download_enabled_when_workflow_stored_without_active_divorce_fact(): void {
		$resolver = new Case_Actions_Resolver();
		$actions  = $resolver->resolve(
			array(
				'workflow' => 'uncontested_divorce_children_nyc',
				'facts'    => array(
					'county'        => 'Queens',
					'spouse_agrees' => true,
					'children'      => true,
					'child_count'   => 1,
				),
				'progress' => 40,
			),
			array(
				'intent'     => 'gathering',
				'completion' => 40,
			)
		);

		$this->assertTrue( $actions['workflow_resolved'] );
		$this->assertTrue( $actions['stage_context']['forms_visible'] );
		$this->assertTrue( $actions['download_enabled'] );
		$this->assertGreaterThan( 0, $actions['forms_matched'] );
	}

	/**
	 * Download is available once routing resolves the workflow, even when personal intake fields remain.
	 */
	public function test_download_enabled_when_workflow_resolved_with_personal_fields_missing(): void {
		$resolver = new Case_Actions_Resolver();
		$partial  = $resolver->resolve(
			array(
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'facts'    => array(
					'county'                    => 'Queens',
					'spouse_agrees'             => true,
					'children'                  => false,
					'marital_property_resolved' => true,
					'active_divorce'            => false,
				),
				'progress' => 60,
			),
			array(
				'intent'         => 'gathering',
				'completion'     => 60,
				'missing_fields' => array( 'spouse_name', 'marriage_date', 'annual_income' ),
			)
		);

		$this->assertFalse( $partial['intake_complete'] );
		$this->assertTrue( $partial['workflow_resolved'] );
		$this->assertTrue( $partial['stage_context']['forms_visible'] );
		$this->assertTrue( $partial['download_enabled'] );
		$this->assertGreaterThan( 0, $partial['forms_matched'] );
		$this->assertCount( 2, $partial['download_options'] );
		$this->assertSame( 'Get Documents (UD-1)', $partial['download_options'][0]['label'] );
		$this->assertSame( 'Get Documents (UD-1A and UD-2)', $partial['download_options'][1]['label'] );

		$form_rows = array();

		foreach ( $partial['summary'] as $row ) {
			$label = (string) ( $row['label'] ?? '' );

			if ( str_contains( $label, 'Option 1' ) || str_contains( $label, 'Option 2' ) ) {
				$form_rows[] = $row;
			}
		}

		$this->assertCount( 2, $form_rows );
		$this->assertStringContainsString( 'UD-1', (string) ( $form_rows[0]['value'] ?? '' ) );
		$this->assertStringContainsString( 'Option 2', (string) ( $form_rows[1]['label'] ?? '' ) );
		$this->assertStringContainsString( 'UD-2', (string) ( $form_rows[1]['value'] ?? '' ) );

		$complete = $resolver->resolve(
			array(
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'facts'    => array( 'county' => 'Queens' ),
				'progress' => 100,
			),
			array(
				'intent'         => 'intake_complete',
				'completion'     => 100,
				'missing_fields' => array(),
			)
		);

		$this->assertTrue( $complete['intake_complete'] );
		$this->assertTrue( $complete['stage_context']['forms_visible'] );
		$this->assertGreaterThan( 0, $complete['forms_matched'] );
	}

	/**
	 * Adoption workflow unlocks blank forms once the workflow is routed.
	 */
	public function test_adoption_download_available_after_workflow_routed(): void {
		$resolver = new Case_Actions_Resolver();
		$actions  = $resolver->resolve(
			array(
				'workflow' => 'adoption_nyc',
				'issue'    => 'adoption',
				'progress' => 10,
			),
			array(
				'intent'     => 'gathering',
				'completion' => 10,
			)
		);

		$this->assertSame( 'adoption_nyc', $actions['workflow'] );
		$this->assertTrue( $actions['workflow_resolved'] );
		$this->assertTrue( $actions['stage_context']['forms_visible'] );
		$this->assertTrue( $actions['download_enabled'] );
	}

	/**
	 * Complete intake with resolved workflow exposes action panel fields.
	 */
	public function test_complete_intake_resolves_summary(): void {
		$resolver = new Case_Actions_Resolver();
		$actions  = $resolver->resolve(
			array(
				'workflow' => 'uncontested_divorce_children_nyc',
				'facts'    => array(
					'county'                    => 'Queens',
					'child_count'               => 2,
					'spouse_agrees'             => true,
					'children'                  => true,
					'marital_property_resolved' => true,
					'active_divorce'            => false,
				),
				'progress' => 100,
			),
			array(
				'intent'         => 'intake_complete',
				'completion'     => 100,
				'missing_fields' => array(),
			)
		);

		$this->assertTrue( $actions['intake_complete'] );
		$this->assertTrue( $actions['package_resolved'] );
		$this->assertNotEmpty( $actions['package_id'] );
		$this->assertNotEmpty( $actions['summary'] );
	}

	/**
	 * Stored procedural node selects the matching stage forms.
	 */
	public function test_procedural_node_selects_service_stage_forms(): void {
		$resolver = new Case_Actions_Resolver();
		$actions  = $resolver->resolve(
			array(
				'workflow'        => 'uncontested_divorce_children_nyc',
				'procedural_node' => 'NODE_1002_SERVICE_COMPLETE',
				'facts'           => array(
					'county'        => 'Queens',
					'spouse_agrees' => true,
					'children'      => true,
					'child_count'   => 1,
				),
				'progress' => 40,
			)
		);

		$this->assertSame( 'service', $actions['stage_context']['current_stage']['id'] ?? '' );
		$codes = array_column( $actions['stage_context']['stage_forms'] ?? array(), 'code' );
		$this->assertContains( 'UD-3', $codes );
		$this->assertNotContains( 'UD-1', $codes );
	}

	/**
	 * Case summary panel includes current procedural stage rows.
	 */
	public function test_summary_includes_current_stage_rows(): void {
		$resolver = new Case_Actions_Resolver();
		$actions  = $resolver->resolve(
			array(
				'workflow'        => 'uncontested_divorce_children_nyc',
				'procedural_node' => 'NODE_1010_JUDGMENT',
				'court'           => 'supreme_court',
				'issue'           => 'divorce',
				'facts'           => array(
					'county'        => 'Queens',
					'spouse_agrees' => true,
					'children'      => true,
					'child_count'   => 1,
				),
				'progress' => 80,
			)
		);

		$labels = array_column( $actions['summary'] ?? array(), 'label' );

		$this->assertContains( 'Current stage', $labels );
		$this->assertContains( 'Forms for this step', $labels );
	}
}
