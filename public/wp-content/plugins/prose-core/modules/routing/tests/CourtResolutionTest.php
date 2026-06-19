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

	/**
	 * Active divorce signal routes family-court issues to Supreme Court context.
	 */
	public function test_active_divorce_signal_routes_custody_to_supreme(): void {
		$resolver = new Court_Resolver();
		$result   = $resolver->resolve( 'custody', array( 'active_divorce' ) );

		$this->assertSame( 'supreme_court', $result );
	}

	/**
	 * Order of protection stays in Family Court even when divorce is also mentioned.
	 */
	public function test_order_of_protection_stays_family_with_divorce_signals(): void {
		$resolver = new Court_Resolver();
		$result   = $resolver->resolve( 'order_of_protection', array( 'divorce', 'order of protection' ) );

		$this->assertSame( 'family_court', $result );
	}
}
