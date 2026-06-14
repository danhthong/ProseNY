<?php
/**
 * Guidance resolver — next steps from workflow stages and county instructions.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Guidance_Resolver
 */
final class Guidance_Resolver {

	/**
	 * Resolve ordered next steps from a workflow definition.
	 *
	 * @param array<string, mixed> $definition Workflow definition.
	 * @return array<int, array{order: int, id: string, title: string}>
	 */
	public function next_steps( array $definition ): array {
		$stages = is_array( $definition['stages'] ?? null ) ? $definition['stages'] : array();
		$steps  = array();
		$order  = 1;

		foreach ( $stages as $stage ) {
			$stage_id = trim( (string) $stage );

			if ( '' === $stage_id ) {
				continue;
			}

			$steps[] = array(
				'order' => $order,
				'id'    => $stage_id,
				'title' => $this->stage_title( $stage_id ),
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
