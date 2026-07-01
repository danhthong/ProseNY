<?php
/**
 * Case Manager presenter — deterministic Case Snapshot, timeline, and upcoming documents.
 *
 * Appends structured procedural context derived from Case Memory and the Workflow Engine.
 * Values are never hardcoded; all labels come from workflow definitions and case state.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Ai_Intake;

use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Guidance\Guidance_Repository;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Manager_Presenter
 */
final class Case_Manager_Presenter {

	/**
	 * Case-manager instructions shared by conversational and stage-transition prompts.
	 */
	public const ROLE_INSTRUCTIONS = <<<'TXT'
You are the user's Case Manager throughout their New York legal journey — not a generic chatbot.

Every meaningful response should help the user answer:
• Where am I?
• What have I already completed?
• Why is this stage important?
• What should I do next?
• What should I prepare?
• What mistakes should I avoid?

CASE CONTEXT CONTINUITY
- Read case_memory.facts and case_memory.workflow_assessment.confirmed_facts — never re-ask confirmed facts.
- When the user answers one routing question, acknowledge it briefly (e.g. "No children under 21 — got it.") then continue collecting ONLY remaining topics in missing_information.
- Never abandon partially completed intake. Combine remaining questions in one natural message when several are still missing.
- While routing is incomplete, you may briefly explain how the current procedural stage generally works so the user is not left waiting in silence.

WORKFLOW CONFIDENCE
- Never present a likely workflow as confirmed. Use case_memory.workflow_assessment.status ("Likely" vs "Confirmed").
- When status is "likely" or "gathering", speak in conditional terms until the Workflow Engine resolves the workflow.

ADAPTIVE GUIDANCE
- If the user uses procedural language or asks a narrow question, be concise.
- If the user seems unfamiliar, provide more education.
- Do not repeat long explanations the user has already received in recent_messages.

EXPLAIN WHY
- Whenever you request missing information, explain WHY it matters for routing or procedure — never ask a bare question.
- Use case_memory.missing_information topics and workflow_assessment.outstanding[].why when available.

Structure conversation_reply as clear prose with labeled sections when workflow context exists:
- Open with personalized guidance referencing THIS user's known facts.
- "Why this matters" — plain-language legal purpose of the current stage.
- "What to expect" — what usually happens during this stage.
- "Common mistakes" — short, stage-relevant pitfalls only.
- "Next step" — one clear action the user should take now.
When a stage was just completed, summarize progress (completed stages → entering new stage).
Explain procedural concepts in plain English; briefly define legal terms when used.
Never give legal strategy or tell the user what outcome to pursue.

Do NOT include AI Assessment, Case Dashboard, Stage Timeline, Upcoming Documents, or "You may also want to know" blocks in conversation_reply — the system appends those from Case Memory automatically.
Do NOT render procedural roadmap step lists — the UI shows the roadmap card separately.
Never use mandatory language ("you must", "you are required to").
Reply in plain conversational prose (no JSON, no markdown headings) inside conversation_reply.
TXT;

	/**
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * @var Workflow_Progression_Service
	 */
	private Workflow_Progression_Service $progression;

	/**
	 * @var Guidance_Repository
	 */
	private Guidance_Repository $guidance;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null             $workflows   Workflow catalog.
	 * @param Workflow_Progression_Service|null $progression Progression service.
	 * @param Guidance_Repository|null          $guidance    Stage guidance repository.
	 */
	public function __construct(
		?Workflow_Catalog $workflows = null,
		?Workflow_Progression_Service $progression = null,
		?Guidance_Repository $guidance = null
	) {
		$this->workflows   = $workflows ?? new Workflow_Catalog();
		$this->progression = $progression ?? new Workflow_Progression_Service( $this->workflows );
		$this->guidance    = $guidance ?? new Guidance_Repository();
	}

