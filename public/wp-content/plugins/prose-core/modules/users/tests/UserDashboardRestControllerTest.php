<?php
/**
 * User dashboard REST controller tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Guidance\Procedural_Roadmap_Presenter;
use ProSe\Core\Users\Rest\User_Dashboard_Rest_Controller;

/**
 * Class UserDashboardRestControllerTest
 */
class UserDashboardRestControllerTest extends TestCase {

	/**
	 * Case progress summary excludes full roadmap step lists.
	 */
	public function test_case_progress_summary_excludes_full_roadmap(): void {
		$presenter = new Procedural_Roadmap_Presenter();
		$roadmap   = $presenter->present(
			array(
				'issue'             => 'divorce',
				'facts'             => array(
					'issue'  => 'divorce',
					'county' => 'Queens',
				),
				'workflow'          => '',
				'completion'        => 35,
				'workflow_resolved' => false,
			)
		);

		$summary = $presenter->to_summary( $roadmap, 'https://example.test/resume' );

		$this->assertTrue( $summary['show'] );
		$this->assertArrayHasKey( 'current_stage', $summary );
		$this->assertArrayHasKey( 'next_likely_step', $summary );
		$this->assertArrayNotHasKey( 'completed_steps', $summary );
		$this->assertArrayNotHasKey( 'upcoming_steps', $summary );
		$this->assertArrayNotHasKey( 'required_forms', $summary );
		$this->assertArrayNotHasKey( 'procedural_guidance', $summary );
		$this->assertSame( 'https://example.test/resume', $summary['continue_case_url'] );
	}

	/**
	 * Dashboard route constant remains stable for clients.
	 */
	public function test_dashboard_route_constant(): void {
		$this->assertSame( '/me/dashboard', User_Dashboard_Rest_Controller::ROUTE );
	}
}
