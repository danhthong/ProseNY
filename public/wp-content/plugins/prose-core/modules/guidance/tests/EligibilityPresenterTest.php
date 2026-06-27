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

	/**
	 * Non-NYC county returns needs_more_info.
	 */
	public function test_non_nyc_county_needs_more_info(): void {
		$presenter = new Eligibility_Presenter();
		$result    = $presenter->evaluate(
			array(
				'county'                  => 'Albany',
				'residency_qualification' => '1_year_state',
			)
		);

		$this->assertSame( Eligibility_Presenter::STATUS_NEEDS_MORE_INFO, $result['status'] );
	}

	/**
	 * Ineligible residency is flagged even when county is unknown.
	 */
	public function test_ineligible_residency_without_county(): void {
		$presenter = new Eligibility_Presenter();
		$result    = $presenter->evaluate(
			array(
				'residency_qualification' => 'ineligible',
			)
		);

		$this->assertSame( Eligibility_Presenter::STATUS_LIKELY_INELIGIBLE, $result['status'] );
	}

	/**
	 * DV concern still eligible with OP note.
	 */
	public function test_dv_concern_eligible_with_op_note(): void {
		$presenter = new Eligibility_Presenter();
		$result    = $presenter->evaluate(
			array(
				'county'                    => 'Queens',
				'residency_qualification'   => '1_year_state',
				'domestic_violence_concerns' => true,
			)
		);

		$this->assertSame( Eligibility_Presenter::STATUS_ELIGIBLE, $result['status'] );
		$this->assertStringContainsString( 'protection', strtolower( (string) ( $result['reason'] ?? '' ) ) );
	}
}
