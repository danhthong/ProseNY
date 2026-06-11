<?php
/**
 * Field resolver — resolve canonical field values from case data.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

use ProSe\Core\Forms\Engine\Case_Catalog;
use ProSe\Core\Forms\Engine\Case_Event;
use ProSe\Core\Forms\Engine\Case_State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Field_Resolver
 *
 * Resolves the value of canonical document fields from the layered case
 * inputs, in deterministic priority order:
 *
 *   1. previously generated forms (so later forms inherit earlier values)
 *   2. case profile + intake answers (with alias normalization)
 *   3. workflow data (service / answer / hearing dates derived from events)
 *   4. court metadata (court routing, index number)
 *   5. county metadata (filing county)
 *   6. catalog default values
 *
 * Pure and DB-free: operates entirely on a Case_State aggregate and an
 * optional context array, mirroring the repository-optional engines.
 */
final class Field_Resolver {

	/**
	 * Resolve a set of canonical field keys for a case.
	 *
	 * @param string[]             $keys    Canonical field keys.
	 * @param Case_State           $state   Case state.
	 * @param array<string, mixed> $context Extra context:
	 *                                       generated => array<string,mixed>,
	 *                                       court     => array<string,mixed>,
	 *                                       county    => array<string,mixed>.
	 * @return Field_Resolution_Result
	 */
	public function resolve( array $keys, Case_State $state, array $context = array() ): Field_Resolution_Result {
		$answers   = $this->apply_aliases( $state->answers() );
		$workflow  = $this->workflow_data( $state );
		$generated = $this->apply_aliases( (array) ( $context['generated'] ?? array() ) );
		$court     = $this->court_metadata( $state, (array) ( $context['court'] ?? array() ) );
		$county    = $this->county_metadata( $state, (array) ( $context['county'] ?? array() ) );

		$chain = array(
			array(
				'source' => Field_Catalog::SOURCE_GENERATED,
				'map'    => $generated,
			),
			array(
				'source' => Field_Catalog::SOURCE_ANSWERS,
				'map'    => $answers,
			),
			array(
				'source' => Field_Catalog::SOURCE_WORKFLOW,
				'map'    => $workflow,
			),
			array(
				'source' => Field_Catalog::SOURCE_COURT,
				'map'    => $court,
			),
			array(
				'source' => Field_Catalog::SOURCE_COUNTY,
				'map'    => $county,
			),
		);

		$fields = array();

		foreach ( $keys as $raw_key ) {
			$key = Field_Catalog::canonical( (string) $raw_key );

			if ( '' === $key ) {
				continue;
			}

			$fields[ $key ] = $this->resolve_one( $key, $chain );
		}

		return new Field_Resolution_Result( $fields );
	}

	/**
	 * Resolve a single canonical field against the source chain.
	 *
	 * @param string                                            $key   Canonical key.
	 * @param array<int, array{source: string, map: array<string, mixed>}> $chain Sources.
	 * @return Generated_Field
	 */
	private function resolve_one( string $key, array $chain ): Generated_Field {
		$label         = Field_Catalog::label( $key );
		$native_source = Field_Catalog::source( $key );

		foreach ( $chain as $link ) {
			$map = $link['map'];

			if ( ! array_key_exists( $key, $map ) ) {
				continue;
			}

			$value = $map[ $key ];

			if ( ! $this->has_value( $value ) ) {
				continue;
			}

			$source = $link['source'];

			// Promote identity fields read from answers to the profile source.
			if ( Field_Catalog::SOURCE_ANSWERS === $source && Field_Catalog::SOURCE_PROFILE === $native_source ) {
				$source = Field_Catalog::SOURCE_PROFILE;
			}

			return new Generated_Field( $key, $label, $value, $source, false, true, false );
		}

		$default = Field_Catalog::default_for( $key );

		if ( $this->has_value( $default ) ) {
			return new Generated_Field( $key, $label, $default, Field_Catalog::SOURCE_DEFAULT, false, true, true );
		}

		return new Generated_Field( $key, $label, null, '', false, false, false );
	}

	/**
	 * Normalize an answer/value map: map alias keys to canonical keys.
	 *
	 * Exact canonical keys take precedence over aliases.
	 *
	 * @param array<string, mixed> $map Raw map.
	 * @return array<string, mixed>
	 */
	public function apply_aliases( array $map ): array {
		$normalized = array();

		// First pass: keys that are already canonical.
		foreach ( $map as $name => $value ) {
			$canonical = Field_Catalog::canonical( (string) $name );

			if ( strtolower( trim( (string) $name ) ) === $canonical && Field_Catalog::has_field( $canonical ) ) {
				$normalized[ $canonical ] = $value;
			}
		}

		// Second pass: aliases that do not clobber an explicit canonical key.
		foreach ( $map as $name => $value ) {
			$canonical = Field_Catalog::canonical( (string) $name );

			if ( ! array_key_exists( $canonical, $normalized ) ) {
				$normalized[ $canonical ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Derive workflow-data fields from recorded lifecycle events.
	 *
	 * @param Case_State $state Case state.
	 * @return array<string, mixed>
	 */
	public function workflow_data( Case_State $state ): array {
		$data = array();

		$event_field = array(
			Case_Catalog::EVENT_SERVICE_COMPLETED => 'service_date',
			Case_Catalog::EVENT_ANSWER_RECEIVED   => 'answer_date',
			Case_Catalog::EVENT_HEARING_SCHEDULED => 'hearing_date',
		);

		foreach ( $state->events() as $event ) {
			if ( ! $event instanceof Case_Event ) {
				continue;
			}

			$field = $event_field[ $event->event_type() ] ?? '';

			if ( '' === $field ) {
				continue;
			}

			$payload = $event->payload();
			$date    = (string) ( $payload['date'] ?? $payload['occurred_at'] ?? $event->occurred_at() );

			if ( '' !== $date ) {
				$data[ $field ] = $date;
			}
		}

		return $data;
	}

	/**
	 * Build the court metadata map.
	 *
	 * @param Case_State           $state    Case state.
	 * @param array<string, mixed> $metadata Supplied court metadata.
	 * @return array<string, mixed>
	 */
	private function court_metadata( Case_State $state, array $metadata ): array {
		$map = $this->apply_aliases( $metadata );

		if ( '' !== $state->court_routing() && ! $this->has_value( $map['court'] ?? null ) ) {
			$map['court'] = $state->court_routing();
		}

		return $map;
	}

	/**
	 * Build the county metadata map.
	 *
	 * @param Case_State           $state    Case state.
	 * @param array<string, mixed> $metadata Supplied county metadata.
	 * @return array<string, mixed>
	 */
	private function county_metadata( Case_State $state, array $metadata ): array {
		$map = $this->apply_aliases( $metadata );

		if ( '' !== $state->county() && ! $this->has_value( $map['county'] ?? null ) ) {
			$map['county'] = $state->county();
		}

		return $map;
	}

	/**
	 * Whether a raw value counts as present (0 and '0' are present; null/'' are not).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function has_value( $value ): bool {
		if ( null === $value ) {
			return false;
		}

		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}

		if ( is_array( $value ) ) {
			return ! empty( $value );
		}

		return true;
	}
}
