<?php
/**
 * AI Fact Extractor — bulk LLM fact extraction with normalization.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Fact_Extractor
 */
final class Fact_Extractor {

	/**
	 * NYC borough to county map.
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
	 * AI settings.
	 *
	 * @var AI_Settings
	 */
	private AI_Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param AI_Settings|null $settings AI settings.
	 */
	public function __construct( ?AI_Settings $settings = null ) {
		$this->settings = $settings ?? new AI_Settings();
	}

	/**
	 * Extract facts from a user message (bulk extraction).
	 *
	 * @param string                              $message        User message.
	 * @param Intake_State                        $state          Current state.
	 * @param array<int, array<string, mixed>>    $required_defs  Required field definitions.
	 * @param array{summary: string, recent: array<int, array<string, string>>} $memory Memory context.
	 * @param Ai_Provider_Interface               $provider       AI provider.
	 * @param AI_Logger|null                      $logger         Optional logger.
	 * @return array{updates: array<string, array{value: mixed, confidence: float}>, raw_confidence: float, intent: string, low_confidence: array<string, array{value: mixed, confidence: float}>}
	 */
	public function extract(
		string $message,
		Intake_State $state,
		array $required_defs,
		array $memory,
		Ai_Provider_Interface $provider,
		?AI_Logger $logger = null
	): array {
		$schema = $this->build_schema( $required_defs );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->settings->system_prompt(),
			),
			array(
				'role'    => 'user',
				'content' => wp_json_encode(
					array(
						'task'                 => 'extract_facts',
						'instructions'         => 'Extract ALL facts present in the message, even when multiple facts appear in one sentence. Return JSON only: { "fact_updates": { "field_key": { "value": <typed value>, "confidence": 0-1 } }, "intent": "answer_question", "confidence": 0-1 }. Dates must be YYYY-MM-DD. Booleans must be true/false. Never omit a fact that is clearly stated. If pending_field is set, interpret short answers in that field context.',
						'pending_field'        => $state->pending_field(),
						'conversation_summary' => $memory['summary'] ?? '',
						'recent_messages'      => $memory['recent'] ?? array(),
						'current_state'        => $state->plain_facts(),
						'required_fields'      => $schema,
						'latest_user_message'  => $message,
					)
				),
			),
		);

		$response = $provider->complete(
			$messages,
			array_merge(
				$this->settings->provider_options(),
				array(
					'mode'           => 'extract',
					'response_format' => 'json_object',
					'context'        => array(
						'pending_field' => $state->pending_field(),
						'facts'         => $state->plain_facts(),
					),
				)
			)
		);

		if ( null !== $logger ) {
			$logger->log(
				array(
					'type'       => 'extract',
					'latency_ms' => $response['latency_ms'],
					'prompt'     => $messages,
					'response'   => $response['content'],
				)
			);
		}

		$this->settings->record_request(
			array(
				'type'       => 'extract',
				'latency_ms' => $response['latency_ms'],
				'provider'   => $provider->name(),
			)
		);

		$parsed = $this->parse_response( $response['content'], $state->pending_field() );

		return $this->merge_deterministic( $message, $required_defs, $state, $parsed );
	}

	/**
	 * Generate a conversational question for a target field.
	 *
	 * @param array{field: string, question?: string, type?: string} $target   Target field.
	 * @param Intake_State                                            $state    State.
	 * @param Ai_Provider_Interface                                   $provider Provider.
	 * @return string
	 */
	public function phrase_question( array $target, Intake_State $state, Ai_Provider_Interface $provider ): string {
		$fallback = (string) ( $target['question'] ?? '' );

		if ( '' === $fallback ) {
			$fallback = 'Could you tell me about your ' . str_replace( '_', ' ', (string) $target['field'] ) . '?';
		}

		try {
			$response = $provider->complete(
				array(
					array(
						'role'    => 'system',
						'content' => $this->settings->system_prompt(),
					),
					array(
						'role'    => 'user',
						'content' => wp_json_encode(
							array(
								'task'                 => 'phrase_question',
								'target_field'         => $target['field'],
								'field_type'           => $target['type'] ?? 'string',
								'workflow_question'    => $fallback,
								'conversation_summary' => $state->conversation_summary(),
								'known_facts'          => $state->plain_facts(),
							)
						),
					),
				),
				array_merge(
					$this->settings->provider_options(),
					array(
						'mode'    => 'question',
						'context' => array(
							'target_field' => $target['field'],
						),
					)
				)
			);

			$parsed = json_decode( $response['content'], true );

			if ( is_array( $parsed ) && ! empty( $parsed['question'] ) ) {
				return (string) $parsed['question'];
			}
		} catch ( \Throwable $e ) {
			$this->settings->record_error( $e->getMessage() );
		}

		return $fallback;
	}

	/**
	 * Normalize, score, and deterministically supplement a raw fact-update map.
	 *
	 * Shared by the legacy extractor and the conversational engine so all
	 * normalization (county/date/boolean/count) and deterministic hardening live
	 * in one place.
	 *
	 * @param array<string, mixed>             $raw_updates   Raw model fact_updates.
	 * @param float                            $confidence    Overall confidence.
	 * @param string                           $intent        Detected intent.
	 * @param string                           $message       User message.
	 * @param array<int, array<string, mixed>> $required_defs Required field defs.
	 * @param Intake_State                     $state         Intake state.
	 * @return array{updates: array<string, array{value: mixed, confidence: float}>, raw_confidence: float, intent: string, low_confidence: array<string, array{value: mixed, confidence: float}>}
	 */
	public function process_raw(
		array $raw_updates,
		float $confidence,
		string $intent,
		string $message,
		array $required_defs,
		Intake_State $state
	): array {
		list( $updates, $low ) = $this->normalize_raw_updates( $raw_updates, $state->pending_field() );

		$parsed = array(
			'updates'        => $updates,
			'low_confidence' => $low,
			'raw_confidence' => $confidence,
			'intent'         => $intent,
		);

		return $this->merge_deterministic( $message, $required_defs, $state, $parsed );
	}

	/**
	 * Parse provider JSON response.
	 *
	 * @param string $content       Raw JSON content.
	 * @param string $pending_field Pending field.
	 * @return array{updates: array<string, array{value: mixed, confidence: float}>, raw_confidence: float, intent: string, low_confidence: array<string, array{value: mixed, confidence: float}>}
	 */
	private function parse_response( string $content, string $pending_field ): array {
		$parsed = $this->decode_json_payload( $content );

		if ( ! is_array( $parsed ) ) {
			return array(
				'updates'        => array(),
				'raw_confidence' => 0.0,
				'intent'         => 'unknown',
				'low_confidence' => array(),
			);
		}

		$raw_updates           = is_array( $parsed['fact_updates'] ?? null ) ? $parsed['fact_updates'] : array();
		list( $updates, $low ) = $this->normalize_raw_updates( $raw_updates, $pending_field );

		return array(
			'updates'        => $updates,
			'raw_confidence' => (float) ( $parsed['confidence'] ?? 0.0 ),
			'intent'         => (string) ( $parsed['intent'] ?? 'answer_question' ),
			'low_confidence' => $low,
		);
	}

	/**
	 * Normalize a raw fact-update map into confident/low-confidence buckets.
	 *
	 * @param array<string, mixed> $raw_updates   Raw fact_updates.
	 * @param string               $pending_field Pending field hint.
	 * @return array{0: array<string, array{value: mixed, confidence: float}>, 1: array<string, array{value: mixed, confidence: float}>}
	 */
	private function normalize_raw_updates( array $raw_updates, string $pending_field ): array {
		$updates = array();
		$low     = array();

		foreach ( $raw_updates as $key => $update ) {
			$normalized = $this->normalize_update_entry( (string) $key, $update, $pending_field );

			if ( null === $normalized ) {
				continue;
			}

			if ( $normalized['confidence'] >= Intake_State::CONFIDENCE_THRESHOLD ) {
				$updates[ $normalized['key'] ] = array(
					'value'      => $normalized['value'],
					'confidence' => $normalized['confidence'],
				);
			} else {
				$low[ $normalized['key'] ] = array(
					'value'      => $normalized['value'],
					'confidence' => $normalized['confidence'],
				);
			}
		}

		if ( isset( $updates['children_count'] ) && ! isset( $updates['child_count'] ) ) {
			$updates['child_count'] = $updates['children_count'];
		}

		if ( isset( $low['children_count'] ) && ! isset( $low['child_count'] ) ) {
			$low['child_count'] = $low['children_count'];
		}

		if (
			isset( $updates['county'] )
			&& 'marriage_location' === $pending_field
			&& ! isset( $updates['marriage_location'] )
		) {
			$updates['marriage_location'] = $updates['county'];
			unset( $updates['county'] );
		}

		return array( $updates, $low );
	}

	/**
	 * Decode a JSON payload from raw model output.
	 *
	 * @param string $content Raw content.
	 * @return array<string, mixed>|null
	 */
	private function decode_json_payload( string $content ): ?array {
		$content = trim( $content );

		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches ) ) {
			$content = $matches[1];
		} elseif ( preg_match( '/(\{.*\})/s', $content, $matches ) ) {
			$content = $matches[1];
		}

		$parsed = json_decode( $content, true );

		return is_array( $parsed ) ? $parsed : null;
	}

	/**
	 * Normalize a single fact update entry from LLM output.
	 *
	 * @param string $key           Field key.
	 * @param mixed  $update        Raw update.
	 * @param string $pending_field Pending field.
	 * @return array{key: string, value: mixed, confidence: float}|null
	 */
	private function normalize_update_entry( string $key, $update, string $pending_field ): ?array {
		$key = $this->resolve_pending_field_key( $key, $pending_field );

		if ( is_array( $update ) && array_key_exists( 'value', $update ) ) {
			$value      = $this->normalize_value( $key, $update['value'], $pending_field );
			$confidence = (float) ( $update['confidence'] ?? 0.9 );
		} elseif ( null !== $update && ( ! is_string( $update ) || '' !== trim( $update ) ) ) {
			$value      = $this->normalize_value( $key, $update, $pending_field );
			$confidence = 0.9;
		} else {
			return null;
		}

		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return null;
		}

		return array(
			'key'        => $key,
			'value'      => $value,
			'confidence' => $confidence,
		);
	}

	/**
	 * Map model field keys onto the pending intake slot when appropriate.
	 *
	 * @param string $key           Field key from the model.
	 * @param string $pending_field Pending field hint.
	 * @return string
	 */
	private function resolve_pending_field_key( string $key, string $pending_field ): string {
		if ( 'county' === $key && 'marriage_location' === $pending_field ) {
			return 'marriage_location';
		}

		return $key;
	}

	/**
	 * Supplement LLM extraction with deterministic pattern matching.
	 *
	 * @param string                           $message       User message.
	 * @param array<int, array<string, mixed>> $required_defs Required field definitions.
	 * @param Intake_State                     $state         Intake state.
	 * @param array<string, mixed>             $parsed        Parsed LLM result.
	 * @return array{updates: array<string, array{value: mixed, confidence: float}>, raw_confidence: float, intent: string, low_confidence: array<string, array{value: mixed, confidence: float}>}
	 */
	private function merge_deterministic( string $message, array $required_defs, Intake_State $state, array $parsed ): array {
		$updates = is_array( $parsed['updates'] ?? null ) ? $parsed['updates'] : array();
		$low     = is_array( $parsed['low_confidence'] ?? null ) ? $parsed['low_confidence'] : array();

		$deterministic = new \ProSe\Core\Intake\Fact_Extractor();

		foreach ( $deterministic->extract( $message, $required_defs, $state->plain_facts() ) as $key => $value ) {
			if ( isset( $updates[ $key ] ) || ( $state->is_filled( $key ) && ! $this->is_date_upgrade( $state, $key, $value ) ) ) {
				continue;
			}

			$updates[ $key ] = array(
				'value'      => $value,
				'confidence' => in_array( $key, array( 'marriage_date', 'separation_date' ), true )
					? \ProSe\Core\Intake\Date_Parser::confidence_for( (string) $value )
					: 0.95,
			);
		}

		foreach ( $this->extract_contextual_dates( $message, $required_defs, $state->pending_field() ) as $key => $value ) {
			if ( isset( $updates[ $key ] ) || ( $state->is_filled( $key ) && ! $this->is_date_upgrade( $state, $key, $value ) ) ) {
				continue;
			}

			$updates[ $key ] = array(
				'value'      => $value,
				'confidence' => \ProSe\Core\Intake\Date_Parser::confidence_for( $value ),
			);
		}

		foreach ( $this->extract_contextual_booleans( $message ) as $key => $value ) {
			if ( isset( $updates[ $key ] ) || $state->is_filled( $key ) ) {
				continue;
			}

			$updates[ $key ] = array(
				'value'      => $value,
				'confidence' => 0.95,
			);
		}

		foreach ( $this->extract_contextual_pending_strings( $message, $required_defs, $state ) as $key => $value ) {
			if ( isset( $updates[ $key ] ) || $state->is_filled( $key ) ) {
				continue;
			}

			$updates[ $key ] = array(
				'value'      => $value,
				'confidence' => 0.95,
			);
		}

		if (
			(
				preg_match( '/\b(?:wife|husband|spouse)\s+agrees?\b/i', $message )
				|| preg_match( '/\b(?:we\s+)?both\s+agree\b/i', $message )
				|| preg_match( '/\buncontested\b/i', $message )
			)
			&& ! isset( $updates['spouse_agrees'] )
			&& ! $state->is_filled( 'spouse_agrees' )
		) {
			$updates['spouse_agrees'] = array(
				'value'      => true,
				'confidence' => 0.95,
			);
		}

		if ( preg_match( '/\bqueens\s+county\b/i', $message ) && ! isset( $updates['county'] ) && ! $state->is_filled( 'county' ) ) {
			$updates['county'] = array(
				'value'      => 'Queens',
				'confidence' => 0.98,
			);
		}

		return array(
			'updates'        => $updates,
			'raw_confidence' => (float) ( $parsed['raw_confidence'] ?? 0.0 ),
			'intent'         => (string) ( $parsed['intent'] ?? 'answer_question' ),
			'low_confidence' => $low,
		);
	}

	/**
	 * Extract marriage/separation dates from natural language.
	 *
	 * @param string                           $message       Message.
	 * @param array<int, array<string, mixed>> $required_defs Required defs.
	 * @param string                           $pending_field Pending field key.
	 * @return array<string, string>
	 */
	private function extract_contextual_dates( string $message, array $required_defs, string $pending_field = '' ): array {
		$keys  = $this->field_keys( $required_defs );
		$facts = array();

		$needs_marriage   = empty( $keys ) || isset( $keys['marriage_date'] );
		$needs_separation = empty( $keys ) || isset( $keys['separation_date'] );

		if ( $needs_marriage ) {
			foreach ( \ProSe\Core\Intake\Date_Parser::extract_marriage_and_separation( $message ) as $key => $value ) {
				if ( 'marriage_date' === $key ) {
					$facts['marriage_date'] = $value;
				}
			}

			if ( ! isset( $facts['marriage_date'] ) && 'marriage_date' === $pending_field ) {
				$parsed = \ProSe\Core\Intake\Date_Parser::parse( trim( $message ) );

				if ( null !== $parsed ) {
					$facts['marriage_date'] = $parsed;
				}
			}
		}

		if ( $needs_separation && ! isset( $facts['separation_date'] ) ) {
			foreach ( \ProSe\Core\Intake\Date_Parser::extract_marriage_and_separation( $message ) as $key => $value ) {
				if ( 'separation_date' === $key ) {
					$facts['separation_date'] = $value;
				}
			}

			if ( ! isset( $facts['separation_date'] ) && 'separation_date' === $pending_field ) {
				$parsed = \ProSe\Core\Intake\Date_Parser::parse( trim( $message ) );

				if ( null !== $parsed ) {
					$facts['separation_date'] = $parsed;
				}
			}
		}

		$needs_child_birth = empty( $keys ) || isset( $keys['child_birth_dates'] ) || isset( $keys['child_birth_date'] );

		if ( $needs_child_birth ) {
			$child_birth = \ProSe\Core\Intake\Date_Parser::extract_child_birth_date( $message );

			if ( null !== $child_birth ) {
				if ( isset( $keys['child_birth_date'] ) ) {
					$facts['child_birth_date'] = $child_birth;
				} else {
					$facts['child_birth_dates'] = $child_birth;
				}
			}
		}

		return $facts;
	}

	/**
	 * Extract direct answers to pending string fields (e.g. marriage location).
	 *
	 * @param string                           $message       Message.
	 * @param array<int, array<string, mixed>> $required_defs Required defs.
	 * @param Intake_State                     $state         Intake state.
	 * @return array<string, string>
	 */
	private function extract_contextual_pending_strings( string $message, array $required_defs, Intake_State $state ): array {
		$keys    = $this->field_keys( $required_defs );
		$facts   = array();
		$pending = $state->pending_field();
		$targets = array( 'marriage_location', 'grounds_for_divorce', 'residency_qualification' );

		$deterministic = new \ProSe\Core\Intake\Fact_Extractor();

		if ( '' !== $pending && in_array( $pending, $targets, true ) && isset( $keys[ $pending ] ) && ! $state->is_filled( $pending ) ) {
			$type     = $this->field_type_from_defs( $required_defs, $pending );
			$inferred = $deterministic->infer_pending_answer( $message, $pending, $type );

			if ( isset( $inferred[ $pending ] ) && is_string( $inferred[ $pending ] ) && '' !== trim( $inferred[ $pending ] ) ) {
				$facts[ $pending ] = $inferred[ $pending ];
			}
		}

		if (
			! isset( $facts['marriage_location'] )
			&& isset( $keys['marriage_location'] )
			&& ! $state->is_filled( 'marriage_location' )
			&& $state->is_filled( 'county' )
			&& $deterministic->looks_like_place_only_answer( $message )
		) {
			$inferred = $deterministic->infer_pending_answer( $message, 'marriage_location', 'string' );

			if ( isset( $inferred['marriage_location'] ) ) {
				$facts['marriage_location'] = $inferred['marriage_location'];
			}
		}

		return $facts;
	}

	/**
	 * Resolve a field type from required field definitions.
	 *
	 * @param array<int, array<string, mixed>> $required_defs Required defs.
	 * @param string                           $field_key     Field key.
	 * @return string|null
	 */
	private function field_type_from_defs( array $required_defs, string $field_key ): ?string {
		foreach ( $required_defs as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( (string) ( $field['key'] ?? '' ) === $field_key ) {
				$type = (string) ( $field['type'] ?? 'string' );

				return '' !== $type ? $type : 'string';
			}
		}

		return null;
	}

	/**
	 * Whether a deterministic date should replace a year-only placeholder.
	 *
	 * @param Intake_State $state Intake state.
	 * @param string       $key   Field key.
	 * @param mixed        $value New value.
	 * @return bool
	 */
	private function is_date_upgrade( Intake_State $state, string $key, $value ): bool {
		if ( ! in_array( $key, array( 'marriage_date', 'separation_date' ), true ) || ! is_string( $value ) ) {
			return false;
		}

		$existing = $state->plain_facts()[ $key ] ?? null;

		return is_string( $existing )
			&& \ProSe\Core\Intake\Date_Parser::is_year_only_placeholder( $existing )
			&& ! \ProSe\Core\Intake\Date_Parser::is_year_only_placeholder( $value );
	}

	/**
	 * Extract routing booleans from natural language.
	 *
	 * @param string $message Message.
	 * @return array<string, bool>
	 */
	private function extract_contextual_booleans( string $message ): array {
		$facts = array();

		if ( preg_match( '/\b(?:have|we have)\s+(?:two|2|\d+)\s+children\b/i', $message ) ) {
			$facts['children']            = true;
			$facts['has_minor_children']  = true;
		}

		if ( preg_match( '/\bno children\b/i', $message ) ) {
			$facts['children']           = false;
			$facts['has_minor_children'] = false;
		}

		return $facts;
	}

	/**
	 * Field keys indexed from required definitions.
	 *
	 * @param array<int, array<string, mixed>> $required_defs Required defs.
	 * @return array<string, bool>
	 */
	private function field_keys( array $required_defs ): array {
		$keys = array();

		foreach ( $required_defs as $def ) {
			$key = (string) ( $def['key'] ?? '' );

			if ( '' !== $key ) {
				$keys[ $key ] = true;
			}
		}

		return $keys;
	}

	/**
	 * Parse a date from natural language into Y-m-d.
	 *
	 * @param string $text Raw text.
	 * @return string|null
	 */
	private function parse_date( string $text ): ?string {
		return \ProSe\Core\Intake\Date_Parser::parse( $text );
	}

	/**
	 * Build schema for required fields.
	 *
	 * @param array<int, array<string, mixed>> $required_defs Required definitions.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_schema( array $required_defs ): array {
		$schema = array();

		foreach ( $required_defs as $def ) {
			$key = (string) ( $def['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$schema[] = array(
				'key'      => $key,
				'type'     => $def['type'] ?? 'string',
				'question' => $def['question'] ?? '',
			);
		}

		return $schema;
	}

	/**
	 * Normalize extracted values.
	 *
	 * @param string $key           Field key.
	 * @param mixed  $value         Raw value.
	 * @param string $pending_field Pending field.
	 * @return mixed
	 */
	private function normalize_value( string $key, $value, string $pending_field ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( 'county' === $key || ( 'county' === $pending_field && is_string( $value ) ) ) {
			return $this->normalize_county( (string) $value );
		}

		if ( 'marriage_location' === $key || ( 'marriage_location' === $pending_field && is_string( $value ) ) ) {
			$formatted = ( new \ProSe\Core\Intake\Fact_Extractor() )->format_place_answer( (string) $value );

			return null !== $formatted ? $formatted : (string) $value;
		}

		if ( in_array( $key, array( 'child_count', 'children_count' ), true ) ) {
			return $this->normalize_count( $value );
		}

		if ( in_array( $key, array( 'marriage_date', 'separation_date', 'incident_date', 'service_date', 'child_birth_date' ), true )
			|| ( in_array( $pending_field, array( 'marriage_date', 'separation_date' ), true ) && is_string( $value ) ) ) {
			$parsed = $this->parse_date( (string) $value );

			return null !== $parsed ? $parsed : $value;
		}

		if ( in_array( $key, array( 'children', 'spouse_agrees', 'has_minor_children', 'spouse_responded', 'active_divorce', 'protection_needed', 'marital_property_resolved' ), true ) ) {
			return $this->normalize_boolean( $value );
		}

		return $value;
	}

	/**
	 * Normalize county names.
	 *
	 * @param string $input Input.
	 * @return string
	 */
	private function normalize_county( string $input ): string {
		$lower = strtolower( trim( $input ) );

		foreach ( self::COUNTY_MAP as $token => $county ) {
			if ( $lower === $token || str_contains( $lower, $token ) ) {
				return $county;
			}
		}

		return ucwords( $input );
	}

	/**
	 * Normalize integer counts.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function normalize_count( $value ): int {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		$words = array(
			'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3,
			'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
			'eight' => 8, 'nine' => 9, 'ten' => 10,
		);

		$lower = strtolower( trim( (string) $value ) );

		return $words[ $lower ] ?? 0;
	}

	/**
	 * Normalize boolean values.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function normalize_boolean( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$lower = strtolower( trim( (string) $value ) );

		return in_array( $lower, array( 'yes', 'yeah', 'yep', 'true', '1', 'agree', 'agreed' ), true );
	}
}
