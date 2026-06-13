<?php
/**
 * Decision Key Catalog — discriminator keys for workflow ambiguity.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Validators;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Decision_Key_Catalog
 */
final class Decision_Key_Catalog {

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null $catalog Workflow catalog.
	 */
	public function __construct( ?Workflow_Catalog $catalog = null ) {
		$this->catalog = $catalog ?? new Workflow_Catalog();
	}

	/**
	 * Decision keys for candidate workflows.
	 *
	 * @param string[] $candidate_keys Candidate workflow keys.
	 * @return string[]
	 */
	public function keys_for_candidates( array $candidate_keys ): array {
		$keys = array();

		foreach ( $candidate_keys as $workflow_key ) {
			$workflow = $this->catalog->by_key( $workflow_key );

			if ( null === $workflow ) {
				continue;
			}

			foreach ( (array) ( $workflow['routing_rules'] ?? array() ) as $rule ) {
				$condition = (string) ( $rule['condition'] ?? '' );
				$key       = $this->condition_key( $condition );

				if ( null !== $key ) {
					$keys[] = $key;
				}
			}
		}

		$keys = array_merge( $keys, $this->fallback_keys( $candidate_keys ) );

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Fallback discriminator keys based on candidate set.
	 *
	 * @param string[] $candidate_keys Candidate workflow keys.
	 * @return string[]
	 */
	private function fallback_keys( array $candidate_keys ): array {
		$keys = array();

		$has_divorce = false;

		foreach ( $candidate_keys as $key ) {
			if ( str_contains( $key, 'divorce' ) ) {
				$has_divorce = true;
				break;
			}
		}

		if ( $has_divorce ) {
			$keys[] = 'children';
			$keys[] = 'spouse_agrees';

			if ( in_array( 'default_divorce_nyc', $candidate_keys, true ) ) {
				$keys[] = 'spouse_responded';
			}
		}

		return $keys;
	}

	/**
	 * Extract key from a condition string.
	 *
	 * @param string $condition Condition.
	 * @return string|null
	 */
	private function condition_key( string $condition ): ?string {
		$parts = explode( '=', $condition, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$key = trim( $parts[0] );

		return '' !== $key ? $key : null;
	}
}
