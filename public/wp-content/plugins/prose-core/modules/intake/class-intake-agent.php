<?php
/**
 * Intake Agent — deterministic intake orchestration layer.
 *
 * Consumes the Routing Engine, Workflow Repository, Case Profile, and Fact
 * Store. It collects and extracts facts, detects missing required fields,
 * calculates completion, and selects the next question. It never provides legal
 * advice, never selects forms, and never overrides routing decisions.
 *
 * Fully deterministic: no external LLMs.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Routing\Case_Profile;
use ProSe\Core\Routing\Routing_Engine;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Intake_Agent
 */
final class Intake_Agent {

	/**
	 * Routing engine.
	 *
	 * @var Routing_Engine
	 */
	private Routing_Engine $routing;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Fact extractor.
	 *
	 * @var Fact_Extractor
	 */
	private Fact_Extractor $extractor;

	/**
	 * Completion calculator.
	 *
	 * @var Completion_Calculator
	 */
	private Completion_Calculator $completion;

	/**
	 * Question selector.
	 *
	 * @var Question_Selector
	 */
	private Question_Selector $selector;

	/**
	 * Constructor.
	 *
	 * @param Routing_Engine|null        $routing    Routing engine.
	 * @param Workflow_Catalog|null      $catalog    Workflow catalog.
	 * @param Fact_Extractor|null        $extractor  Fact extractor.
	 * @param Completion_Calculator|null $completion Completion calculator.
	 * @param Question_Selector|null     $selector   Question selector.
	 */
	public function __construct(
		?Routing_Engine $routing = null,
		?Workflow_Catalog $catalog = null,
		?Fact_Extractor $extractor = null,
		?Completion_Calculator $completion = null,
		?Question_Selector $selector = null
	) {
		$this->catalog    = $catalog ?? new Workflow_Catalog();
		$this->routing    = $routing ?? new Routing_Engine( $this->catalog );
		$this->extractor  = $extractor ?? new Fact_Extractor( $this->catalog );
		$this->completion = $completion ?? new Completion_Calculator();
		$this->selector   = $selector ?? new Question_Selector();
	}

	/**
	 * Process one intake turn.
	 *
	 * @param string               $message      User message.
	 * @param array<string, mixed> $case_profile Prior case profile (round-tripped).
	 * @return array<string, mixed>
	 */
	public function process( string $message, array $case_profile = array() ): array {
		$pending_field = isset( $case_profile['pending_field'] ) && is_string( $case_profile['pending_field'] )
			? $case_profile['pending_field']
			: '';

		$profile = Case_Profile::from_array( $case_profile );

		// Stable conversation identity for the life of the session.
		if ( '' === $profile->conversation_id() ) {
			$profile->set_conversation_id( $this->generate_conversation_id() );
		}

		$extracted = array();

		// Prior routing decision (retained when a later turn is inconclusive).
		$prior_workflow = $profile->workflow();
		$prior_issue    = $profile->issue();
		$prior_court    = $profile->court();

		// Resolve the pending answer before routing so discriminator answers
		// (e.g. a bare "yes" to "Do you have children?") influence resolution.
		if ( '' !== $pending_field ) {
			$pre = $this->extractor->infer_pending_answer( $message, $pending_field, null );
			$pre = $this->non_empty( $pre );

			if ( ! empty( $pre ) ) {
				$profile->facts()->merge( $pre );
				$extracted = array_merge( $extracted, $pre );
			}
		}

		// Routing remains the source of truth (workflow resolution untouched).
		$result = $this->routing->route_profile( $message, $profile );

		// Retain the last positive routing decision when this turn is
		// inconclusive (e.g. "Brooklyn" carries no workflow signal). This does
		// not override routing — it preserves a decision routing already made.
		$workflow = $profile->workflow();

		if ( ( null === $workflow || '' === $workflow ) && null !== $prior_workflow && '' !== $prior_workflow ) {
			$workflow = $prior_workflow;
		}

		$required_fields = $this->required_fields_for( $workflow );

		// Content-signal extraction against the resolved workflow.
		$content = $this->extractor->extract( $message, $required_fields, $profile->facts()->all() );
		$content = $this->non_empty( $content );

		if ( ! empty( $content ) ) {
			$profile->facts()->merge( $content );
			$extracted = array_merge( $extracted, $content );
		}

		// Typed refinement of the pending answer once the field type is known.
		if ( '' !== $pending_field ) {
			$type  = $this->field_type( $required_fields, $pending_field );
			$typed = $this->extractor->infer_pending_answer( $message, $pending_field, $type );
			$typed = $this->non_empty( $typed );

			if ( ! empty( $typed ) ) {
				$profile->facts()->merge( $typed );
				$extracted = array_merge( $extracted, $typed );
			}
		}

		$missing     = $this->completion->missing_required( $required_fields, $profile->facts() );
		$completion  = $this->completion->calculate( $required_fields, $profile->facts() );
		$next        = $this->selector->select( $required_fields, $missing, $workflow, $result->missing_fields() );

		$profile_array = $profile->to_array();

		// Persist the retained decision into the serialized profile.
		$profile_array['workflow'] = $workflow;

		if ( null === $profile_array['issue'] && null !== $prior_issue ) {
			$profile_array['issue'] = $prior_issue;
		}

		if ( null === $profile_array['court'] && null !== $prior_court ) {
			$profile_array['court'] = $prior_court;
		}

		$profile_array['pending_field'] = $next['field'];

		return array(
			'conversation_id' => $profile->conversation_id(),
			'workflow'        => $workflow,
			'facts_extracted' => $extracted,
			'case_profile'    => $profile_array,
			'missing_fields'  => $missing,
			'next_question'   => $next['question'],
			'completion'      => $completion,
		);
	}

	/**
	 * Required fields for a workflow key.
	 *
	 * @param string|null $workflow Workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	private function required_fields_for( ?string $workflow ): array {
		if ( null === $workflow || '' === $workflow ) {
			return array();
		}

		$definition = $this->catalog->by_key( $workflow );

		if ( null === $definition ) {
			return array();
		}

		$fields = $definition['required_fields'] ?? array();

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Look up a field's type within required_fields.
	 *
	 * @param array<int, array<string, mixed>> $required_fields Required fields.
	 * @param string                           $key             Field key.
	 * @return string|null
	 */
	private function field_type( array $required_fields, string $key ): ?string {
		foreach ( $required_fields as $field ) {
			if ( (string) ( $field['key'] ?? '' ) === $key ) {
				return (string) ( $field['type'] ?? 'string' );
			}
		}

		return null;
	}

	/**
	 * Filter out null and empty-string values (null never overwrites facts).
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return array<string, mixed>
	 */
	private function non_empty( array $facts ): array {
		$clean = array();

		foreach ( $facts as $key => $value ) {
			if ( null === $value ) {
				continue;
			}

			if ( is_string( $value ) && '' === trim( $value ) ) {
				continue;
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Generate a stable UUIDv4 conversation identifier.
	 *
	 * Uses wp_generate_uuid4() when available, otherwise a random_bytes
	 * fallback (e.g. in unit tests outside WordPress).
	 *
	 * @return string
	 */
	private function generate_conversation_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		$data = random_bytes( 16 );

		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
