<?php
/**
 * Guidance resolver — next steps from workflow stages and county instructions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural;

use ProSe\Core\Forms\Engine\Workflow_Progression_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Guidance_Resolver
 */
final class Guidance_Resolver {

	/**
	 * Progression service.
	 *
	 * @var Workflow_Progression_Service
	 */
	private Workflow_Progression_Service $progression;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Progression_Service|null $progression Progression service.
	 */
	public function __construct( ?Workflow_Progression_Service $progression = null ) {
		$this->progression = $progression ?? new Workflow_Progression_Service();
	}

	/**
	 * Resolve ordered next steps from a workflow definition.
	 *
	 * @param array<string, mixed>      $definition     Workflow definition.
	 * @param string|null               $current_stage  Optional current stage slug.
	 * @param string|null               $current_node   Optional current node key.
	 * @return array<int, array{order: int, id: string, title: string, current: bool, forms: array<int, array{code: string, title: string, required: bool}>}>
	 */
	public function next_steps( array $definition, ?string $current_stage = null, ?string $current_node = null ): array {
		$workflow_key = (string) ( $definition['workflow'] ?? '' );
		$stages       = $this->progression->get_stages( $workflow_key );

		if ( empty( $stages ) ) {
			$stages = is_array( $definition['stages'] ?? null ) ? $definition['stages'] : array();
		}

		if ( null === $current_stage || '' === $current_stage ) {
			if ( null !== $current_node && '' !== $current_node && '' !== $workflow_key ) {
				$current_stage = $this->progression->get_current_stage( $workflow_key, $current_node );
			}
		}

		$steps = array();
		$order = 1;

		foreach ( $stages as $stage ) {
			$stage_id = trim( (string) $stage );

			if ( '' === $stage_id ) {
				continue;
			}

			$steps[] = array(
				'order'   => $order,
				'id'      => $stage_id,
				'title'   => $this->stage_title( $stage_id ),
				'current' => null !== $current_stage && $current_stage === $stage_id,
				'forms'   => '' !== $workflow_key
					? $this->progression->get_stage_forms( $workflow_key, $stage_id )
					: array(),
			);

			++$order;
		}

		return $steps;
	}

	/**
	 * Resolve county-specific filing instructions scaffold.
	 *
	 * @param string $county      County name.
	 * @param string $court_label Human-readable court label.
	 * @return array{county: string, court: string, filing_location: null, website: null, phone: null, notes: string[]}
	 */
	public function instructions( string $county, string $court_label ): array {
		$instructions = array(
			'county'          => $county,
			'court'           => $court_label,
			'filing_location' => null,
			'website'         => null,
			'phone'           => null,
			'notes'           => array(),
		);

		/**
		 * Filter county-specific filing instructions.
		 *
		 * @param array<string, mixed> $instructions Instruction scaffold.
		 * @param string               $county       County name.
		 * @param string               $court_label  Court label.
		 */
		return apply_filters( 'prose_procedural_county_instructions', $instructions, $county, $court_label );
	}

	/**
	 * Convert a stage slug to a human-readable title.
	 *
	 * @param string $stage_id Stage slug.
	 * @return string
	 */
	private function stage_title( string $stage_id ): string {
		$words = explode( '_', $stage_id );
		$words = array_map(
			static function ( string $word ): string {
				return ucfirst( strtolower( $word ) );
			},
			$words
		);

		return implode( ' ', $words );
	}
}
