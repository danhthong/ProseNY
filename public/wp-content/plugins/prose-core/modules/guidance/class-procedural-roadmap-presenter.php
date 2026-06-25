<?php
/**
 * Procedural Roadmap Presenter — deterministic roadmap for workspace and dashboard.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

use ProSe\Core\Procedural\Guidance_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Procedural_Roadmap_Presenter
 */
final class Procedural_Roadmap_Presenter {

	/**
	 * Routing-critical fact keys for fingerprinting.
	 *
	 * @var array<int, string>
	 */
	private const ROUTING_FACT_KEYS = array(
		'issue',
		'county',
		'spouse_agrees',
		'has_minor_children',
		'child_count',
		'marital_property_resolved',
		'active_divorce',
		'spouse_responded',
		'protection_needed',
	);

	/**
	 * Guidance repository.
	 *
	 * @var Guidance_Repository
	 */
	private Guidance_Repository $repository;

	/**
	 * Step resolver.
	 *
	 * @var Step_Resolver
	 */
	private Step_Resolver $step_resolver;

	/**
	 * Guidance resolver.
	 *
	 * @var Guidance_Resolver
	 */
	private Guidance_Resolver $guidance;

	/**
	 * Constructor.
	 *
	 * @param Guidance_Repository|null $repository    Repository.
	 * @param Step_Resolver|null       $step_resolver Step resolver.
	 * @param Guidance_Resolver|null   $guidance      Guidance resolver.
	 */
	public function __construct(
		?Guidance_Repository $repository = null,
		?Step_Resolver $step_resolver = null,
		?Guidance_Resolver $guidance = null
	) {
		$this->repository    = $repository ?? new Guidance_Repository();
		$this->step_resolver   = $step_resolver ?? new Step_Resolver();
		$this->guidance        = $guidance ?? new Guidance_Resolver();
	}

	/**
	 * Build a full roadmap payload for the workspace.
	 *
	 * @param array<string, mixed> $input Presentation input.
	 * @return array<string, mixed>
	 */
	public function present( array $input ): array {
		$facts      = is_array( $input['facts'] ?? null ) ? $input['facts'] : array();
		$issue      = sanitize_key( (string) ( $input['issue'] ?? $facts['issue'] ?? '' ) );
		$workflow   = trim( (string) ( $input['workflow'] ?? '' ) );
		$completion = (int) ( $input['completion'] ?? 0 );
		$missing    = is_array( $input['missing_fields'] ?? null ) ? $input['missing_fields'] : array();
		$stage_ctx  = is_array( $input['stage_context'] ?? null ) ? $input['stage_context'] : array();
		$navigator  = is_array( $input['procedural_navigator'] ?? null ) ? $input['procedural_navigator'] : array();
		$resolved   = ! empty( $input['workflow_resolved'] );
		$intake_ok  = ! empty( $input['intake_complete'] );
		$lifecycle  = is_array( $input['lifecycle'] ?? null ) ? $input['lifecycle'] : array();

		if ( '' === $issue && '' === $workflow ) {
			return $this->empty_roadmap();
		}

		$mode   = ( '' !== $workflow && $resolved ) ? 'workflow' : 'intake';
		$roadmap = 'workflow' === $mode
			? $this->build_workflow_roadmap( $workflow, $facts, $stage_ctx, $navigator, $missing, $completion, $resolved, $intake_ok, $lifecycle )
			: $this->build_intake_roadmap( $issue, $facts, $missing, $completion, $resolved, $intake_ok );

		$roadmap['fingerprint'] = $this->compute_fingerprint( $input, $roadmap );

		return $roadmap;
	}

