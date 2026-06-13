<?php
/**
 * Missing Info Detector — identifies unresolved discriminator facts.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Routing\Validators;

use ProSe\Core\Routing\Fact_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Missing_Info_Detector
 */
final class Missing_Info_Detector {

	/**
	 * Decision key catalog.
	 *
	 * @var Decision_Key_Catalog
	 */
	private Decision_Key_Catalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param Decision_Key_Catalog|null $catalog Decision key catalog.
	 */
	public function __construct( ?Decision_Key_Catalog $catalog = null ) {
		$this->catalog = $catalog ?? new Decision_Key_Catalog();
	}

	/**
	 * Detect missing fields for candidate workflows.
	 *
	 * @param string[]                           $candidate_workflows Candidate workflow keys.
	 * @param Fact_Store|array<string, mixed>    $facts               Known facts.
	 * @return string[]
	 */
	public function detect( array $candidate_workflows, $facts ): array {
		if ( empty( $candidate_workflows ) ) {
			return array();
		}

		$store = $facts instanceof Fact_Store ? $facts : Fact_Store::from_array( (array) $facts );
		$keys  = $this->catalog->keys_for_candidates( $candidate_workflows );
		$missing = array();

		foreach ( $keys as $key ) {
			if ( ! $store->has( $key ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}
}
