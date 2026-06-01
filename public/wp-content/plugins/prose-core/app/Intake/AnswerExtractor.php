<?php
/**
 * AnswerExtractor — deterministic safety-net parser for the active intake field.
 *
 * The LLM IntakeAgent is the primary extractor, but it can miss terse replies
 * ("Queens", "yes", "two kids", "$80k"). This class focuses on the SINGLE
 * pending question and maps the user's reply directly to that field path
 * using deterministic rules. It only emits patches it is confident about and
 * never overwrites facts that are already set (DataMerger handles that).
 *
 * Always runs AFTER the LLM extractor. The result is a partial facts patch
 * that DataMerger merges normally.
 *
 * @package ProseCore
 */

namespace Prose\Core\Intake;

use Prose\Core\Forms\DataResolver;

final class AnswerExtractor {

	/**
	 * NY counties + common borough/city aliases. Keys are lowercased aliases,
	 * values are canonical county names (no "County" suffix).
	 */
	private const COUNTY_ALIASES = array(
		'queens'          => 'Queens',
		'brooklyn'        => 'Kings',
		'kings'           => 'Kings',
		'manhattan'       => 'New York',
		'new york'        => 'New York',
		'nyc'             => 'New York',
		'bronx'           => 'Bronx',
		'the bronx'       => 'Bronx',
		'staten island'   => 'Richmond',
		'richmond'        => 'Richmond',
		'nassau'          => 'Nassau',
		'suffolk'         => 'Suffolk',
		'westchester'     => 'Westchester',
		'rockland'        => 'Rockland',
		'orange'          => 'Orange',
		'putnam'          => 'Putnam',
		'dutchess'        => 'Dutchess',
		'erie'            => 'Erie',
		'monroe'          => 'Monroe',
		'onondaga'        => 'Onondaga',
		'albany'          => 'Albany',
		'saratoga'        => 'Saratoga',
		'schenectady'     => 'Schenectady',
		'rensselaer'      => 'Rensselaer',
		'ulster'          => 'Ulster',
		'sullivan'        => 'Sullivan',
		'tompkins'        => 'Tompkins',
		'broome'          => 'Broome',
		'oneida'          => 'Oneida',
		'niagara'         => 'Niagara',
	);

	private const CASE_TYPE_ALIASES = array(
		'divorce'              => 'divorce',
		'separation'           => 'divorce',
		'custody'              => 'custody',
		'visitation'           => 'custody',
		'child support'        => 'child_support',
		'support'              => 'child_support',
		'order of protection'  => 'order_of_protection',
		'protection'           => 'order_of_protection',
		'restraining order'    => 'order_of_protection',
		'family offense'       => 'family_offense',
	);

	public function __construct(
		private readonly ?DataResolver $resolver = null
	) {}

	/**
	 * Try to extract a value for $pending_path from $user_message.
	 * Returns a partial facts patch (nested by dot path) or empty array.
	 *
	 * @param array<string, mixed> $facts Current facts (used to skip when already set).
	 * @return array<string, mixed>
	 */
	public function extract( string $pending_path, string $user_message, array $facts = array() ): array {
		$pending_path = trim( $pending_path );
		if ( '' === $pending_path ) {
			return array();
		}

		$resolver = $this->resolver ?? new DataResolver();
		if ( null !== $resolver->resolve( $pending_path, $facts ) ) {
			return array();
		}

		$text = trim( $user_message );
		if ( '' === $text ) {
			return array();
		}

		$value = match ( $pending_path ) {
			'case.county'                  => $this->extract_county( $text ),
			'user.full_name'               => $this->extract_full_name( $text ),
			'case.case_type'               => $this->extract_case_type( $text ),
			'case.contested'               => $this->extract_contested( $text ),
			'case.children'                => $this->extract_yes_no( $text ),
			'case.children_count'          => $this->extract_integer( $text ),
			'case.children_info'           => $this->extract_children_info( $text ),
			'case.child_support_requested' => $this->extract_yes_no( $text ),
			'case.order_of_protection'     => $this->extract_yes_no( $text ),
			'case.spouse_name'             => $this->extract_full_name( $text ),
			'case.marriage_date'           => $this->extract_date( $text ),
			'case.marriage_place'          => $this->extract_place( $text ),
			'case.income'                  => $this->extract_money( $text ),
			'case.employer'                => $this->extract_employer( $text ),
			default                        => null,
		};

		if ( null === $value || '' === $value || ( is_array( $value ) && empty( $value ) ) ) {
			return array();
		}

		return $this->build_patch( $pending_path, $value );
	}