	/**
	 * Compare stored fingerprint with a freshly built roadmap.
	 *
	 * @param string               $stored_fingerprint Previously stored fingerprint.
	 * @param array<string, mixed> $input              Presentation input.
	 * @return array{fingerprint: string, changed: bool, roadmap: array<string, mixed>}
	 */
	public function resolve_with_change_detection( string $stored_fingerprint, array $input ): array {
		$roadmap     = $this->present( $input );
		$fingerprint = (string) ( $roadmap['fingerprint'] ?? '' );

		return array(
			'fingerprint' => $fingerprint,
			'changed'     => '' === $stored_fingerprint || $stored_fingerprint !== $fingerprint,
			'roadmap'     => $roadmap,
		);
	}

	/**
	 * Dashboard summary — subset of full roadmap (Option B).
	 *
	 * @param array<string, mixed> $roadmap         Full roadmap.
	 * @param string               $continue_case_url Resume URL.
	 * @return array<string, mixed>
	 */
	public function to_summary( array $roadmap, string $continue_case_url = '' ): array {
		if ( empty( $roadmap['show'] ) ) {
			return array( 'show' => false );
		}

		$current_stage = is_array( $roadmap['current_stage'] ?? null ) ? $roadmap['current_stage'] : array();
		$confidence    = is_array( $roadmap['confidence_level'] ?? null ) ? $roadmap['confidence_level'] : array();
		$next_likely   = is_array( $roadmap['next_likely_step'] ?? null ) ? $roadmap['next_likely_step'] : array();

		return array(
			'show'                         => true,
			'current_stage'                => (string) ( $current_stage['label'] ?? $current_stage['title'] ?? '' ),
			'progress_percentage'          => (int) ( $roadmap['progress_percentage'] ?? 0 ),
			'confidence_level'             => array(
				'label'  => (string) ( $confidence['label'] ?? '' ),
				'reason' => (string) ( $confidence['reason'] ?? '' ),
			),
			'next_likely_step'             => array(
				'title'       => (string) ( $next_likely['title'] ?? '' ),
				'description' => (string) ( $next_likely['description'] ?? '' ),
			),
			'suggested_follow_up_question' => (string) ( $roadmap['suggested_next_question'] ?? '' ),
			'continue_case_url'            => $continue_case_url,
			'lifecycle_stage'              => (string) ( $roadmap['lifecycle_stage'] ?? '' ),
			'answer_deadline'              => is_array( $roadmap['answer_deadline'] ?? null ) ? $roadmap['answer_deadline'] : null,
		);
	}

