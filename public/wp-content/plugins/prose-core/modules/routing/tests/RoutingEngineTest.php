<?php
/**
 * End-to-end routing engine tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Routing\Case_Profile;
use ProSe\Core\Routing\Fact_Store;
use ProSe\Core\Routing\Routing_Engine;

/**
 * Class RoutingEngineTest
 */
class RoutingEngineTest extends TestCase {

	/**
	 * Routing engine.
	 *
	 * @var Routing_Engine
	 */
	private Routing_Engine $engine;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		$this->engine = new Routing_Engine();
	}

	/**
	 * Divorce with two kids resolves to uncontested divorce children workflow.
	 */
	public function test_divorce_with_two_children(): void {
		$result = $this->engine->route( 'I want a divorce with two kids.' );

		$this->assertSame( 'divorce_with_children', $result->issue() );
		$this->assertSame( 'supreme_court', $result->court() );
		$this->assertSame( 'uncontested_divorce_children_nyc', $result->workflow() );
		$this->assertContains( 'supreme_court', $result->courts() );
		$this->assertFalse( $result->overlap() );
	}

	/**
	 * Divorce with children resolves to uncontested divorce children workflow.
	 */
	public function test_divorce_with_children(): void {
		$result = $this->engine->route( 'I want a divorce and we have two children.' );

		$this->assertSame( 'divorce_with_children', $result->issue() );
		$this->assertSame( 'supreme_court', $result->court() );
		$this->assertSame( 'uncontested_divorce_children_nyc', $result->workflow() );
		$this->assertGreaterThan( 0.0, $result->confidence() );
	}

	/**
	 * Custody resolves to custody workflow.
	 */
	public function test_custody(): void {
		$result = $this->engine->route( 'I want custody of my son.' );

		$this->assertSame( 'custody', $result->issue() );
		$this->assertSame( 'family_court', $result->court() );
		$this->assertSame( 'custody_nyc', $result->workflow() );
		$this->assertContains( 'GF-17', $result->required_form_codes() );
	}

	/**
	 * Child support resolves to child support workflow.
	 */
	public function test_child_support(): void {
		$result = $this->engine->route( 'My ex is not paying child support.' );

		$this->assertSame( 'child_support', $result->issue() );
		$this->assertSame( 'family_court', $result->court() );
		$this->assertSame( 'child_support_nyc', $result->workflow() );
	}

	/**
	 * Divorce context overrides standalone custody workflow.
	 */
	public function test_divorce_override_custody(): void {
		$result = $this->engine->route( 'I am getting divorced and we have children.' );

		$this->assertSame( 'supreme_court', $result->court() );
		$this->assertSame( 'uncontested_divorce_children_nyc', $result->workflow() );
		$this->assertNotSame( 'custody_nyc', $result->workflow() );
	}

	/**
	 * Contested divorce resolves correctly.
	 */
	public function test_contested_divorce(): void {
		$result = $this->engine->route(
			'My spouse will not agree to the divorce.',
			array( 'children' => true )
		);

		$this->assertSame( 'divorce', $result->issue() );
		$this->assertSame( 'contested_divorce_nyc', $result->workflow() );
	}

	/**
	 * Uncontested divorce without children resolves correctly.
	 */
	public function test_uncontested_divorce_no_children(): void {
		$result = $this->engine->route(
			'We both agree to divorce without children.',
			array( 'children' => false, 'spouse_agrees' => true )
		);

		$this->assertSame( 'uncontested_divorce_no_children_nyc', $result->workflow() );
	}

	/**
	 * Default divorce resolves when spouse did not respond.
	 */
	public function test_default_divorce(): void {
		$result = $this->engine->route(
			'My spouse did not respond to the divorce papers.',
			array( 'spouse_responded' => false )
		);

		$this->assertSame( 'default_divorce_nyc', $result->workflow() );
	}

	/**
	 * Family offense workflow.
	 */
	public function test_family_offense(): void {
		$result = $this->engine->route( 'My husband abused me.' );

		$this->assertSame( 'family_offense', $result->issue() );
		$this->assertSame( 'family_court', $result->court() );
		$this->assertSame( 'family_offense_nyc', $result->workflow() );
	}

	/**
	 * Order of protection workflow.
	 */
	public function test_order_of_protection(): void {
		$result = $this->engine->route( 'I need an order of protection.' );

		$this->assertSame( 'order_of_protection', $result->issue() );
		$this->assertSame( 'order_of_protection_nyc', $result->workflow() );
	}

	/**
	 * Visitation workflow.
	 */
	public function test_visitation(): void {
		$result = $this->engine->route( 'I want visitation with my child.' );

		$this->assertSame( 'visitation', $result->issue() );
		$this->assertSame( 'visitation_nyc', $result->workflow() );
	}

	/**
	 * Paternity workflow.
	 */
	public function test_paternity(): void {
		$result = $this->engine->route( 'I need to establish paternity.' );

		$this->assertSame( 'paternity', $result->issue() );
		$this->assertSame( 'paternity_nyc', $result->workflow() );
	}

	/**
	 * Guardianship workflow.
	 */
	public function test_guardianship(): void {
		$result = $this->engine->route( 'I want to become the legal guardian of my niece.' );

		$this->assertSame( 'guardianship', $result->issue() );
		$this->assertSame( 'guardianship_nyc', $result->workflow() );
	}

	/**
	 * Adoption workflow.
	 */
	public function test_adoption(): void {
		$result = $this->engine->route( 'I want to adopt a child.' );

		$this->assertSame( 'adoption', $result->issue() );
		$this->assertSame( 'adoption_nyc', $result->workflow() );
	}

	/**
	 * Case profile route writes result back to profile.
	 */
	public function test_route_profile_updates_case_profile(): void {
		$profile = new Case_Profile();
		$result  = $this->engine->route_profile( 'I want custody of my son.', $profile );

		$this->assertSame( 'custody_nyc', $result->workflow() );
		$this->assertSame( 'custody_nyc', $profile->workflow() );
		$this->assertSame( 'custody', $profile->issue() );
		$this->assertSame( 'family_court', $profile->court() );
	}

	/**
	 * Short follow-up answers retain the session issue and routing candidates.
	 */
	public function test_route_profile_retains_issue_on_short_answer(): void {
		$profile = Case_Profile::from_array(
			array(
				'issue'               => 'divorce',
				'court'               => 'supreme_court',
				'facts'               => array( 'children' => false ),
				'candidate_workflows' => array(
					'contested_divorce_nyc',
					'uncontested_divorce_children_nyc',
					'uncontested_divorce_no_children_nyc',
				),
			)
		);

		$result = $this->engine->route_profile( 'No', $profile );

		$this->assertSame( 'divorce', $result->issue() );
		$this->assertSame( 'supreme_court', $result->court() );
		$this->assertSame( 'uncontested_divorce_no_children_nyc', $result->workflow() );
	}

	/**
	 * Mid-divorce fact answers must not reroute on the OP trigger inside "property".
	 */
	public function test_property_answer_does_not_reroute_to_order_of_protection(): void {
		$profile = Case_Profile::from_array(
			array(
				'issue'    => 'divorce',
				'court'    => 'supreme_court',
				'workflow' => 'uncontested_divorce_no_children_nyc',
				'facts'    => array(
					'county'        => 'Queens',
					'spouse_agrees' => true,
				),
			)
		);

		$result = $this->engine->route_profile(
			'My spouse agrees. No children under 21. Property and support are agreed.',
			$profile
		);

		$this->assertSame( 'divorce', $result->issue() );
		$this->assertSame( 'uncontested_divorce_no_children_nyc', $result->workflow() );
	}

	/**
	 * Active divorce redirects standalone custody to Supreme Court divorce workflow.
	 */
	public function test_active_divorce_custody_routes_to_supreme(): void {
		$result = $this->engine->route(
			'I need custody, we are already in a divorce',
			array( 'active_divorce' => true )
		);

		$this->assertSame( 'supreme_court', $result->court() );
		$this->assertSame( 'uncontested_divorce_children_nyc', $result->workflow() );
		$this->assertSame( array( 'supreme_court' ), $result->courts() );
		$this->assertFalse( $result->overlap() );
		$this->assertNotEmpty( $result->routing_note() );
	}

	/**
	 * Child support without divorce stays in Family Court.
	 */
	public function test_child_support_no_divorce(): void {
		$result = $this->engine->route( 'I need child support, no divorce' );

		$this->assertSame( 'child_support', $result->issue() );
		$this->assertSame( 'family_court', $result->court() );
		$this->assertSame( 'child_support_nyc', $result->workflow() );
		$this->assertFalse( $result->overlap() );
	}

	/**
	 * Divorce plus order of protection flags overlap across both courts.
	 */
	public function test_divorce_and_order_of_protection_overlap(): void {
		$result = $this->engine->route( 'I want a divorce and I need an order of protection' );

		$this->assertTrue( $result->overlap() );
		$this->assertContains( 'supreme_court', $result->courts() );
		$this->assertContains( 'family_court', $result->courts() );
		$this->assertSame( 'divorce_and_order_of_protection', $result->overlap_reason() );
		$this->assertNotEmpty( $result->routing_explanation() );
	}
}