	private function extract_county( string $text ): ?string {
		$lower = strtolower( $text );
		$lower = preg_replace( '/\s+county\b/i', '', $lower ) ?? $lower;
		$lower = trim( preg_replace( '/[^a-z\s]/', ' ', $lower ) ?? $lower );

		foreach ( self::COUNTY_ALIASES as $alias => $canonical ) {
			if ( $lower === $alias ) {
				return $canonical;
			}
		}
		foreach ( self::COUNTY_ALIASES as $alias => $canonical ) {
			if ( preg_match( '/\b' . preg_quote( $alias, '/' ) . '\b/i', $text ) ) {
				return $canonical;
			}
		}

		return null;
	}

	private function extract_full_name( string $text ): ?string {
		$text = trim( $text );
		if ( '' === $text || strlen( $text ) > 80 ) {
			return null;
		}

		$cleaned = preg_replace( '/^(my name is|i am|i\'m|it\'s|its|the other party is|his name is|her name is|spouse is|name)\s*:?\s*/i', '', $text );
		$cleaned = trim( trim( (string) $cleaned ), '.,!?"\'' );

		if ( '' === $cleaned ) {
			return null;
		}

		if ( str_word_count( $cleaned ) < 2 || str_word_count( $cleaned ) > 5 ) {
			return null;
		}

		if ( ! preg_match( '/^[\p{L}\p{M}\s\.\'-]+$/u', $cleaned ) ) {
			return null;
		}

		return $cleaned;
	}

	private function extract_case_type( string $text ): ?string {
		$lower = strtolower( $text );
		foreach ( self::CASE_TYPE_ALIASES as $alias => $canonical ) {
			if ( str_contains( $lower, $alias ) ) {
				return $canonical;
			}
		}
		return null;
	}

	private function extract_contested( string $text ): ?bool {
		$lower = strtolower( $text );
		if ( preg_match( '/\b(uncontested|we agree|both agree|amicable|no contest)\b/', $lower ) ) {
			return false;
		}
		if ( preg_match( '/\b(contested|disagree|fighting|dispute|disputed)\b/', $lower ) ) {
			return true;
		}
		return null;
	}

	private function extract_yes_no( string $text ): ?bool {
		$lower = strtolower( trim( $text ) );
		$lower = trim( $lower, '.,!?' );

		$yes = array( 'yes', 'y', 'yeah', 'yep', 'yup', 'sure', 'correct', 'affirmative', 'true', 'we do', 'i do' );
		$no  = array( 'no', 'n', 'nope', 'nah', 'negative', 'false', 'we do not', 'we don\'t', 'i do not', 'i don\'t', 'none' );

		foreach ( $yes as $y ) {
			if ( $lower === $y || str_starts_with( $lower, $y . ' ' ) || str_starts_with( $lower, $y . ',' ) ) {
				return true;
			}
		}
		foreach ( $no as $n ) {
			if ( $lower === $n || str_starts_with( $lower, $n . ' ' ) || str_starts_with( $lower, $n . ',' ) ) {
				return false;
			}
		}
		return null;
	}

