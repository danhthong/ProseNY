<?php
/**
 * Fact Extractor — deterministic, workflow-aware fact extraction.
 *
 * No external LLMs. All extraction uses workflow required_field definitions
 * and engine-owned pattern matching (interpretation config, like Signal_Lexicon).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Fact_Extractor
 */
final class Fact_Extractor {

	/**
	 * NYC borough / colloquial name to official county name.
	 *
	 * Ordered longest-first so multi-word tokens match before substrings.
	 *
	 * @var array<string, string>
	 */
	private const COUNTY_MAP = array(
		'new york county' => 'New York',
		'staten island'   => 'Richmond',
		'the bronx'       => 'Bronx',
		'manhattan'       => 'New York',
		'brooklyn'        => 'Kings',
		'richmond'        => 'Richmond',
		'queens'          => 'Queens',
		'queen'           => 'Queens',
		'bronx'           => 'Bronx',
		'kings'           => 'Kings',
	);

	/**
	 * Number words to integers.
	 *
	 * @var array<string, int>
	 */
	private const NUMBER_WORDS = array(
		'zero'  => 0,
		'one'   => 1,
		'two'   => 2,
		'three' => 3,
		'four'  => 4,
		'five'  => 5,
		'six'   => 6,
		'seven' => 7,
		'eight' => 8,
		'nine'  => 9,
		'ten'   => 10,
	);

	/**
	 * Affirmation cues.
	 *
	 * @var string[]
	 */
	private const AFFIRM = array( 'yes', 'yeah', 'yep', 'yup', 'correct', 'true', 'agreed', 'agree', 'sure', 'absolutely', 'we do', 'i do', 'we have', 'i have' );

	/**
	 * Negation cues.
	 *
	 * @var string[]
	 */
	private const NEGATE = array( 'no', 'nope', 'nah', 'not', 'none', 'false', 'never', 'we do not', 'i do not', 'we don t', 'i don t', 'disagree' );

	/**
	 * Workflow catalog (used for shared normalization).
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
	 * Extract structured content signals from a message for a workflow.
	 *
	 * Returns only non-null facts keyed by required_field keys. Handles the
	 * well-known signals (county, child_count, has_minor_children) regardless of
	 * which question is pending so first-message extraction works.
	 *
	 * @param string                            $message         User message.
	 * @param array<int, array<string, mixed>>  $required_fields Workflow required_fields.
	 * @param array<string, mixed>              $existing_facts  Facts already known.
	 * @return array<string, mixed>
	 */
	public function extract( string $message, array $required_fields, array $existing_facts = array() ): array {
		$normalized = $this->catalog->normalize_text( $message );
		$keys       = $this->field_types( $required_fields );
		$facts      = array();

		$child_count = $this->extract_child_count( $normalized );

		if ( null !== $child_count ) {
			$facts['child_count'] = $child_count;
		} elseif ( isset( $keys['child_count'] ) && $this->is_zero_count_answer( $normalized, 'child_count' ) ) {
			$facts['child_count'] = 0;
		}

		$county = $this->extract_county( $normalized );

		if ( null !== $county && ! $this->should_defer_county_to_marriage_location( $message, $keys, $existing_facts ) ) {
			$facts['county'] = $county;
		}

		if ( isset( $keys['has_minor_children'] ) ) {
			$has_children = $this->extract_has_children( $normalized, $existing_facts, $child_count );

			if ( null !== $has_children ) {
				$facts['has_minor_children'] = $has_children;
			}
		}

		$residency = $this->extract_residency_qualification( $normalized );

		if ( null !== $residency ) {
			$facts['residency_qualification'] = $residency;
		}

		if ( isset( $keys['marriage_date'] ) || isset( $keys['separation_date'] ) ) {
			foreach ( Date_Parser::extract_marriage_and_separation( $message ) as $key => $value ) {
				if ( isset( $keys[ $key ] ) && ! isset( $facts[ $key ] ) ) {
					$facts[ $key ] = $value;
				}
			}
		}

		return $this->strip_nulls( $facts );
	}

