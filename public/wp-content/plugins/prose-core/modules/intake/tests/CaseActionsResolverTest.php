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
		$this->assertTrue( $actions['show_documents'] );
		$this->assertNotEmpty( $actions['workflow'] );
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
