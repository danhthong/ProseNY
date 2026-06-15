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

		if ( null !== $county ) {
			$facts['county'] = $county;
		}

		if ( isset( $keys['has_minor_children'] ) ) {
			$has_children = $this->extract_has_children( $normalized, $existing_facts, $child_count );

			if ( null !== $has_children ) {
				$facts['has_minor_children'] = $has_children;
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
				$value = $this->parse_date( $trimmed );

				return array( $field_key => ( null !== $value ? $value : $trimmed ) );

			case 'array':
				return array( $field_key => $this->parse_list( $trimmed ) );

			case 'string':
				if ( 'county' === $field_key ) {
					$county = $this->extract_county( $normalized );

					return array( $field_key => ( null !== $county ? $county : $trimmed ) );
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

		$boolean = $this->parse_boolean( $normalized );

		if ( null !== $boolean ) {
			return array( $field_key => $boolean );
		}

		$integer = $this->parse_integer( $normalized );

		if ( null !== $integer ) {
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

		if ( array_key_exists( 'children', $existing_facts ) && is_bool( $existing_facts['children'] ) ) {
			return $existing_facts['children'];
		}

		if ( isset( $existing_facts['child_count'] ) && is_numeric( $existing_facts['child_count'] ) ) {
			return (int) $existing_facts['child_count'] > 0;
		}

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
		if ( preg_match( '/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $text, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		}

		if ( preg_match( '#\b(\d{1,2})/(\d{1,2})/(\d{4})\b#', $text, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2] );
		}

		$timestamp = strtotime( $text );

		if ( false !== $timestamp && preg_match( '/\b[a-zA-Z]{3,}\b.*\b\d{4}\b/', $text ) ) {
			return gmdate( 'Y-m-d', $timestamp );
		}

		return null;
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
