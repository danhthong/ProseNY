<?php
/**
 * Eligibility presenter — deterministic NY residency / filing readiness checks.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Eligibility_Presenter
 */
final class Eligibility_Presenter {

	public const STATUS_ELIGIBLE          = 'eligible';
	public const STATUS_NEEDS_MORE_INFO   = 'needs_more_info';
	public const STATUS_LIKELY_INELIGIBLE = 'likely_ineligible';

	/**
	 * Evaluate filing eligibility from intake facts.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return array<string, mixed>
	 */
	public function evaluate( array $facts ): array {
		$county = trim( (string) ( $facts['county'] ?? '' ) );
		$qual   = sanitize_key( (string) ( $facts['residency_qualification'] ?? '' ) );

		if ( in_array( $qual, array( 'none', 'not_met', 'ineligible' ), true ) ) {
			return $this->result(
				self::STATUS_LIKELY_INELIGIBLE,
				__( 'Based on the information provided, New York residency requirements may not be met. You may wish to verify eligibility with the court or a legal resource before filing.', 'prose-core' ),
				array()
			);
		}

		if ( '' === $county ) {
			return $this->result(
				self::STATUS_NEEDS_MORE_INFO,
				__( 'County of residence is needed before filing guidance can be provided.', 'prose-core' ),
				array( 'county', 'residency_qualification' )
			);
		}

		if ( '' === $qual ) {
			return $this->result(
				self::STATUS_NEEDS_MORE_INFO,
				__( 'New York residency should be confirmed before recommending a filing county.', 'prose-core' ),
				array( 'residency_qualification' )
			);
		}

		$valid_counties = array( 'New York', 'Kings', 'Queens', 'Bronx', 'Richmond' );

		if ( ! in_array( $county, $valid_counties, true ) ) {
			return $this->result(
				self::STATUS_NEEDS_MORE_INFO,
				__( 'CourtFlow currently supports the five NYC counties. Confirm which borough you reside in.', 'prose-core' ),
				array( 'county' )
			);
		}

		$message = __( 'Residency and county information appear sufficient to proceed with NYC intake and form identification.', 'prose-core' );

		if ( ! empty( $facts['domestic_violence_concerns'] ) || ! empty( $facts['protection_needed'] ) ) {
			$message .= ' ' . __( 'If safety is a concern, an order of protection matter may also apply in Family Court.', 'prose-core' );
		}

		return $this->result( self::STATUS_ELIGIBLE, $message, array() );
	}

	/**
	 * Whether package generation should be blocked.
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	public function blocks_package( array $facts ): bool {
		$result = $this->evaluate( $facts );

		return self::STATUS_LIKELY_INELIGIBLE === ( $result['status'] ?? '' );
	}

	/**
	 * @param string              $status Status.
	 * @param string              $reason Reason.
	 * @param array<int, string>  $missing Missing fact keys.
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $reason, array $missing ): array {
		return array(
			'status'  => $status,
			'reason'  => $reason,
			'missing' => array_values( $missing ),
			'show'    => true,
		);
	}
}
