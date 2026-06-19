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
		$this->assertFalse( $actions['intake_complete'] );
		$this->assertNotEmpty( $actions['workflow'] );

		if ( $actions['workflow_resolved'] && $actions['forms_matched'] > 0 ) {
			$this->assertTrue( $actions['download_enabled'] );
		}
	}

	/**
	 * Blank form download is available once workflow forms are matched, without full intake.
	 */
	public function test_download_enabled_when_workflow_resolved_without_full_intake(): void {
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
		$this->assertGreaterThan( 0, $partial['forms_matched'] );
		$this->assertTrue( $partial['download_enabled'] );

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
		$this->assertTrue( $complete['download_enabled'] );
	}

	/**
	 * Adoption workflow enables download when forms are listed, even before PDFs are ready.
	 */
	public function test_adoption_download_enabled_when_forms_matched(): void {
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
		$this->assertGreaterThanOrEqual( 2, $actions['forms_matched'] );
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