	/**
	 * Whether deterministic case-manager blocks should be appended.
	 *
	 * @param string $reply   Assistant reply.
	 * @param string $message User message.
	 * @return bool
	 */
	public function should_append( string $reply, string $message ): bool {
		$reply = trim( $reply );

		if ( '' === $reply ) {
			return false;
		}

		if ( $this->is_closing_message( $message ) ) {
			return false;
		}

		$lower = strtolower( $reply );

		foreach ( array( 'team member may follow up', 'needs a little more help with your intake' ) as $phrase ) {
			if ( str_contains( $lower, $phrase ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Append deterministic case-manager blocks to a reply.
	 *
	 * @param string               $reply   Assistant reply.
	 * @param array<string, mixed> $context Presentation context.
	 * @return string
	 */
	public function append_sections( string $reply, array $context ): string {
		$message = (string) ( $context['message'] ?? '' );

		if ( ! $this->should_append( $reply, $message ) ) {
			return $reply;
		}

		$blocks   = array();
		$assessment = $this->render_ai_assessment( $context );

		if ( '' !== $assessment ) {
			$blocks[] = $assessment;
		}

		$dashboard = $this->render_case_dashboard( $context );

		if ( '' !== $dashboard ) {
			$blocks[] = $dashboard;
		}

		$timeline = $this->render_stage_timeline( $context );

		if ( '' !== $timeline ) {
			$blocks[] = $timeline;
		}

		$upcoming = $this->render_upcoming_documents( $context );

		if ( '' !== $upcoming ) {
			$blocks[] = $upcoming;
		}

		$anticipate = $this->render_anticipate_needs( $context );

		if ( '' !== $anticipate ) {
			$blocks[] = $anticipate;
		}

		if ( empty( $blocks ) ) {
			return $reply;
		}

		if ( $this->reply_already_has_blocks( $reply ) ) {
			return $reply;
		}

		return rtrim( $reply ) . "\n\n" . implode( "\n\n", $blocks );
	}

	/**
	 * Render AI Assessment block (workflow confidence and outstanding facts).
	 *
	 * @param array<string, mixed> $context Presentation context.
	 * @return string
	 */
	public function render_ai_assessment( array $context ): string {
		$assessment = $this->assessment_data( $context );

		if ( empty( $assessment ) ) {
			return '';
		}

		$lines   = array( "\u{1F9E0} " . __( 'Current Assessment', 'prose-core' ) );
		$status  = (string) ( $assessment['status_label'] ?? '' );
		$title   = trim( (string) ( $assessment['workflow_title'] ?? '' ) );
		$percent = (int) ( $assessment['confidence_percent'] ?? 0 );

		if ( '' !== $title ) {
			$lines[] = __( 'Workflow', 'prose-core' ) . "\n" . trim( $status . ' ' . $title );
		}

		if ( $percent > 0 && 'confirmed' !== (string) ( $assessment['status'] ?? '' ) ) {
			$lines[] = __( 'Confidence', 'prose-core' ) . "\n" . $percent . '%';
		}

		$confirmed = (array) ( $assessment['confirmed_facts'] ?? array() );

		if ( ! empty( $confirmed ) ) {
			$lines[] = __( 'Confirmed', 'prose-core' ) . "\n" . implode( "\n", array_map(
				static function ( string $fact ): string {
					return "\u{2713} " . $fact;
				},
				$confirmed
			) );
		}

		$outstanding = (array) ( $assessment['outstanding'] ?? array() );

		if ( ! empty( $outstanding ) ) {
			$waiting = array();

			foreach ( $outstanding as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$label = trim( (string) ( $row['label'] ?? '' ) );

				if ( '' !== $label ) {
					$waiting[] = "\u{25A1} " . $label;
				}
			}

			if ( ! empty( $waiting ) ) {
				$lines[] = __( 'Waiting For', 'prose-core' ) . "\n" . implode( "\n", $waiting );
			}
		}

		$reason = trim( (string) ( $assessment['reason'] ?? '' ) );

		if ( '' !== $reason ) {
			$lines[] = __( 'Reason', 'prose-core' ) . "\n" . $reason;
		}

		return count( $lines ) > 1 ? implode( "\n\n", $lines ) : '';
	}

	/**
	 * Render Case Dashboard from canonical case context.
	 *
	 * @param array<string, mixed> $context Presentation context.
	 * @return string
	 */
	public function render_case_dashboard( array $context ): string {
		$case_summary = is_array( $context['case_summary'] ?? null ) ? $context['case_summary'] : array();
		$case_memory  = is_array( $context['case_memory'] ?? null ) ? $context['case_memory'] : array();
		$assessment   = $this->assessment_data( $context );
		$workflow     = trim( (string) ( $context['workflow'] ?? $case_summary['workflow'] ?? $case_memory['workflow'] ?? '' ) );
		$lines        = array();
		$court        = trim( (string) ( $case_summary['court_label'] ?? $case_memory['court'] ?? '' ) );
		$matter       = trim( (string) ( $case_summary['workflow_title'] ?? '' ) );
		$stage        = is_array( $case_summary['current_stage'] ?? null ) ? $case_summary['current_stage'] : array();
		$stage_title  = trim( (string) ( $stage['title'] ?? '' ) );
		$completed    = is_array( $case_summary['completed_stages'] ?? null ) ? $case_summary['completed_stages'] : array();
		$next_stage   = is_array( $case_memory['next_stage'] ?? null ) ? $case_memory['next_stage'] : array();
		$next_title   = trim( (string) ( $next_stage['title'] ?? '' ) );
		$stage_ctx    = is_array( $context['stage_context'] ?? null ) ? $context['stage_context'] : array();
		$next_action  = trim( (string) ( $stage_ctx['next_action']['message'] ?? '' ) );

		if ( '' !== $court ) {
			$lines[] = __( 'Court', 'prose-core' ) . "\n" . $court;
		}

		if ( '' !== $matter ) {
			$lines[] = __( 'Matter', 'prose-core' ) . "\n" . $matter;
		}

		if ( '' !== $workflow ) {
			$lines[] = __( 'Workflow', 'prose-core' ) . "\n" . $this->workflow_title( $workflow );
		}

		if ( '' !== $stage_title ) {
			$lines[] = __( 'Current Stage', 'prose-core' ) . "\n" . $stage_title;
		}

		$progress = $this->stage_progress_label( $workflow, $stage, $completed, $context );

		if ( '' !== $progress ) {
			$lines[] = __( 'Progress', 'prose-core' ) . "\n" . $progress;
		}

		if ( ! empty( $completed ) ) {
			$lines[] = __( 'Completed Stages', 'prose-core' ) . "\n" . implode( ', ', $completed );
		}

		if ( '' !== $next_title ) {
			$lines[] = __( 'Next Stage', 'prose-core' ) . "\n" . $next_title;
		}

		if ( ! empty( $assessment ) && 'confirmed' !== (string) ( $assessment['status'] ?? '' ) ) {
			$lines[] = __( 'Current Confidence', 'prose-core' ) . "\n" . (int) ( $assessment['confidence_percent'] ?? 0 ) . '%';
		}

		$key_facts = is_array( $case_summary['key_facts'] ?? null ) ? $case_summary['key_facts'] : array();

		if ( ! empty( $key_facts ) ) {
			$lines[] = __( 'Known Facts', 'prose-core' ) . "\n" . implode( '; ', $key_facts );
		}

		$outstanding = (array) ( $assessment['outstanding'] ?? array() );

		if ( ! empty( $outstanding ) ) {
			$labels = array();

			foreach ( $outstanding as $row ) {
				if ( is_array( $row ) && ! empty( $row['label'] ?? '' ) ) {
					$labels[] = (string) $row['label'];
				}
			}

			if ( ! empty( $labels ) ) {
				$lines[] = __( 'Outstanding Questions', 'prose-core' ) . "\n" . implode( '; ', $labels );
			}
		}

		if ( '' !== $next_action ) {
			$lines[] = __( 'Recommended Next Action', 'prose-core' ) . "\n" . $next_action;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		$divider = str_repeat( '-', 40 );

		return "\u{1F4CD} " . __( 'Case Dashboard', 'prose-core' ) . "\n" . $divider . "\n" . implode( "\n\n", $lines ) . "\n" . $divider;
	}

	/**
	 * Proactive topics the user may want next.
	 *
	 * @param array<string, mixed> $context Presentation context.
	 * @return string
	 */
	public function render_anticipate_needs( array $context ): string {
		$stage_ctx = is_array( $context['stage_context'] ?? null ) ? $context['stage_context'] : array();
		$stage_id  = sanitize_key( (string) ( $stage_ctx['current_stage']['id'] ?? '' ) );

		if ( '' === $stage_id ) {
			return '';
		}

		$guidance = $this->guidance->read_stage( $stage_id );

		if ( ! is_array( $guidance ) ) {
			return '';
		}

		$bullets = array();

		foreach ( (array) ( $guidance['tips'] ?? array() ) as $tip ) {
			$tip = trim( (string) $tip );

			if ( '' !== $tip ) {
				$bullets[] = '• ' . $tip;
			}
		}

		if ( count( $bullets ) < 2 ) {
			$future = (array) ( $stage_ctx['future_stages'] ?? array() );

			if ( ! empty( $future[0]['title'] ?? '' ) ) {
				/* translators: %s: next stage title. */
				$bullets[] = '• ' . sprintf( __( 'What happens during %s', 'prose-core' ), (string) $future[0]['title'] );
			}
		}

		if ( empty( $bullets ) ) {
			return '';
		}

		$lines = array( __( 'You may also want to know', 'prose-core' ) );

		return $lines[0] . "\n\n" . implode( "\n", array_slice( $bullets, 0, 3 ) );
	}

	/**
	 * @deprecated Use render_case_dashboard().
	 *
	 * @param array<string, mixed> $context Presentation context.
	 * @return string
	 */
	public function render_case_snapshot( array $context ): string {
		return $this->render_case_dashboard( $context );
	}

	/**
	 * @param array<string, mixed> $context Presentation context.
	 * @return array<string, mixed>
	 */
	private function assessment_data( array $context ): array {
		$case_memory = is_array( $context['case_memory'] ?? null ) ? $context['case_memory'] : array();

		if ( is_array( $case_memory['workflow_assessment'] ?? null ) ) {
			return $case_memory['workflow_assessment'];
		}

		if ( is_array( $context['workflow_assessment'] ?? null ) ) {
			return $context['workflow_assessment'];
		}

		return array();
	}

	/**
	 * @param string $reply Reply text.
	 * @return bool
	 */
	private function reply_already_has_blocks( string $reply ): bool {
		foreach ( array( 'Case Dashboard', 'Your Case', 'Current Assessment' ) as $marker ) {
			if ( str_contains( $reply, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render workflow stage timeline with completion markers.
	 *
	 * @param array<string, mixed> $context Presentation context.
	 * @return string
	 */
	public function render_stage_timeline( array $context ): string {
		$workflow    = trim( (string) ( $context['workflow'] ?? '' ) );
		$facts       = is_array( $context['facts'] ?? null ) ? $context['facts'] : array();
		$case_summary = is_array( $context['case_summary'] ?? null ) ? $context['case_summary'] : array();

		if ( '' === $workflow ) {
			return '';
		}

		$stages = $this->progression->get_stages( $workflow, $facts );

		if ( empty( $stages ) ) {
			return '';
		}

		$current_id = sanitize_key( (string) ( $case_summary['current_stage']['id'] ?? '' ) );

		$lines         = array();
		$found_current = false;

		foreach ( $stages as $stage_id ) {
			$stage_id   = sanitize_key( (string) $stage_id );
			$title      = $this->stage_title( $stage_id );
			$is_current = ( '' !== $current_id && $stage_id === $current_id );

			if ( $is_current ) {
				$found_current = true;
				$lines[]       = "\u{1F7E2} " . $title . ' (' . __( 'Current', 'prose-core' ) . ')';
				continue;
			}

			if ( ! $found_current ) {
				$lines[] = "\u{2714} " . $title;
				continue;
			}

			$lines[] = "\u{2B1C} " . $title;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return __( 'Stage Timeline', 'prose-core' ) . "\n" . implode( "\n", $lines );
	}

	/**
	 * Render upcoming document codes from future workflow stages.
	 *
	 * @param array<string, mixed> $context Presentation context.
	 * @return string
	 */
	public function render_upcoming_documents( array $context ): string {
		$workflow     = trim( (string) ( $context['workflow'] ?? '' ) );
		$stage_context = is_array( $context['stage_context'] ?? null ) ? $context['stage_context'] : array();
		$future       = (array) ( $stage_context['future_stages'] ?? array() );

		if ( '' === $workflow || empty( $future ) ) {
			return '';
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( ! is_array( $definition ) ) {
			return '';
		}

		$target_stage = is_array( $future[0] ?? null ) ? $future[0] : array();
		$target_id    = sanitize_key( (string) ( $target_stage['id'] ?? '' ) );
		$target_title = trim( (string) ( $target_stage['title'] ?? '' ) );

		if ( '' === $target_id ) {
			return '';
		}

		$codes = $this->form_codes_for_stage( $definition, $target_id );

		if ( empty( $codes ) ) {
			return '';
		}

		$lines   = array( __( 'Upcoming Documents', 'prose-core' ) );
		$lines[] = implode( "\n", $codes );

		if ( '' !== $target_title ) {
			/* translators: %s: future procedural stage title. */
			$lines[] = sprintf( __( 'These documents are typically prepared during the %s stage.', 'prose-core' ), $target_title );
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * @param string               $workflow  Workflow key.
	 * @param array<string, mixed> $stage     Current stage row.
	 * @param string[]             $completed Completed stage labels.
	 * @param array<string, mixed> $context   Full context.
	 * @return string
	 */
	private function stage_progress_label( string $workflow, array $stage, array $completed, array $context ): string {
		$facts = is_array( $context['facts'] ?? null ) ? $context['facts'] : array();
		$stages = '' !== $workflow ? $this->progression->get_stages( $workflow, $facts ) : array();
		$total  = count( $stages );

		if ( $total < 2 ) {
			return '';
		}

		$current_id = sanitize_key( (string) ( $stage['id'] ?? '' ) );
		$done       = 0;

		foreach ( $stages as $stage_id ) {
			if ( sanitize_key( (string) $stage_id ) === $current_id ) {
				break;
			}

			++$done;
		}

		if ( 0 === $done && empty( $completed ) ) {
			return '';
		}

		if ( $done < count( $completed ) ) {
			$done = count( $completed );
		}

		/* translators: 1: completed stage count, 2: total stage count. */
		return sprintf( __( '%1$d of %2$d stages completed', 'prose-core' ), min( $done, $total ), $total );
	}

	/**
	 * @param array<string, mixed> $definition Workflow definition.
	 * @param string               $stage_id   Stage slug.
	 * @return string[]
	 */
	private function form_codes_for_stage( array $definition, string $stage_id ): array {
		$codes = array();

		foreach ( array( 'required_forms', 'optional_forms' ) as $bucket ) {
			foreach ( (array) ( $definition[ $bucket ] ?? array() ) as $group ) {
				if ( ! is_array( $group ) || sanitize_key( (string) ( $group['stage'] ?? '' ) ) !== $stage_id ) {
					continue;
				}

				foreach ( (array) ( $group['forms'] ?? array() ) as $form ) {
					if ( ! is_array( $form ) ) {
						continue;
					}

					$code = strtoupper( trim( (string) ( $form['code'] ?? '' ) ) );

					if ( '' !== $code && ! in_array( $code, $codes, true ) ) {
						$codes[] = $code;
					}
				}
			}
		}

		return array_slice( $codes, 0, 8 );
	}

	/**
	 * @param string $workflow Workflow key.
	 * @return string
	 */
	private function workflow_title( string $workflow ): string {
		if ( '' === $workflow ) {
			return '';
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( ! is_array( $definition ) ) {
			return ucwords( str_replace( array( '_', '-' ), ' ', $workflow ) );
		}

		$title = trim( (string) ( $definition['description'] ?? $definition['title'] ?? $definition['name'] ?? '' ) );

		return '' !== $title ? $title : ucwords( str_replace( array( '_', '-' ), ' ', $workflow ) );
	}

	/**
	 * @param string $stage_id Stage slug.
	 * @return string
	 */
	private function stage_title( string $stage_id ): string {
		if ( '' === $stage_id ) {
			return '';
		}

		$guidance = $this->guidance->read_stage( $stage_id );
		$title    = is_array( $guidance ) ? trim( (string) ( $guidance['title'] ?? '' ) ) : '';

		if ( '' !== $title ) {
			return $title;
		}

		return ucwords( str_replace( '_', ' ', $stage_id ) );
	}

	/**
	 * @param string $message User message.
	 * @return bool
	 */
	private function is_closing_message( string $message ): bool {
		$text = strtolower( trim( $message ) );

		if ( '' === $text || strlen( $text ) > 80 || str_contains( $text, '?' ) ) {
			return false;
		}

		$normalized = trim( preg_replace( '/[^\p{L}\p{N}\s\']/u', '', $text ) ?? $text );
		$patterns   = array(
			'/^(okay|ok|okey)( thank(?:s| you)( so much)?)?[!.]*$/',
			'/^thank(?:s| you)( so much)?[!.]*$/',
			'/^(got it|sounds good|perfect|great|awesome|cool|alright|all right)[!.]*$/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $normalized ) ) {
				return true;
			}
		}

		return false;
	}
}