	private function extract_integer( string $text ): ?int {
		$words = array( 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10 );
		$lower = strtolower( $text );

		foreach ( $words as $word => $n ) {
			if ( preg_match( '/\b' . $word . '\b/', $lower ) ) {
				return $n;
			}
		}
		if ( preg_match( '/\b(\d{1,2})\b/', $text, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Try to parse multiple children's names + DOBs from a single message.
	 * Supports patterns like "Jane Doe 2015-04-01, John Doe 2017-08-12".
	 *
	 * @return array<int, array<string, string>>|null
	 */
	private function extract_children_info( string $text ): ?array {
		$lines = preg_split( '/\r?\n|;|,(?=\s*[A-Z])/', $text ) ?: array();
		$out   = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$dob  = null;
			$name = $line;

			if ( preg_match( '/\b(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|[A-Za-z]+\s+\d{1,2},?\s+\d{4})\b/', $line, $m ) ) {
				$dob  = $m[1];
				$name = trim( str_replace( $m[1], '', $line ) );
				$name = trim( $name, ',.-:;()' );
			}

			$name = trim( preg_replace( '/^(child\s*\d*\s*:?\s*|name\s*:?\s*)/i', '', $name ) ?? $name );
			if ( '' === $name || ! $dob ) {
				continue;
			}

			$out[] = array(
				'name' => $name,
				'dob'  => $this->normalize_date( $dob ),
			);
		}

		return $out ?: null;
	}

	private function extract_date( string $text ): ?string {
		if ( preg_match( '/\b(\d{4}-\d{1,2}-\d{1,2})\b/', $text, $m ) ) {
			return $this->normalize_date( $m[1] );
		}
		if ( preg_match( '/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/', $text, $m ) ) {
			return $this->normalize_date( $m[1] );
		}
		if ( preg_match( '/\b([A-Za-z]+\s+\d{1,2},?\s+\d{4})\b/', $text, $m ) ) {
			return $this->normalize_date( $m[1] );
		}
		return null;
	}

	private function normalize_date( string $value ): string {
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : $value;
	}

	private function extract_place( string $text ): ?string {
		$text = trim( $text );
		if ( '' === $text || strlen( $text ) > 120 ) {
			return null;
		}
		if ( ! preg_match( '/[A-Za-z]/', $text ) ) {
			return null;
		}

		$cleaned = preg_replace( '/^(in|at|we were married in|married in)\s+/i', '', $text );
		return trim( (string) $cleaned, '.,;:!?"\'' ) ?: null;
	}

	private function extract_money( string $text ): ?float {
		if ( preg_match( '/\$?\s*([\d,]+(?:\.\d+)?)\s*(k|m|thousand|million)?/i', $text, $m ) ) {
			$num    = (float) str_replace( ',', '', $m[1] );
			$suffix = strtolower( $m[2] ?? '' );
			if ( 'k' === $suffix || 'thousand' === $suffix ) {
				$num *= 1000;
			} elseif ( 'm' === $suffix || 'million' === $suffix ) {
				$num *= 1000000;
			}
			return $num > 0 ? $num : null;
		}
		return null;
	}

	private function extract_employer( string $text ): ?string {
		$text  = trim( $text );
		$lower = strtolower( $text );

		if ( preg_match( '/\b(self[ -]?employed|self employment)\b/', $lower ) ) {
			return 'self-employed';
		}
		if ( preg_match( '/\b(unemployed|not employed|no employer|between jobs|looking for work)\b/', $lower ) ) {
			return 'unemployed';
		}

		if ( '' === $text || strlen( $text ) > 80 ) {
			return null;
		}

		$cleaned = preg_replace( '/^(i work at|my employer is|employer:?|i\'m at|i am at|at)\s+/i', '', $text );
		$cleaned = trim( (string) $cleaned, '.,;:!?"\'' );

		return $cleaned ?: null;
	}

	/**
	 * Build a nested patch from a dot path + leaf value.
	 *
	 * @return array<string, mixed>
	 */
	private function build_patch( string $path, mixed $value ): array {
		$parts  = explode( '.', $path );
		$patch  = array();
		$ref    = &$patch;

		foreach ( $parts as $i => $part ) {
			if ( $i === count( $parts ) - 1 ) {
				$ref[ $part ] = $value;
			} else {
				$ref[ $part ] = array();
				$ref          = &$ref[ $part ];
			}
		}

		return $patch;
	}
}