	/**
	 * @param array<string, mixed> $input   Input.
	 * @param array<string, mixed> $roadmap Built roadmap.
	 * @return string
	 */
	public function compute_fingerprint( array $input, array $roadmap ): string {
		$facts       = is_array( $input['facts'] ?? null ) ? $input['facts'] : array();
		$stage_ctx   = is_array( $input['stage_context'] ?? null ) ? $input['stage_context'] : array();
		$lifecycle   = is_array( $input['lifecycle'] ?? null ) ? $input['lifecycle'] : array();
		$current     = is_array( $stage_ctx['current_stage'] ?? null ) ? $stage_ctx['current_stage'] : array();
		$form_codes  = $this->visible_form_codes( $stage_ctx, $roadmap );

		$payload = array(
			'stage_id'        => (string) ( $current['id'] ?? $roadmap['current_stage']['id'] ?? '' ),
			'procedural_node' => (string) ( $input['procedural_node'] ?? '' ),
			'routing_facts'   => $this->routing_fact_slice( $facts ),
			'form_codes'      => $form_codes,
			'eligibility'     => array(
				'workflow'             => (string) ( $input['workflow'] ?? '' ),
				'workflow_resolved'    => ! empty( $input['workflow_resolved'] ),
				'intake_complete'      => ! empty( $input['intake_complete'] ),
				'forms_visible'        => ! empty( $stage_ctx['forms_visible'] ),
				'routing_status'       => (string) ( $input['routing_status'] ?? '' ),
				'candidate_workflows'  => array_values( (array) ( $input['candidate_workflows'] ?? array() ) ),
				'lifecycle_stage'      => (string) ( $lifecycle['stage'] ?? '' ),
				'lifecycle_branch'     => (string) ( $lifecycle['branch'] ?? '' ),
			),
		);

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function empty_roadmap(): array {
		return array(
			'show'                    => false,
			'mode'                    => 'intake',
			'current_stage'           => array( 'id' => '', 'title' => '', 'label' => '' ),
			'completed_steps'         => array(),
			'current_focus'           => array(),
			'upcoming_steps'          => array(),
			'confidence_level'        => array( 'label' => '', 'score' => 0.0, 'reason' => '' ),
			'next_likely_step'        => array( 'id' => '', 'title' => '', 'description' => '' ),
			'suggested_next_question' => '',
			'required_forms'          => array(),
			'procedural_guidance'     => null,
			'disclaimer'              => $this->disclaimer(),
			'progress_percentage'     => 0,
			'fingerprint'             => '',
		);
	}

	/**
	 * @param string               $issue      Issue slug.
	 * @param array<string, mixed> $facts      Facts.
	 * @param array<int, mixed>    $missing    Missing fields.
	 * @param int                  $completion Completion percent.
	 * @param bool                 $resolved   Workflow resolved.
	 * @param bool                 $intake_ok  Intake complete.
	 * @return array<string, mixed>
	 */
	private function build_intake_roadmap(
		string $issue,
		array $facts,
		array $missing,
		int $completion,
		bool $resolved,
		bool $intake_ok
	): array {
		$seed = $this->repository->read_intake_roadmap( $issue );

		if ( null === $seed ) {
			$seed = $this->repository->read_intake_roadmap( 'divorce' );
		}

		if ( null === $seed ) {
			return $this->empty_roadmap();
		}

		$steps_def     = is_array( $seed['steps'] ?? null ) ? $seed['steps'] : array();
		$completed     = array();
		$upcoming      = array();
		$current_focus = array();
		$found_current = false;

		foreach ( $steps_def as $step_def ) {
			if ( ! is_array( $step_def ) ) {
				continue;
			}

			$item = $this->intake_step_item( $step_def, $facts, $missing );

			if ( $item['completed'] ) {
				$completed[] = array(
					'id'          => $item['id'],
					'title'       => $item['title'],
					'description' => $item['description'],
					'relevance'   => $item['relevance'],
				);
				continue;
			}

			if ( ! $found_current ) {
				$current_focus = array(
					'id'          => $item['id'],
					'title'       => $item['title'],
					'description' => $item['description'],
					'relevance'   => $item['relevance'],
					'forms'       => array(),
				);
				$found_current = true;
				continue;
			}

			$upcoming[] = array(
				'id'          => $item['id'],
				'title'       => $item['title'],
				'description' => $item['description'],
				'relevance'   => $item['relevance'],
				'needs_info'  => $item['needs_info'],
				'forms'       => array(),
			);
		}

		if ( empty( $current_focus ) && ! empty( $completed ) ) {
			$last          = $completed[ count( $completed ) - 1 ];
			$current_focus = array(
				'id'          => (string) ( $last['id'] ?? '' ),
				'title'       => (string) ( $last['title'] ?? '' ),
				'description' => (string) ( $last['description'] ?? '' ),
				'relevance'   => (string) ( $last['relevance'] ?? '' ),
				'forms'       => array(),
			);
		}

		$confidence = $this->derive_confidence( $completion, $resolved, $intake_ok, true );
		$question   = $this->suggested_question( $missing, $seed );

		return array(
			'show'                    => true,
			'mode'                    => 'intake',
			'current_stage'           => array(
				'id'    => 'intake_' . $issue,
				'title' => (string) ( $seed['title'] ?? ucfirst( $issue ) . ' Intake' ),
				'label' => (string) ( $seed['current_stage_label'] ?? ( $seed['title'] ?? 'Initial Intake' ) ),
			),
			'completed_steps'         => $completed,
			'current_focus'           => $current_focus,
			'upcoming_steps'          => $upcoming,
			'confidence_level'        => $confidence,
			'next_likely_step'        => $this->next_likely_step( $current_focus, $upcoming ),
			'suggested_next_question' => $question,
			'required_forms'          => array(),
			'procedural_guidance'     => null,
			'disclaimer'              => $this->disclaimer(),
			'progress_percentage'     => $completion,
		);
	}

	/**
	 * @param string               $workflow  Workflow key.
	 * @param array<string, mixed> $facts     Facts.
	 * @param array<string, mixed> $stage_ctx Stage context.
	 * @param array<string, mixed> $navigator Navigator context.
	 * @param array<int, mixed>    $missing   Missing fields.
	 * @param int                  $completion Completion.
	 * @param bool                 $resolved  Workflow resolved.
	 * @param bool                 $intake_ok Intake complete.
	 * @param array<string, mixed> $lifecycle Lifecycle payload.
	 * @return array<string, mixed>
	 */
	private function build_workflow_roadmap(
		string $workflow,
		array $facts,
		array $stage_ctx,
		array $navigator,
		array $missing,
		int $completion,
		bool $resolved,
		bool $intake_ok,
		array $lifecycle = array()
	): array {
		$next_steps   = is_array( $navigator['next_steps'] ?? null ) ? $navigator['next_steps'] : array();
		$forms_visible = ! empty( $stage_ctx['forms_visible'] );
		$current_id   = '';

		foreach ( $next_steps as $step ) {
			if ( ! empty( $step['current'] ) ) {
				$current_id = (string) ( $step['id'] ?? '' );
				break;
			}
		}

		if ( '' === $current_id ) {
			$current_stage = is_array( $stage_ctx['current_stage'] ?? null ) ? $stage_ctx['current_stage'] : array();
			$current_id    = (string) ( $current_stage['id'] ?? '' );
		}

		$guidance_steps = $this->step_resolver->resolve( $workflow );
		$enriched       = array();

		foreach ( (array) ( $guidance_steps['steps'] ?? array() ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$id = (string) ( $step['id'] ?? $step['stage'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			$enriched[ $id ] = $step;
		}

		$completed     = array();
		$upcoming      = array();
		$current_focus = array();
		$passed_current = false;

		foreach ( $next_steps as $nav_step ) {
			if ( ! is_array( $nav_step ) ) {
				continue;
			}

			$id       = (string) ( $nav_step['id'] ?? '' );
			$is_curr  = ( $id === $current_id ) || ! empty( $nav_step['current'] );
			$meta     = $enriched[ $id ] ?? array();
			$stage    = $this->repository->read_stage( $id );
			$title    = (string) ( $nav_step['title'] ?? $meta['title'] ?? $stage['title'] ?? $this->humanize( $id ) );
			$desc     = (string) ( $meta['description'] ?? $stage['description'] ?? '' );
			$relevance = (string) ( $meta['summary'] ?? $desc );
			$forms    = $forms_visible && $is_curr ? $this->normalize_forms( (array) ( $nav_step['forms'] ?? array() ) ) : array();

			$item = array(
				'id'          => $id,
				'title'       => $title,
				'description' => $desc,
				'relevance'   => $relevance,
				'needs_info'  => false,
				'forms'       => $forms,
			);

			if ( $is_curr ) {
				$current_focus  = $item;
				$passed_current = true;
				continue;
			}

			if ( ! $passed_current && '' !== $current_id ) {
				$completed[] = array(
					'id'          => $item['id'],
					'title'       => $item['title'],
					'description' => $item['description'],
					'relevance'   => $item['relevance'],
				);
				continue;
			}

			$upcoming[] = $item;
		}

		if ( empty( $current_focus ) && ! empty( $next_steps[0] ) && is_array( $next_steps[0] ) ) {
			$first = $next_steps[0];
			$current_focus = array(
				'id'          => (string) ( $first['id'] ?? '' ),
				'title'       => (string) ( $first['title'] ?? '' ),
				'description' => '',
				'relevance'   => '',
				'forms'       => $forms_visible ? $this->normalize_forms( (array) ( $first['forms'] ?? array() ) ) : array(),
			);
		}

		$stage_guidance = null;

		if ( '' !== $current_id ) {
			$stage_data = $this->repository->read_stage( $current_id );

			if ( is_array( $stage_data ) ) {
				$stage_guidance = (string) ( $stage_data['description'] ?? '' );
				$tips           = is_array( $stage_data['tips'] ?? null ) ? $stage_data['tips'] : array();

				if ( ! empty( $tips[0] ) ) {
					$stage_guidance = trim( $stage_guidance . ' ' . (string) $tips[0] );
				}
			}
		}

		$required_forms = $forms_visible
			? $this->normalize_forms( (array) ( $stage_ctx['stage_forms'] ?? $current_focus['forms'] ?? array() ) )
			: array();

		$confidence = $this->derive_confidence( $completion, $resolved, $intake_ok, false );
		$question   = $this->suggested_question(
			$missing,
			array(),
			(string) ( $stage_ctx['next_action']['message'] ?? '' )
		);

		$current_stage = is_array( $stage_ctx['current_stage'] ?? null ) ? $stage_ctx['current_stage'] : array();

		$roadmap = array(
			'show'                    => true,
			'mode'                    => 'workflow',
			'current_stage'           => array(
				'id'    => (string) ( $current_stage['id'] ?? $current_id ),
				'title' => (string) ( $current_stage['title'] ?? $current_focus['title'] ?? '' ),
				'label' => (string) ( $current_stage['title'] ?? $current_focus['title'] ?? $this->humanize( $current_id ) ),
			),
			'completed_steps'         => $completed,
			'current_focus'           => $current_focus,
			'upcoming_steps'          => $upcoming,
			'confidence_level'        => $confidence,
			'next_likely_step'        => $this->next_likely_step( $current_focus, $upcoming ),
			'suggested_next_question' => $question,
			'required_forms'          => $required_forms,
			'procedural_guidance'     => '' !== $stage_guidance ? $stage_guidance : null,
			'disclaimer'              => $this->disclaimer(),
			'progress_percentage'     => $completion,
		);

		return $this->merge_lifecycle_roadmap( $roadmap, $lifecycle, $intake_ok );
	}

	/**
	 * Overlay post-intake lifecycle milestones on workflow roadmap.
	 *
	 * @param array<string, mixed> $roadmap   Base roadmap.
	 * @param array<string, mixed> $lifecycle Lifecycle payload.
	 * @param bool                 $intake_ok Intake complete.
	 * @return array<string, mixed>
	 */
	private function merge_lifecycle_roadmap( array $roadmap, array $lifecycle, bool $intake_ok ): array {
		if ( empty( $lifecycle['show'] ) || ! $intake_ok ) {
			return $roadmap;
		}

		$stage       = (string) ( $lifecycle['stage'] ?? '' );
		$milestones  = is_array( $lifecycle['milestones'] ?? null ) ? $lifecycle['milestones'] : array();
		$deadlines   = is_array( $lifecycle['deadlines'] ?? null ) ? $lifecycle['deadlines'] : array();
		$next_actions = is_array( $lifecycle['next_actions'] ?? null ) ? $lifecycle['next_actions'] : array();

		if ( '' === $stage || empty( $milestones ) ) {
			return $roadmap;
		}

		$completed = array();
		$upcoming  = array();
		$focus     = array();
		$label     = '';

		foreach ( $milestones as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$row = array(
				'id'          => (string) ( $item['id'] ?? '' ),
				'title'       => (string) ( $item['label'] ?? '' ),
				'description' => '',
				'relevance'   => '',
			);

			$status = (string) ( $item['status'] ?? '' );

			if ( 'completed' === $status ) {
				$completed[] = $row;
			} elseif ( 'current' === $status ) {
				$focus = $row;
				$label = $row['title'];
			} else {
				$upcoming[] = $row;
			}
		}

		if ( empty( $focus ) && ! empty( $upcoming[0] ) ) {
			$focus = $upcoming[0];
			$label = (string) ( $focus['title'] ?? '' );
			array_shift( $upcoming );
		}

		$guidance = $roadmap['procedural_guidance'];

		if ( ! empty( $deadlines[0] ) && is_array( $deadlines[0] ) ) {
			$guidance = trim(
				(string) $guidance . ' ' . (string) ( $deadlines[0]['description'] ?? '' )
			);
		}

		$question = $roadmap['suggested_next_question'];

		if ( '' === $question && ! empty( $next_actions[0]['label'] ) ) {
			$question = (string) $next_actions[0]['label'];
		}

		$roadmap['lifecycle_stage']  = $stage;
		$roadmap['completed_steps']  = $completed;
		$roadmap['current_focus']    = $focus;
		$roadmap['upcoming_steps']   = $upcoming;
		$roadmap['next_likely_step'] = $this->next_likely_step( $focus, $upcoming );
		$roadmap['procedural_guidance'] = '' !== trim( (string) $guidance ) ? trim( (string) $guidance ) : null;
		$roadmap['suggested_next_question'] = $question;
		$roadmap['current_stage']    = array(
			'id'    => $stage,
			'title' => $label,
			'label' => $label,
		);
		$roadmap['answer_deadline']  = ! empty( $deadlines[0] ) && is_array( $deadlines[0] )
			? array(
				'label'    => (string) ( $deadlines[0]['label'] ?? '' ),
				'due_date' => (string) ( $deadlines[0]['due_date'] ?? '' ),
			)
			: null;

		return $roadmap;
	}

	/**
	 * @param array<string, mixed> $step_def Step definition.
	 * @param array<string, mixed> $facts    Facts.
	 * @param array<int, mixed>    $missing  Missing fields.
	 * @return array<string, mixed>
	 */
	private function intake_step_item( array $step_def, array $facts, array $missing ): array {
		$fact_keys = is_array( $step_def['fact_keys'] ?? null ) ? $step_def['fact_keys'] : array();
		$completed = true;
		$needs_info = false;

		if ( ! empty( $fact_keys ) ) {
			foreach ( $fact_keys as $key ) {
				$key = (string) $key;

				if ( '' === $key ) {
					continue;
				}

				if ( ! array_key_exists( $key, $facts ) || '' === (string) $facts[ $key ] ) {
					$completed  = false;
					$needs_info = true;
					break;
				}
			}
		} else {
			$completed  = false;
			$needs_info = true;
		}

		return array(
			'id'          => (string) ( $step_def['id'] ?? '' ),
			'title'       => (string) ( $step_def['title'] ?? '' ),
			'description' => (string) ( $step_def['description'] ?? '' ),
			'relevance'   => (string) ( $step_def['relevance'] ?? '' ),
			'completed'   => $completed,
			'needs_info'  => $needs_info,
		);
	}

	/**
	 * @param array<int, mixed>    $missing Missing fields.
	 * @param array<string, mixed> $seed    Intake seed.
	 * @param string               $fallback Fallback question.
	 * @return string
	 */
	private function suggested_question( array $missing, array $seed = array(), string $fallback = '' ): string {
		foreach ( $missing as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$question = trim( (string) ( $field['question'] ?? '' ) );

			if ( '' !== $question ) {
				return $question;
			}
		}

		if ( '' !== $fallback ) {
			return $fallback;
		}

		return __( 'Could you share a bit more about your situation so I can help narrow down the process?', 'prose-core' );
	}

	/**
	 * @param int  $completion Completion percent.
	 * @param bool $resolved   Workflow resolved.
	 * @param bool $intake_ok  Intake complete.
	 * @param bool $intake_mode Intake mode.
	 * @return array{label: string, score: float, reason: string}
	 */
	private function derive_confidence( int $completion, bool $resolved, bool $intake_ok, bool $intake_mode ): array {
		if ( $resolved && $intake_ok ) {
			return array(
				'label'  => __( 'High', 'prose-core' ),
				'score'  => 0.9,
				'reason' => __( 'Workflow identified and intake information is largely complete.', 'prose-core' ),
			);
		}

		if ( $resolved ) {
			return array(
				'label'  => __( 'Moderate', 'prose-core' ),
				'score'  => 0.7,
				'reason' => __( 'A likely workflow path is identified; some details may still be helpful.', 'prose-core' ),
			);
		}

		if ( $intake_mode && $completion >= 40 ) {
			return array(
				'label'  => __( 'Preliminary', 'prose-core' ),
				'score'  => 0.5,
				'reason' => __( 'Early intake information is available; the full path is not yet confirmed.', 'prose-core' ),
			);
		}

		return array(
			'label'  => __( 'Preliminary', 'prose-core' ),
			'score'  => 0.35,
			'reason' => __( 'Limited information so far; guidance may change as you share more.', 'prose-core' ),
		);
	}

	/**
	 * @param array<string, mixed> $current_focus Current focus.
	 * @param array<int, mixed>    $upcoming      Upcoming steps.
	 * @return array{id: string, title: string, description: string}
	 */
	private function next_likely_step( array $current_focus, array $upcoming ): array {
		if ( ! empty( $upcoming[0] ) && is_array( $upcoming[0] ) ) {
			return array(
				'id'          => (string) ( $upcoming[0]['id'] ?? '' ),
				'title'       => (string) ( $upcoming[0]['title'] ?? '' ),
				'description' => (string) ( $upcoming[0]['description'] ?? '' ),
			);
		}

		return array(
			'id'          => (string) ( $current_focus['id'] ?? '' ),
			'title'       => (string) ( $current_focus['title'] ?? '' ),
			'description' => (string) ( $current_focus['description'] ?? '' ),
		);
	}

	/**
	 * @param array<int, mixed> $forms Raw forms.
	 * @return array<int, array{code: string, title: string, required: bool}>
	 */
	private function normalize_forms( array $forms ): array {
		$normalized = array();

		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) ) {
				continue;
			}

			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' === $code ) {
				continue;
			}

			$normalized[] = array(
				'code'     => $code,
				'title'    => (string) ( $form['title'] ?? $code ),
				'required' => ! empty( $form['required'] ),
			);
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $stage_ctx Stage context.
	 * @param array<string, mixed> $roadmap   Roadmap.
	 * @return array<int, string>
	 */
	private function visible_form_codes( array $stage_ctx, array $roadmap ): array {
		$forms = array();

		if ( ! empty( $stage_ctx['forms_visible'] ) ) {
			foreach ( (array) ( $stage_ctx['stage_forms'] ?? array() ) as $form ) {
				if ( is_array( $form ) && ! empty( $form['code'] ) ) {
					$forms[] = (string) $form['code'];
				}
			}
		}

		foreach ( (array) ( $roadmap['required_forms'] ?? array() ) as $form ) {
			if ( is_array( $form ) && ! empty( $form['code'] ) ) {
				$forms[] = (string) $form['code'];
			}
		}

		$forms = array_values( array_unique( $forms ) );
		sort( $forms );

		return $forms;
	}

	/**
	 * @param array<string, mixed> $facts Facts.
	 * @return array<string, mixed>
	 */
	private function routing_fact_slice( array $facts ): array {
		$slice = array();

		foreach ( self::ROUTING_FACT_KEYS as $key ) {
			if ( array_key_exists( $key, $facts ) ) {
				$slice[ $key ] = $facts[ $key ];
			}
		}

		return $slice;
	}

	/**
	 * @return string
	 */
	private function disclaimer(): string {
		return __( 'These are examples of steps commonly encountered in similar situations. Your circumstances may be different, and you are not required to follow this sequence.', 'prose-core' );
	}

	/**
	 * @param string $slug Slug.
	 * @return string
	 */
	private function humanize( string $slug ): string {
		$words = explode( '_', $slug );
		$words = array_map(
			static function ( string $word ): string {
				return ucfirst( strtolower( $word ) );
			},
			$words
		);

		return implode( ' ', $words );
	}
}