	/**
	 * Interpret a message as the direct answer to a pending field.
	 *
	 * Used for multi-turn intake: when the agent asked about $field_key, the
	 * user's next message is the answer. Typing is inferred from the field type
	 * when known, otherwise from the message content.
	 *
	 * @param string      $message   User message.
	 * @param string      $field_key Pending field key.
	 * @param string|null $type      Field type when known (string|integer|boolean|date|array).
	 * @return array<string, mixed> Single-entry fact array, or empty when nothing usable.
	 */
	public function infer_pending_answer( string $message, string $field_key, ?string $type = null ): array {		if ( '' === $field_key ) {
			return array();
		}

		$normalized = $this->catalog->normalize_text( $message );
		$trimmed    = trim( $message );

		if ( '' === $trimmed ) {
			return array();
		}

		switch ( $type ) {
			case 'integer':
				$value = $this->parse_integer( $normalized );

				if ( null === $value && $this->is_zero_count_answer( $normalized, $field_key ) ) {
					$value = 0;
				}

				return null === $value ? array() : array( $field_key => $value );

			case 'boolean':
				$value = $this->parse_boolean( $normalized );

				return null === $value ? array() : array( $field_key => $value );

			case 'date':
				$value = Date_Parser::parse( $trimmed );

				return null !== $value ? array( $field_key => $value ) : array();

			case 'array':
				return array( $field_key => $this->parse_list( $trimmed ) );

			case 'string':
				if ( 'county' === $field_key ) {
					$county = $this->extract_county( $normalized );

					return array( $field_key => ( null !== $county ? $county : $trimmed ) );
				}

				if ( 'marriage_location' === $field_key ) {
					$place = $this->format_place_answer( $trimmed );

					return array( $field_key => null !== $place ? $place : $trimmed );
				}

				return array( $field_key => $trimmed );
		}

		// Unknown type (e.g. discriminator pending before workflow resolves): infer.
		if ( 'county' === $field_key ) {
			$county = $this->extract_county( $normalized );

			if ( null !== $county ) {
				return array( $field_key => $county );
			}
		}

		if ( 'marriage_location' === $field_key ) {
			$place = $this->format_place_answer( $trimmed );

			return array( $field_key => null !== $place ? $place : $trimmed );
		}

		if ( in_array( $field_key, array( 'marriage_date', 'separation_date' ), true ) ) {
			$parsed = Date_Parser::parse( $trimmed );

			return null !== $parsed ? array( $field_key => $parsed ) : array();
		}

		$boolean = $this->parse_boolean( $normalized );

		if ( null !== $boolean && $this->is_boolean_discriminator( $field_key ) ) {
			return array( $field_key => $boolean );
		}

		if ( $this->is_children_discriminator( $field_key ) ) {
			$residency = $this->extract_residency_qualification( $normalized );

			if ( null !== $residency ) {
				return array( 'residency_qualification' => $residency );
			}

			$child_count = $this->extract_child_count( $normalized );

			if ( null !== $child_count ) {
				return array(
					'child_count'         => $child_count,
					'has_minor_children'  => $child_count > 0,
					'children'            => $child_count > 0,
				);
			}

			if ( $this->is_zero_count_answer( $normalized, 'child_count' ) ) {
				return array(
					'child_count'        => 0,
					'has_minor_children' => false,
					'children'           => false,
				);
			}

			return array();
		}

		$integer = $this->parse_integer( $normalized );

		if ( null !== $integer && 'child_count' === $field_key ) {
			return array( $field_key => $integer );
		}

		if ( null !== $integer && ! $this->looks_like_residency_duration( $normalized ) ) {
			return array( $field_key => $integer );
		}

		if ( $this->is_zero_count_answer( $normalized, $field_key ) ) {
			return array( $field_key => 0 );
		}

		return array( $field_key => $trimmed );
	}

	/**
	 * Strictly parse a value of a given type, returning null when the message
	 * does not actually contain a value of that type.
	 *
	 * Unlike infer_pending_answer(), this never falls back to the raw text for
	 * date/integer/boolean fields — so a correction such as "sorry, two kids"
	 * is not mistaken for a date or a name answer.
	 *
	 * @param string $message User message.
	 * @param string $type    Field type.
	 * @return mixed Parsed value, or null when not present.
	 */
	public function strict_value( string $message, string $type ) {
		$normalized = $this->catalog->normalize_text( $message );
		$trimmed    = trim( $message );

		switch ( $type ) {
			case 'date':
				return $this->parse_date( $trimmed );
			case 'integer':
				$value = $this->parse_integer( $normalized );

				if ( null === $value && $this->is_zero_count_answer( $normalized, 'child_count' ) ) {
					return 0;
				}

				return $value;
			case 'boolean':
				return $this->parse_boolean( $normalized );
			default:
				return '' === $trimmed ? null : $trimmed;
		}
	}

