<?php
/**
 * Case summary presenter — structured current-state snapshot for AI and UI.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Forms\Engine\Workflow_Progression_Service;
use ProSe\Core\Guidance\Guidance_Repository;
use ProSe\Core\Routing\Court_Routing_Explainer;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Summary_Presenter
 */
final class Case_Summary_Presenter {

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
	 * @param Guidance_Repository|null          $guidance    Stage guidance.
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
	 * Build a structured case summary from deterministic context.
	 *
	 * @param array<string, mixed> $input Context input.
	 * @return array<string, mixed>
	 */
	public function build( array $input ): array {
		$workflow        = trim( (string) ( $input['workflow'] ?? '' ) );
		$facts           = is_array( $input['facts'] ?? null ) ? $input['facts'] : array();
		$stage_context   = is_array( $input['stage_context'] ?? null ) ? $input['stage_context'] : array();
		$roadmap         = is_array( $input['roadmap'] ?? null ) ? $input['roadmap'] : array();
		$current_stage   = is_array( $stage_context['current_stage'] ?? null ) ? $stage_context['current_stage'] : array();
		$stage_id        = sanitize_key( (string) ( $current_stage['id'] ?? '' ) );
		$stage_title     = trim( (string) ( $current_stage['title'] ?? '' ) );
		$forms           = $this->normalize_forms( (array) ( $stage_context['stage_forms'] ?? array() ) );
		$court           = trim( (string) ( $input['court'] ?? '' ) );
		$issue           = trim( (string) ( $input['issue'] ?? $facts['issue'] ?? '' ) );
		$procedural_node = trim( (string) ( $input['procedural_node'] ?? $stage_context['procedural_node'] ?? '' ) );
		$completed       = $this->completed_stage_labels_from_node( $workflow, $procedural_node, $facts );

		if ( empty( $completed ) ) {
			$completed = $this->completed_stage_labels( $roadmap );
		}

		if ( '' === $stage_title && '' !== $stage_id ) {
			$stage_title = ucwords( str_replace( '_', ' ', $stage_id ) );
		}

		return array(
			'workflow'          => $workflow,
			'workflow_title'    => $this->workflow_title( $workflow ),
			'issue'             => $issue,
			'court'             => $court,
			'court_label'       => '' !== $court ? Court_Routing_Explainer::court_label( $court ) : '',
			'procedural_node'   => $procedural_node,
			'current_stage'     => array(
				'id'    => $stage_id,
				'title' => $stage_title,
			),
			'completed_stages'  => $completed,
			'current_forms'     => $forms,
			'forms_visible'     => ! empty( $stage_context['forms_visible'] ),
			'completion'        => (int) ( $input['completion'] ?? 0 ),
			'key_facts'         => $this->key_facts( $facts ),
		);
	}

