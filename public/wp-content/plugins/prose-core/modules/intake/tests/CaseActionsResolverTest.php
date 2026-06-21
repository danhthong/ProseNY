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
	 * Download stays disabled until intake completes and the current stage unlocks forms.
	 */
	public function test_download_disabled_before_intake_complete(): void {
		$resolver = new Case_Actions_Resolver();
		$partial  = $resolver->resolve(
			array(
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'facts'    => array( 'county' => 'Queens' ),
				'progress' => 60,
			),
			array(
				'intent'         => 'gathering',
				'completion'     => 60,
				'missing_fields' => array( 'spouse_name' ),
			)
		);

		$this->assertFalse( $partial['intake_complete'] );
		$this->assertTrue( $partial['workflow_resolved'] );
		$this->assertFalse( $partial['download_enabled'] );
		$this->assertFalse( $partial['stage_context']['forms_visible'] );

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
	 * Adoption workflow keeps download gated until intake completes.
	 */
	public function test_adoption_download_gated_until_intake_complete(): void {
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
		$this->assertFalse( $actions['download_enabled'] );
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
					'county'      => 'Queens',
					'child_count' => 2,
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
}
