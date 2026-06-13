<?php
/**
 * Court resolution tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Routing\Resolver\Court_Resolver;

/**
 * Class CourtResolutionTest
 */
class CourtResolutionTest extends TestCase {

	/**
	 * Issue to court mapping provider.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function issue_court_provider(): array {
		return array(
			'divorce'              => array( 'divorce', 'supreme_court' ),
			'divorce_with_children'=> array( 'divorce_with_children', 'supreme_court' ),
			'custody'              => array( 'custody', 'family_court' ),
			'visitation'           => array( 'visitation', 'family_court' ),
			'child_support'        => array( 'child_support', 'family_court' ),
			'family_offense'       => array( 'family_offense', 'family_court' ),
			'order_of_protection'  => array( 'order_of_protection', 'family_court' ),
			'paternity'            => array( 'paternity', 'family_court' ),
			'guardianship'         => array( 'guardianship', 'family_court' ),
			'adoption'             => array( 'adoption', 'family_court' ),
		);
	}

	/**
	 * Court resolver maps issues to courts from repository metadata.
	 *
	 * @dataProvider issue_court_provider
	 *
	 * @param string $issue Issue.
	 * @param string $court Expected court.
	 */
	public function test_issue_maps_to_court( string $issue, string $court ): void {
		$resolver = new Court_Resolver();
		$result   = $resolver->resolve( $issue );

		$this->assertSame( $court, $result );
	}
}