	/**
	 * Compact text block for LLM conversation_summary / case_summary prompt field.
	 *
	 * @param array<string, mixed> $summary Structured summary.
	 * @return string
	 */
	public function to_prompt_text( array $summary ): string {
		$lines = array();

		if ( ! empty( $summary['workflow_title'] ) ) {
			$lines[] = __( 'Matter:', 'prose-core' ) . ' ' . (string) $summary['workflow_title'];
		}

		if ( ! empty( $summary['court_label'] ) ) {
			$lines[] = __( 'Court:', 'prose-core' ) . ' ' . (string) $summary['court_label'];
		}

		$stage = is_array( $summary['current_stage'] ?? null ) ? $summary['current_stage'] : array();
		$stage_label = trim( (string) ( $stage['title'] ?? '' ) );
		$stage_id    = trim( (string) ( $stage['id'] ?? '' ) );

		if ( '' !== $stage_label || '' !== $stage_id ) {
			$current = __( 'Current procedural stage:', 'prose-core' ) . ' ' . $stage_label;

			if ( '' !== $stage_id && $stage_id !== sanitize_key( $stage_label ) ) {
				$current .= ' (' . $stage_id . ')';
			}

			$lines[] = $current;
		}

		$completed = is_array( $summary['completed_stages'] ?? null ) ? $summary['completed_stages'] : array();

		if ( ! empty( $completed ) ) {
			$lines[] = __( 'Completed stages:', 'prose-core' ) . ' ' . implode( ', ', $completed );
		}

		$forms = is_array( $summary['current_forms'] ?? null ) ? $summary['current_forms'] : array();

		if ( ! empty( $forms ) ) {
			$form_parts = array();

			foreach ( $forms as $form ) {
				$code  = (string) ( $form['code'] ?? '' );
				$title = (string) ( $form['title'] ?? $code );
				$line  = $code . ( '' !== $title && $title !== $code ? ' — ' . $title : '' );

				if ( empty( $form['required'] ) ) {
					$line .= ' ' . __( '(if applicable)', 'prose-core' );
				}

				$form_parts[] = $line;
			}

			$lines[] = __( 'Forms for the current stage:', 'prose-core' ) . ' ' . implode( '; ', $form_parts );
		}

		$key_facts = is_array( $summary['key_facts'] ?? null ) ? $summary['key_facts'] : array();

		if ( ! empty( $key_facts ) ) {
			$lines[] = __( 'Key facts:', 'prose-core' ) . ' ' . implode( '; ', $key_facts );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Extra rows for the Case Actions summary panel.
	 *
	 * @param array<string, mixed> $summary Structured summary.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function to_action_rows( array $summary ): array {
		$rows    = array();
		$stage   = is_array( $summary['current_stage'] ?? null ) ? $summary['current_stage'] : array();
		$title   = trim( (string) ( $stage['title'] ?? '' ) );
		$forms   = is_array( $summary['current_forms'] ?? null ) ? $summary['current_forms'] : array();
		$done    = is_array( $summary['completed_stages'] ?? null ) ? $summary['completed_stages'] : array();

		if ( '' !== $title ) {
			$rows[] = array(
				'label' => __( 'Current stage', 'prose-core' ),
				'value' => $title,
			);
		}

		if ( ! empty( $done ) ) {
			$rows[] = array(
				'label' => __( 'Completed stages', 'prose-core' ),
				'value' => implode( ', ', $done ),
			);
		}

		if ( ! empty( $forms ) ) {
			$codes = array_map(
				static function ( array $form ): string {
					return (string) ( $form['code'] ?? '' );
				},
				$forms
			);

			$rows[] = array(
				'label' => __( 'Forms for this step', 'prose-core' ),
				'value' => implode( ', ', array_filter( $codes ) ),
			);
		}

		return $rows;
	}

	/**
	 * Strip a persisted Case Summary block, returning only conversation notes.
	 *
	 * @param string $stored Stored conversation summary.
	 * @return string
	 */
	public function extract_conversation_notes( string $stored ): string {
		$stored = trim( $stored );

		if ( '' === $stored ) {
			return '';
		}

		$header = __( 'Case Summary', 'prose-core' );

		if ( ! str_starts_with( $stored, $header ) ) {
			return $stored;
		}

		$notes_marker = __( 'Conversation notes:', 'prose-core' );
		$pos          = strpos( $stored, $notes_marker );

		if ( false !== $pos ) {
			return trim( substr( $stored, $pos + strlen( $notes_marker ) ) );
		}

		return '';
	}

	/**
	 * Compose a transient AI prompt block. Not for persistence.
	 *
	 * @param string               $fact_summary Prior conversation / fact summary.
	 * @param array<string, mixed> $case_summary Structured case summary.
	 * @return string
	 */
	public function merge_prompt_summary( string $fact_summary, array $case_summary ): string {
		$state_text = $this->to_prompt_text( $case_summary );

		if ( '' === $state_text ) {
			return trim( $fact_summary );
		}

		$fact_summary = trim( $fact_summary );

		if ( '' === $fact_summary ) {
			return __( 'Case Summary', 'prose-core' ) . "\n" . $state_text;
		}

		return __( 'Case Summary', 'prose-core' ) . "\n" . $state_text . "\n\n" . __( 'Conversation notes:', 'prose-core' ) . ' ' . $fact_summary;
	}

	/**
	 * @param array<int, array<string, mixed>> $forms Stage forms.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_forms( array $forms ): array {
		$out = array();

		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$out[] = array(
				'code'     => $code,
				'title'    => trim( (string) ( $form['title'] ?? $code ) ),
				'required' => ! empty( $form['required'] ),
			);
		}

		return $out;
	}

	/**
	 * Completed stage labels inferred from procedural node (stable across chat turns).
	 *
	 * @param string               $workflow        Workflow key.
	 * @param string               $procedural_node Current node.
	 * @param array<string, mixed> $facts           Plain facts.
	 * @return string[]
	 */
	private function completed_stage_labels_from_node( string $workflow, string $procedural_node, array $facts ): array {
		$workflow        = trim( $workflow );
		$procedural_node = trim( $procedural_node );

		if ( '' === $workflow || '' === $procedural_node ) {
			return array();
		}

		$current_stage = $this->progression->get_current_stage( $workflow, $procedural_node, $facts );

		if ( null === $current_stage || '' === $current_stage ) {
			return array();
		}

		$labels = array();

		foreach ( $this->progression->get_stages( $workflow, $facts ) as $stage ) {
			if ( $stage === $current_stage ) {
				break;
			}

			$guidance = $this->guidance->read_stage( (string) $stage );
			$label    = trim( (string) ( $guidance['title'] ?? '' ) );

			if ( '' === $label ) {
				$label = ucwords( str_replace( '_', ' ', (string) $stage ) );
			}

			$labels[] = $label;
		}

		return $labels;
	}

	/**
	 * @param array<string, mixed> $roadmap Roadmap payload.
	 * @return string[]
	 */
	private function completed_stage_labels( array $roadmap ): array {
		$steps  = is_array( $roadmap['completed_steps'] ?? null ) ? $roadmap['completed_steps'] : array();
		$labels = array();

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$label = trim( (string) ( $step['title'] ?? $step['id'] ?? '' ) );

			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		return $labels;
	}

	/**
	 * @param array<string, mixed> $facts Plain facts.
	 * @return string[]
	 */
	private function key_facts( array $facts ): array {
		$parts = array();

		if ( isset( $facts['spouse_agrees'] ) ) {
			$parts[] = ! empty( $facts['spouse_agrees'] )
				? __( 'spouse agrees to divorce', 'prose-core' )
				: __( 'divorce may be contested', 'prose-core' );
		}

		if ( ! empty( $facts['marital_property_resolved'] ) ) {
			$parts[] = __( 'settlement/property terms agreed', 'prose-core' );
		}

		if ( ! empty( $facts['active_divorce'] ) ) {
			$parts[] = __( 'divorce case already commenced', 'prose-core' );
		}

		foreach ( array( 'child_count', 'children_count' ) as $key ) {
			if ( isset( $facts[ $key ] ) && is_numeric( $facts[ $key ] ) ) {
				/* translators: %d: number of children. */
				$parts[] = sprintf( __( '%d child(ren) under 21', 'prose-core' ), (int) $facts[ $key ] );
				break;
			}
		}

		if ( empty( $parts ) && ! empty( $facts['has_minor_children'] ) ) {
			$parts[] = __( 'minor children involved', 'prose-core' );
		}

		$county = trim( (string) ( $facts['county'] ?? '' ) );

		if ( '' !== $county ) {
			/* translators: %s: county name. */
			$parts[] = sprintf( __( 'filing county: %s', 'prose-core' ), $county );
		}

		return $parts;
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

		if ( is_array( $definition ) && ! empty( $definition['description'] ) ) {
			return (string) $definition['description'];
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $workflow ) );
	}
}