	/**
	 * Build a key => type index from required_fields.
	 *
	 * @param array<int, array<string, mixed>> $required_fields Required fields.
	 * @return array<string, string>
	 */
	private function field_types( array $required_fields ): array {
		$types = array();

		foreach ( $required_fields as $field ) {
			$key = (string) ( $field['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$types[ $key ] = (string) ( $field['type'] ?? 'string' );
		}

		return $types;
	}

	/**
	 * Extract a child count from normalized text.
	 *
	 * @param string $normalized Normalized text.
	 * @return int|null
	 */
	private function extract_child_count( string $normalized ): ?int {
		if ( preg_match( '/\b(\d+)\s+(?:children|child|kids|kid|minor children)\b/', $normalized, $matches ) ) {
			return (int) $matches[1];
		}

		foreach ( self::NUMBER_WORDS as $word => $value ) {
			if ( preg_match( '/\b' . $word . '\s+(?:children|child|kids|kid)\b/', $normalized ) ) {
				return $value;
			}
		}

		if ( preg_match( '/\b(?:one|a|my)\s+(?:child|kid|son|daughter)\b/', $normalized ) ) {
			return 1;
		}

		return null;
	}

	/**
	 * Resolve whether minor children are present.
	 *
	 * @param string               $normalized     Normalized text.
	 * @param array<string, mixed> $existing_facts Existing facts.
	 * @param int|null             $child_count    Child count parsed this turn.
	 * @return bool|null
	 */
	private function extract_has_children( string $normalized, array $existing_facts, ?int $child_count ): ?bool {
		if ( null !== $child_count ) {
			return $child_count > 0;
		}

		if ( preg_match( '/\bno children\b|\bwithout children\b|\bno kids\b|\bchildless\b/', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:we|i)\s+have\s+(?:children|kids|a child)\b|\bminor children\b|\b(?:my|our)\s+(?:son|daughter|child|children|kids)\b/', $normalized ) ) {
			return true;
		}

		// Do not re-emit children discriminators on unrelated short answers
		// (e.g. "Queens" for marriage_location) when child_count is already known.
		return null;
	}

	/**
	 * Whether a message indicates zero children for a count field.
	 *
	 * @param string $normalized Normalized text.
	 * @param string $field_key  Pending field key.
	 * @return bool
	 */
	private function is_zero_count_answer( string $normalized, string $field_key ): bool {
		if ( 'child_count' !== $field_key ) {
			return false;
		}

		if ( preg_match( '/\bno children\b|\bwithout children\b|\bno kids\b|\bchildless\b|\bmean no children\b/', $normalized ) ) {
			return true;
		}

		$boolean = $this->parse_boolean( $normalized );

		return false === $boolean;
	}

	/**
	 * Format a short place answer for marriage/separation location fields.
	 *
	 * @param string $message Raw user message.
	 * @return string|null
	 */
	public function format_place_answer( string $message ): ?string {
		$trimmed = trim( $message );

		if ( '' === $trimmed ) {
			return null;
		}

		$normalized = $this->catalog->normalize_text( $trimmed );
		$county     = $this->extract_county( $normalized );

		if ( null === $county ) {
			return $trimmed;
		}

		$labels = array(
			'Kings'     => 'Brooklyn',
			'New York'  => 'Manhattan',
			'Richmond'  => 'Staten Island',
			'Queens'    => 'Queens',
			'Bronx'     => 'Bronx',
		);

		$label = $labels[ $county ] ?? $county;

		if ( preg_match( '/\b(?:ny|new york|n\.?y\.?)\b/i', $trimmed ) || str_contains( $trimmed, ',' ) ) {
			return $trimmed;
		}

		return $label . ', NY';
	}

	/**
	 * Whether a short message is only a place name (not a filing-county answer).
	 *
	 * @param string $message Raw message.
	 * @return bool
	 */
	public function looks_like_place_only_answer( string $message ): bool {
		$trimmed = trim( $message );

		if ( '' === $trimmed || str_contains( $trimmed, '?' ) || strlen( $trimmed ) > 60 ) {
			return false;
		}

		$normalized = $this->catalog->normalize_text( $trimmed );

		if ( null !== $this->extract_county( $normalized ) ) {
			return true;
		}

		return (bool) preg_match( '/^[a-z][a-z\s\',.-]{1,50}$/i', $trimmed );
	}

	/**
	 * Whether a borough/county token should fill marriage_location instead of county.
	 *
	 * @param string                            $message         User message.
	 * @param array<string, string>             $keys            Required field keys.
	 * @param array<string, mixed>              $existing_facts  Known facts.
	 * @return bool
	 */
	private function should_defer_county_to_marriage_location( string $message, array $keys, array $existing_facts ): bool {
		if ( ! isset( $keys['marriage_location'] ) ) {
			return false;
		}

		if ( empty( $existing_facts['county'] ) ) {
			return false;
		}

		return $this->looks_like_place_only_answer( $message );
	}

	/**
	 * Extract an NYC county from normalized text.
	 *
	 * @param string $normalized Normalized text.
	 * @return string|null
	 */
	private function extract_county( string $normalized ): ?string {
		foreach ( self::COUNTY_MAP as $token => $county ) {
			if ( str_contains( $normalized, $token ) ) {
				return $county;
			}
		}

		return null;
	}

	/**
	 * Parse an integer from normalized text (digits or number words).
	 *
	 * @param string $normalized Normalized text.
	 * @return int|null
	 */
	private function parse_integer( string $normalized ): ?int {
		if ( $this->looks_like_residency_duration( $normalized ) ) {
			return null;
		}

		if ( preg_match( '/\b(\d+)\b/', $normalized, $matches ) ) {
			return (int) $matches[1];
		}

		foreach ( self::NUMBER_WORDS as $word => $value ) {
			if ( preg_match( '/\b' . $word . '\b/', $normalized ) ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Parse a boolean from normalized text.
	 *
	 * @param string $normalized Normalized text.
	 * @return bool|null
	 */
	private function parse_boolean( string $normalized ): ?bool {
		foreach ( self::NEGATE as $cue ) {
			if ( preg_match( '/\b' . preg_quote( $cue, '/' ) . '\b/', $normalized ) ) {
				return false;
			}
		}

		foreach ( self::AFFIRM as $cue ) {
			if ( preg_match( '/\b' . preg_quote( $cue, '/' ) . '\b/', $normalized ) ) {
				return true;
			}
		}

		return null;
	}

	/**
	 * Parse a date from raw text into Y-m-d.
	 *
	 * @param string $text Raw text.
	 * @return string|null
	 */
	private function parse_date( string $text ): ?string {
		return Date_Parser::parse( $text );
	}

	/**
	 * Split free text into a list of values.
	 *
	 * @param string $text Raw text.
	 * @return string[]
	 */
	private function parse_list( string $text ): array {
		$parts = preg_split( '/\s*(?:,|;|\band\b)\s*/i', $text ) ?: array();
		$items = array();

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );

			if ( '' !== $part ) {
				$items[] = $part;
			}
		}

		return $items;
	}

	/**
	 * Whether the message describes a residency duration (not a child count).
	 *
	 * @param string $normalized Normalized text.
	 * @return bool
	 */
	private function looks_like_residency_duration( string $normalized ): bool {
		if ( str_contains( $normalized, 'just moved' ) ) {
			return true;
		}

		if ( ! preg_match( '/\b\d+\s+(?:month|year)s?\b/', $normalized ) ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:lived|living|resident|residency|moved|here|new york|ny|only)\b/',
			$normalized
		);
	}

	/**
	 * Map residency duration phrases to qualification keys.
	 *
	 * @param string $normalized Normalized text.
	 * @return string|null
	 */
	private function extract_residency_qualification( string $normalized ): ?string {
		if ( ! preg_match( '/\b(?:lived|living|resident|residency|moved|here|new york|ny)\b/', $normalized )
			&& ! str_contains( $normalized, 'just moved' ) ) {
			return null;
		}

		if ( str_contains( $normalized, 'just moved' ) ) {
			return 'ineligible';
		}

		if ( preg_match( '/\b(\d+)\s+months?\b/', $normalized, $matches ) ) {
			return (int) $matches[1] < 12 ? 'ineligible' : 'not_met';
		}

		if ( preg_match( '/\b(\d+)\s+years?\b/', $normalized, $matches ) ) {
			$years = (int) $matches[1];

			if ( $years >= 2 ) {
				return '2_year_state';
			}

			if ( $years >= 1 ) {
				return '1_year_state';
			}

			return 'ineligible';
		}

		return null;
	}

	/**
	 * Whether a pending field is a yes/no routing discriminator.
	 *
	 * @param string $field_key Field key.
	 * @return bool
	 */
	private function is_boolean_discriminator( string $field_key ): bool {
		return in_array(
			$field_key,
			array( 'children', 'has_minor_children', 'spouse_agrees', 'spouse_responded', 'marital_property_resolved', 'protection_needed', 'active_divorce' ),
			true
		);
	}

	/**
	 * Whether a pending field is the children routing discriminator.
	 *
	 * @param string $field_key Field key.
	 * @return bool
	 */
	private function is_children_discriminator( string $field_key ): bool {
		return in_array( $field_key, array( 'children', 'has_minor_children' ), true );
	}

	/**
	 * Remove null values (null never overwrites populated facts).
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return array<string, mixed>
	 */
	private function strip_nulls( array $facts ): array {
		return array_filter(
			$facts,
			static function ( $value ): bool {
				return null !== $value;
			}
		);
	}
}
