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

use ProSe\Core\Ai_Intake\Supported_Language_Guard;
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
	 * Matter switch detector.
	 *
	 * @var Matter_Switch
	 */
	private Matter_Switch $matter_switch;

	/**
	 * Document request detector.
	 *
	 * @var Document_Request_Detector
	 */
	private Document_Request_Detector $documents;

	/**
	 * Language guard.
	 *
	 * @var Supported_Language_Guard
	 */
	private Supported_Language_Guard $language_guard;

	/**
	 * Constructor.
	 *
	 * @param Routing_Engine|null           $routing        Routing engine.
	 * @param Workflow_Catalog|null         $catalog        Workflow catalog.
	 * @param Fact_Extractor|null           $extractor      Fact extractor.
	 * @param Completion_Calculator|null    $completion     Completion calculator.
	 * @param Question_Selector|null        $selector       Question selector.
	 * @param Matter_Switch|null            $matter_switch  Matter switch detector.
	 * @param Document_Request_Detector|null $documents     Document request detector.
	 * @param Supported_Language_Guard|null  $language_guard Language guard.
	 */
	public function __construct(
		?Routing_Engine $routing = null,
		?Workflow_Catalog $catalog = null,
		?Fact_Extractor $extractor = null,
		?Completion_Calculator $completion = null,
		?Question_Selector $selector = null,
		?Matter_Switch $matter_switch = null,
		?Document_Request_Detector $documents = null,
		?Supported_Language_Guard $language_guard = null
	) {
		$this->catalog        = $catalog ?? new Workflow_Catalog();
		$this->routing        = $routing ?? new Routing_Engine( $this->catalog );
		$this->extractor      = $extractor ?? new Fact_Extractor( $this->catalog );
		$this->completion     = $completion ?? new Completion_Calculator();
		$this->selector       = $selector ?? new Question_Selector();
		$this->matter_switch  = $matter_switch ?? new Matter_Switch( $this->catalog );
		$this->documents      = $documents ?? new Document_Request_Detector();
		$this->language_guard = $language_guard ?? new Supported_Language_Guard();
	}

	/**
	 * Process one intake turn.
	 *
	 * @param string               $message      User message.
	 * @param array<string, mixed> $case_profile Prior case profile (round-tripped).
	 * @return array<string, mixed>
	 */
	public function process( string $message, array $case_profile = array() ): array {
		$language = $this->language_guard->assess( $message );

		if ( ! $language['supported'] ) {
			$conversation_id = isset( $case_profile['conversation_id'] ) && is_string( $case_profile['conversation_id'] ) && '' !== $case_profile['conversation_id']
				? $case_profile['conversation_id']
				: $this->generate_conversation_id();

			return array(
				'conversation_id' => $conversation_id,
				'workflow'        => $case_profile['workflow'] ?? null,
				'facts_extracted' => array(),
				'case_profile'    => $case_profile,
				'missing_fields'  => is_array( $case_profile['missing_fields'] ?? null ) ? $case_profile['missing_fields'] : array(),
				'next_question'   => (string) ( $language['message'] ?? $this->language_guard->restriction_message() ),
				'next_action'     => 'language_restricted',
				'completion'      => (int) ( $case_profile['progress'] ?? 0 ),
				'intent'          => 'language_restricted',
			);
		}

		$pending_field = isset( $case_profile['pending_field'] ) && is_string( $case_profile['pending_field'] )
			? $case_profile['pending_field']
			: '';

		$profile = Case_Profile::from_array( $case_profile );

		if ( $this->matter_switch->should_reset( $message, $profile->workflow() ) ) {
			$profile      = $this->matter_switch->reset_profile( $profile );
			$case_profile = $profile->to_array();
		}

		// Stable conversation identity for the life of the session.
		if ( '' === $profile->conversation_id() ) {
			$profile->set_conversation_id( $this->generate_conversation_id() );
		}

		$extracted = array();

		// Prior routing decision (retained when a later turn is inconclusive).
		$prior_workflow            = $profile->workflow();
		$prior_issue               = $profile->issue();
		$prior_court               = $profile->court();
		$prior_candidate_workflows = $profile->candidate_workflows();

		// 1) Workflow-agnostic content extraction FIRST, so a correction to a
		//    discriminator fact (e.g. "no" -> "actually two kids") is captured
		//    and can re-route this same turn.
		$content = $this->non_empty(
			$this->extractor->extract( $message, $this->required_fields_for( $prior_workflow ), $profile->facts()->all() )
		);

		if ( ! empty( $content ) ) {
			$profile->facts()->merge( $content );
			$extracted = array_merge( $extracted, $content );
		}

		$children_changed = $this->message_changes_children( $content );

		// 2) Pending discriminator answer (bare "yes"/"no"/number) influences
		//    routing. Only for discriminator questions, never for free-text or
		//    date fields, so a correction is not forced into the wrong slot.
		if ( '' !== $pending_field && $this->is_discriminator_field( $pending_field ) && ! $children_changed && ! $this->pending_field_already_filled( $pending_field, $profile->facts() ) ) {
			$pre = $this->non_empty( $this->extractor->infer_pending_answer( $message, $pending_field, null ) );

			if ( ! empty( $pre ) ) {
				$profile->facts()->merge( $pre );
				$extracted = array_merge( $extracted, $pre );
			}
		}

		// 2b) Capture pending workflow field answers before routing re-evaluates
		//     the turn (e.g. "Queens" for marriage_location must not be lost).
		if ( '' !== $pending_field && ! $this->is_discriminator_field( $pending_field ) && ! $children_changed && ! $this->pending_field_already_filled( $pending_field, $profile->facts() ) ) {
			$pending_type = $this->field_type( $this->required_fields_for( $prior_workflow ), $pending_field );

			if ( $this->message_answers_field( $message, $pending_field, $pending_type ) ) {
				$pre = $this->non_empty( $this->extractor->infer_pending_answer( $message, $pending_field, $pending_type ) );

				if ( ! empty( $pre ) ) {
					$profile->facts()->merge( $pre );
					$extracted = array_merge( $extracted, $pre );
				}
			}
		}

		// 3) Keep child discriminators consistent so routing follows the count.
		$this->reconcile_child_facts( $profile->facts() );

		// 4) Route with the corrected facts (re-resolves workflow on a change).
		$result = $this->routing->route_profile( $message, $profile );

		// Retain the last positive routing decision when this turn is
		// inconclusive (e.g. "Brooklyn" carries no workflow signal). This does
		// not override routing — it preserves a decision routing already made.
		$workflow = $profile->workflow();

		if ( ( null === $workflow || '' === $workflow ) && null !== $prior_workflow && '' !== $prior_workflow ) {
			$workflow = $prior_workflow;
		}

		$required_fields = $this->required_fields_for( $workflow );

		// 5) Re-extract against the resolved workflow to capture workflow-specific keys.
		$content2 = $this->non_empty(
			$this->extractor->extract( $message, $required_fields, $profile->facts()->all() )
		);

		if ( ! empty( $content2 ) ) {
			$profile->facts()->merge( $content2 );
			$extracted = array_merge( $extracted, $content2 );
		}

		// 6) Typed refinement of the pending answer — only when the message
		//    genuinely answers that field (guards against capturing corrections
		//    like "sorry i have two kids" as a date or a name).
		if ( '' !== $pending_field && ! $children_changed && ! $this->pending_field_already_filled( $pending_field, $profile->facts() ) ) {
			$type = $this->field_type( $required_fields, $pending_field );

			if ( $this->message_answers_field( $message, $pending_field, $type ) ) {
				$typed = $this->non_empty( $this->extractor->infer_pending_answer( $message, $pending_field, $type ) );

				if ( ! empty( $typed ) ) {
					$profile->facts()->merge( $typed );
					$extracted = array_merge( $extracted, $typed );
				}
			}
		}

		$this->reconcile_child_facts( $profile->facts() );
		$this->sync_routing_facts_to_workflow( $profile->facts(), $required_fields );

		$missing     = $this->completion->missing_required( $required_fields, $profile->facts() );
		$completion  = $this->completion->calculate( $required_fields, $profile->facts() );
		$routing_missing = $result->missing_fields();

		if ( empty( $routing_missing ) && ( null === $workflow || '' === $workflow ) && ! empty( $prior_candidate_workflows ) ) {
			$routing_missing = $this->routing_missing_for_candidates( $prior_candidate_workflows, $profile->facts() );
		}

		$next = $this->selector->select( $required_fields, $missing, $workflow, $routing_missing );

		$next_question    = (string) $next['question'];
		$next_action      = 'ask_question';
		$profile_array    = $profile->to_array();
		$is_complete      = empty( $missing ) && null !== $workflow && '' !== $workflow;
		$routing_complete = null !== $workflow && '' !== $workflow && empty( $routing_missing );

		// Once routing resolves (or all required fields are filled), stop asking
		// document-phase questions and offer procedural guidance instead.
		if ( '' === trim( $next_question ) && ( $is_complete || $routing_complete ) ) {
			$routing_only    = $routing_complete && ! $is_complete;
			$followup        = $this->complete_followup( $message, $workflow, $profile_array, $routing_only );
			$next_question   = $followup['question'];
			$next_action     = $followup['next_action'];
			$profile_array   = $followup['case_profile'];
		}

		// Mid-routing: user asked for blank forms before workflow is resolved.
		if ( 'ask_question' === $next_action && null !== $workflow && '' !== $workflow && ! $routing_complete && $this->documents->wants_documents( $message ) ) {
			$next_question = __( 'You can get your blank forms from Case Actions once intake is complete. For now, I just need a few more details about your matter.', 'prose-core' );
			$next_action   = 'ask_question';
		}

		// Persist the retained decision into the serialized profile.
		$profile_array['workflow'] = $workflow;

		if ( null === $profile_array['issue'] && null !== $prior_issue ) {
			$profile_array['issue'] = $prior_issue;
		}

		if ( null === $profile_array['court'] && null !== $prior_court ) {
			$profile_array['court'] = $prior_court;
		}

		if ( empty( $profile_array['candidate_workflows'] ) && ! empty( $prior_candidate_workflows ) && ( null === $workflow || '' === $workflow ) ) {
			$profile_array['candidate_workflows'] = $prior_candidate_workflows;
		}

		$profile_array['pending_field'] = ( $is_complete || $routing_complete ) ? '' : (string) $next['field'];

		$intent = 'gathering';

		if ( $is_complete ) {
			$intent = 'intake_complete';
		} elseif ( $routing_complete ) {
			$intent = 'guidance';
		}

		$response = array(
			'conversation_id' => $profile->conversation_id(),
			'workflow'        => $workflow,
			'facts_extracted' => $extracted,
			'case_profile'    => $profile_array,
			'missing_fields'  => $missing,
			'next_question'   => $next_question,
			'next_action'     => $next_action,
			'completion'      => $completion,
			'intent'          => $intent,
		);

		if ( $this->debug_enabled() ) {
			$response['debug'] = array(
				'workflow'        => $workflow,
				'required_fields' => array_values(
					array_map(
						static function ( array $field ): string {
							return (string) ( $field['key'] ?? '' );
						},
						array_filter(
							$required_fields,
							static function ( array $field ): bool {
								return true === ( $field['required'] ?? false );
							}
						)
					)
				),
				'known_facts'     => $profile->facts()->export(),
				'missing_fields'  => $missing,
				'routing_missing' => $routing_missing,
				'completion'      => $completion,
			);
		}

		return $response;
	}

	/**
	 * Build contextual guidance once intake is complete.
	 *
	 * @param string               $message       User message.
	 * @param string               $workflow      Workflow key.
	 * @param array<string, mixed> $case_profile  Case profile (mutated).
	 * @param bool                 $routing_only  True when workflow is resolved but document fields remain.
	 * @return array{question: string, next_action: string, case_profile: array<string, mixed>}
	 */
	private function complete_followup( string $message, string $workflow, array $case_profile, bool $routing_only = false ): array {
		$announced = ! empty( $case_profile['intake_complete_announced'] );

		if ( $this->wants_documents( $message ) ) {
			return array(
				'question'      => __( 'Your filing package is ready. Use the Get Documents button in Case Actions when you are ready to download your forms.', 'prose-core' ),
				'next_action'   => 'guidance',
				'case_profile'  => $this->mark_complete_announced( $case_profile ),
			);
		}

		if ( $this->wants_filing_help( $message ) ) {
			return array(
				'question'     => $this->filing_help_message( $workflow ),
				'next_action'  => 'guidance',
				'case_profile' => $this->mark_complete_announced( $case_profile ),
			);
		}

		if ( ! $announced ) {
			$case_profile = $this->mark_complete_announced( $case_profile );

			return array(
				'question'     => $routing_only ? $this->routing_complete_message( $workflow ) : $this->completion_message( $workflow ),
				'next_action'  => $routing_only ? 'guidance' : 'complete_intake',
				'case_profile' => $case_profile,
			);
		}

		return array(
			'question'     => $this->guidance_followup( $message ),
			'next_action'  => 'guidance',
			'case_profile' => $case_profile,
		);
	}

	/**
	 * Mark that the one-time completion announcement was shown.
	 *
	 * @param array<string, mixed> $case_profile Case profile.
	 * @return array<string, mixed>
	 */
	private function mark_complete_announced( array $case_profile ): array {
		$case_profile['intake_complete_announced'] = true;

		return $case_profile;
	}

	/**
	 * Whether the user is asking for documents or a download.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function wants_documents( string $message ): bool {
		return $this->documents->wants_documents( $message );
	}

	/**
	 * Whether the user is asking how to file or what happens next.
	 *
	 * @param string $message User message.
	 * @return bool
	 */
	private function wants_filing_help( string $message ): bool {
		$text = strtolower( trim( $message ) );

		$phrases = array(
			'how do i file',
			'how to file',
			'what happens next',
			'what do i do next',
			'next step',
			'next steps',
			'after i file',
			'where do i file',
			'where to file',
			'serve',
			'service',
			'deadline',
		);

		foreach ( $phrases as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Short filing-process guidance (no legal advice).
	 *
	 * @param string $workflow Workflow key.
	 * @return string
	 */
	private function filing_help_message( string $workflow ): string {
		unset( $workflow );

		$templates = array(
			__( 'Generally, you’ll complete the forms, sign where indicated, file them with the court clerk, and serve copies on the other party as required. The exact steps depend on your matter — tell me which part you want explained (filing, service, or deadlines).', 'prose-core' ),
			__( 'After your forms are ready, the usual path is: review everything, sign, file with the court, then serve the other side. Ask me about filing, service, or any form you’re unsure about.', 'prose-core' ),
			__( 'Your package below lists the forms for this matter. Once they’re filled out, they’re typically filed with the court and served on the other party. What would you like help with — filing, service, or a specific form?', 'prose-core' ),
		);

		$index = function_exists( 'wp_rand' ) ? wp_rand( 0, count( $templates ) - 1 ) : array_rand( $templates );

		return $templates[ $index ];
	}

	/**
	 * Varied short replies after completion — avoids repeating the workflow title.
	 *
	 * @param string $message User message.
	 * @return string
	 */
	private function guidance_followup( string $message ): string {
		$text = strtolower( trim( $message ) );

		if ( preg_match( '/\b(thanks|thank you|ok|okay|got it|great|perfect)\b/', $text ) ) {
			$thanks = array(
				__( 'You’re welcome. I’m here if anything else comes up about your forms or filing.', 'prose-core' ),
				__( 'Glad to help. Ask anytime if you need something explained on the forms below.', 'prose-core' ),
			);

			$index = function_exists( 'wp_rand' ) ? wp_rand( 0, count( $thanks ) - 1 ) : array_rand( $thanks );

			return $thanks[ $index ];
		}

		if ( preg_match( '/\b(hmm|huh|what|confused|unsure|not sure)\b/', $text ) ) {
			return __( 'No rush — I’m here when you’re ready. You can ask about a specific form, how to file, or use Get Documents in Case Actions when your forms are ready.', 'prose-core' );
		}

		$templates = array(
			__( 'I’m still here. Ask me to explain any form or filing step, or use Get Documents in Case Actions when you are ready.', 'prose-core' ),
			__( 'Feel free to ask about deadlines, service, or what a specific form is for.', 'prose-core' ),
			__( 'Your filing package is identified. Tell me what you would like help with — a form, filing, or next steps.', 'prose-core' ),
			__( 'What would be most helpful right now — understanding a form or the filing steps?', 'prose-core' ),
		);

		$index = function_exists( 'wp_rand' ) ? wp_rand( 0, count( $templates ) - 1 ) : array_rand( $templates );

		return $templates[ $index ];
	}

	/**
	 * Message once routing resolves the workflow but document fields remain.
	 *
	 * @param string $workflow Workflow key.
	 * @return string
	 */
	private function routing_complete_message( string $workflow ): string {
		$title = $this->workflow_short_title( $workflow );

		return sprintf(
			/* translators: %s: short matter title. */
			__( 'I can help you with your %s matter. Your filing path is ready — use Get Documents in Case Actions to download your forms. You will fill in personal details on the forms in a later step.', 'prose-core' ),
			$title
		);
	}

	/**
	 * Build a varied completion message once intake has all required fields.
	 *
	 * @param string $workflow Workflow key.
	 * @return string
	 */
	private function completion_message( string $workflow ): string {
		$title = $this->workflow_short_title( $workflow );

		$templates = array(
			/* translators: %s: short matter title. */
			__( 'Good news — I have everything I need for your %s matter. You can review the case summary and use Get Documents in Case Actions when you are ready.', 'prose-core' ),
			/* translators: %s: short matter title. */
			__( 'That covers it for your %s matter. Ask me anything about the forms or the filing process, or download your forms from Case Actions.', 'prose-core' ),
			/* translators: %s: short matter title. */
			__( 'We are all set on your %s matter. Let me know if you would like help understanding any form or the next steps.', 'prose-core' ),
		);

		$index = function_exists( 'wp_rand' ) ? wp_rand( 0, count( $templates ) - 1 ) : array_rand( $templates );

		return sprintf( $templates[ $index ], $title );
	}

	/**
	 * Short human-readable workflow label (not the full catalog description).
	 *
	 * @param string $workflow Workflow key.
	 * @return string
	 */
	private function workflow_short_title( string $workflow ): string {
		$title = ucwords( str_replace( array( '_nyc', '_', '-' ), array( '', ' ', ' ' ), $workflow ) );

		return rtrim( trim( $title ), '.' );
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
	 * Whether a field is a routing discriminator (drives workflow selection).
	 *
	 * @param string $field Field key.
	 * @return bool
	 */
	private function is_discriminator_field( string $field ): bool {
		return in_array(
			$field,
			array( 'children', 'has_minor_children', 'child_count', 'spouse_agrees', 'spouse_responded', 'active_divorce', 'protection_needed', 'is_default' ),
			true
		);
	}

	/**
	 * Whether a pending field already holds a usable value.
	 *
	 * @param string                           $field Field key.
	 * @param \ProSe\Core\Routing\Fact_Store   $facts Fact store.
	 * @return bool
	 */
	private function pending_field_already_filled( string $field, \ProSe\Core\Routing\Fact_Store $facts ): bool {
		if ( ! $facts->has( $field ) ) {
			return false;
		}

		$value = $facts->get( $field );

		if ( null === $value ) {
			return false;
		}

		if ( is_string( $value ) && '' === trim( $value ) ) {
			return false;
		}

		if ( is_array( $value ) && array() === $value ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether this turn changed the children discriminator.
	 *
	 * @param array<string, mixed> $content Extracted content facts.
	 * @return bool
	 */
	private function message_changes_children( array $content ): bool {
		return array_key_exists( 'child_count', $content ) || array_key_exists( 'has_minor_children', $content );
	}

	/**
	 * Keep child discriminators consistent with child_count so routing follows.
	 *
	 * @param \ProSe\Core\Routing\Fact_Store $facts Fact store.
	 * @return void
	 */
	private function reconcile_child_facts( \ProSe\Core\Routing\Fact_Store $facts ): void {
		if ( ! $facts->has( 'child_count' ) ) {
			return;
		}

		$count = $facts->get( 'child_count' );

		if ( ! is_numeric( $count ) ) {
			return;
		}

		$has = (int) $count > 0;

		$facts->set( 'children', $has );
		$facts->set( 'has_minor_children', $has );
	}

	/**
	 * Whether the message genuinely answers the pending field.
	 *
	 * Date/integer/boolean fields require a value of that type; free-text fields
	 * accept anything that is not an explicit correction.
	 *
	 * @param string      $message Message.
	 * @param string      $field   Pending field key.
	 * @param string|null $type    Field type.
	 * @return bool
	 */
	private function message_answers_field( string $message, string $field, ?string $type ): bool {
		if ( in_array( $field, array( 'children', 'has_minor_children' ), true ) ) {
			$normalized = strtolower( trim( $message ) );

			if ( $this->extractor->strict_value( $message, 'boolean' ) !== null ) {
				return true;
			}

			if ( preg_match( '/\bno children\b|\bwithout children\b|\bno kids\b|\bchildless\b/', $normalized ) ) {
				return true;
			}

			if ( preg_match( '/\b\d+\s+(?:children|child|kids|kid)\b/', $normalized ) ) {
				return true;
			}

			return false;
		}

		if ( in_array( $type, array( 'date', 'integer', 'boolean' ), true ) ) {
			return null !== $this->extractor->strict_value( $message, $type );
		}

		return ! $this->is_correction_message( $message );
	}

	/**
	 * Whether the message is an explicit correction rather than a fresh answer.
	 *
	 * @param string $message Message.
	 * @return bool
	 */
	private function is_correction_message( string $message ): bool {
		$text = strtolower( trim( $message ) );

		foreach ( array( 'sorry', 'actually', 'i meant', 'i mean', 'oops', 'my mistake', 'wrong', 'no wait', 'correction' ) as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				return true;
			}
		}

		return false;
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
	 * Mirror routing discriminator facts onto workflow required_field keys.
	 *
	 * Routing uses `children`; workflow metadata uses `has_minor_children`.
	 *
	 * @param \ProSe\Core\Routing\Fact_Store $facts           Fact store.
	 * @param array<int, array<string, mixed>> $required_fields Required fields.
	 * @return void
	 */
	private function sync_routing_facts_to_workflow( \ProSe\Core\Routing\Fact_Store $facts, array $required_fields ): void {
		$keys = array();

		foreach ( $required_fields as $field ) {
			$key = (string) ( $field['key'] ?? '' );

			if ( '' !== $key ) {
				$keys[ $key ] = true;
			}
		}

		if ( isset( $keys['has_minor_children'] ) && $facts->has( 'children' ) && ! $facts->has( 'has_minor_children' ) ) {
			$facts->set( 'has_minor_children', (bool) $facts->get( 'children' ) );
		}
	}

	/**
	 * Routing missing fields for a preserved candidate set.
	 *
	 * @param string[]                           $candidate_workflows Candidate workflows.
	 * @param \ProSe\Core\Routing\Fact_Store     $facts               Facts.
	 * @return string[]
	 */
	private function routing_missing_for_candidates( array $candidate_workflows, \ProSe\Core\Routing\Fact_Store $facts ): array {
		$detector = new \ProSe\Core\Routing\Validators\Missing_Info_Detector();

		return $detector->detect( $candidate_workflows, $facts );
	}

	/**
	 * Whether intake debug payload should be attached.
	 *
	 * @return bool
	 */
	private function debug_enabled(): bool {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}

		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
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
