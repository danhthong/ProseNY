<?php
/**
 * Eligibility presenter tests.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Guidance\Eligibility_Presenter;

/**
 * Class EligibilityPresenterTest
 */
class EligibilityPresenterTest extends TestCase {

	/**
	 * Missing county returns needs_more_info.
	 */
	public function test_missing_county_needs_more_info(): void {
		$presenter = new Eligibility_Presenter();
		$result    = $presenter->evaluate( array() );

		$this->assertSame( Eligibility_Presenter::STATUS_NEEDS_MORE_INFO, $result['status'] );
	}

	/**
	 * Valid NYC county and residency returns eligible.
	 */
	public function test_nyc_residency_eligible(): void {
		$presenter = new Eligibility_Presenter();
		$result    = $presenter->evaluate(
			array(
				'county'                  => 'Queens',
				'residency_qualification' => '1_year_state',
			)
		);

		$this->assertSame( Eligibility_Presenter::STATUS_ELIGIBLE, $result['status'] );
	}

	/**
	 * Likely ineligible blocks package generation.
	 */
	public function test_ineligible_blocks_package(): void {
		$presenter = new Eligibility_Presenter();
		$blocked   = $presenter->blocks_package(
			array(
				'county'                  => 'Queens',
				'residency_qualification' => 'ineligible',
			)
		);

		$this->assertTrue( $blocked );
	}
}
