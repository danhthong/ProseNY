<?php
/**
 * Procedural stage completion — advance node after form download.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Guidance\Procedural_Roadmap_Presenter;
use ProSe\Core\Routing\Workflow_Catalog;
use ProSe\Core\Routing\Workflow_Engine;
use ProSe\Core\Users\Conversation_Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Procedural_Stage_Completer
 */
final class Procedural_Stage_Completer {

	/**
	 * @var Stage_Form_Presenter
	 */
	private Stage_Form_Presenter $stage_presenter;

	/**
	 * @var Case_Actions_Resolver
	 */
	private Case_Actions_Resolver $actions;

	/**
	 * @var Case_Lifecycle_Service
	 */
	private Case_Lifecycle_Service $lifecycle;

	/**
	 * @var Procedural_Roadmap_Presenter
	 */
	private Procedural_Roadmap_Presenter $roadmap_presenter;

	/**
	 * @var Conversation_Persistence
	 */
	private Conversation_Persistence $persistence;

	/**
	 * @var Completed_Stage_Document_Store
	 */
	private Completed_Stage_Document_Store $completed_documents;

	/**
	 * @var Workflow_Engine
	 */
	private Workflow_Engine $workflow_engine;

	/**
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Constructor.
	 *
	 * @param Stage_Form_Presenter|null         $stage_presenter   Stage presenter.
	 * @param Case_Actions_Resolver|null        $actions             Actions resolver.
	 * @param Case_Lifecycle_Service|null       $lifecycle           Lifecycle service.
	 * @param Procedural_Roadmap_Presenter|null $roadmap_presenter   Roadmap presenter.
	 * @param Conversation_Persistence|null     $persistence         Conversation persistence.
	 * @param Completed_Stage_Document_Store|null $completed_documents Completed document store.
	 * @param Workflow_Engine|null            $workflow_engine     Workflow engine.
	 * @param Workflow_Catalog|null           $workflows           Workflow catalog.
	 */
	public function __construct(
		?Stage_Form_Presenter $stage_presenter = null,
		?Case_Actions_Resolver $actions = null,
		?Case_Lifecycle_Service $lifecycle = null,
		?Procedural_Roadmap_Presenter $roadmap_presenter = null,
		?Conversation_Persistence $persistence = null,
		?Completed_Stage_Document_Store $completed_documents = null,
		?Workflow_Engine $workflow_engine = null,
		?Workflow_Catalog $workflows = null
	) {
		$this->stage_presenter       = $stage_presenter ?? new Stage_Form_Presenter();
		$this->actions               = $actions ?? new Case_Actions_Resolver();
		$this->lifecycle             = $lifecycle ?? new Case_Lifecycle_Service();
		$this->roadmap_presenter     = $roadmap_presenter ?? new Procedural_Roadmap_Presenter();
		$this->persistence           = $persistence ?? new Conversation_Persistence();
		$this->completed_documents   = $completed_documents ?? new Completed_Stage_Document_Store();
		$this->workflow_engine       = $workflow_engine ?? new Workflow_Engine();
		$this->workflows             = $workflows ?? new Workflow_Catalog();
	}

	/**
	 * Mark the current procedural stage complete and advance to the next step.
	 *
	 * @param array<string, mixed> $case_profile Case profile snapshot.
	 * @param string               $session_id     Optional conversation UUID for persistence.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function complete_current_stage( array $case_profile, string $session_id = '' ) {
		$workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return new \WP_Error(
				'prose_stage_no_workflow',
				__( 'A resolved workflow is required before advancing stages.', 'prose-core' ),
				array( 'status' => 422 )
			);
		}

		$facts            = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$procedural_node  = trim( (string) ( $case_profile['procedural_node'] ?? '' ) );
		$required_defs    = $this->required_field_defs( $workflow );
		$completed_stages = Completed_Stage_Document_Store::completed_stage_count( $case_profile );
		$workflow_state   = $this->workflow_engine->resolve_state( $workflow, $facts, $procedural_node, $required_defs, $completed_stages );
		$procedural_node  = (string) ( $workflow_state['procedural_node'] ?? $procedural_node );

		$stage_context    = $this->workflow_engine->determine_stage(
			$workflow,
			$facts,
			$procedural_node,
			true,
			$required_defs,
			$completed_stages
		);

		if ( empty( $stage_context['forms_visible'] ) ) {
			return new \WP_Error(
				'prose_stage_forms_gated',
				__( 'Complete intake before advancing procedural stages.', 'prose-core' ),
				array( 'status' => 422 )
			);
		}

		$current_stage = sanitize_key( (string) ( $stage_context['current_stage']['id'] ?? '' ) );
		$current_node  = trim( (string) ( $stage_context['procedural_node'] ?? $procedural_node ) );

		if ( '' === $current_stage ) {
			return new \WP_Error(
				'prose_stage_unknown',
				__( 'Could not determine the current procedural stage.', 'prose-core' ),
				array( 'status' => 422 )
			);
		}

		$advanced_node = $this->stage_presenter->advance_after_stage(
			$workflow,
			$current_node,
			$current_stage,
			$facts
		);

		if ( $advanced_node === $current_node ) {
			return array(
				'advanced'        => false,
				'case_profile'    => $case_profile,
				'stage_context'   => $stage_context,
				'actions'         => $this->actions->resolve( $case_profile ),
				'completed_stage' => $current_stage,
				'message'         => $this->stage_complete_message( $stage_context, $workflow, false ),
			);
		}

		$case_profile['procedural_node'] = $advanced_node;
		$case_profile                    = $this->completed_documents->record_stage_completion( $case_profile, $stage_context, $current_stage );
		$case_profile                    = $this->record_lifecycle_for_stage( $case_profile, $current_stage, $advanced_node );

		$next_context = $this->workflow_engine->determine_stage(
			$workflow,
			$facts,
			$advanced_node,
			true,
			$required_defs,
			Completed_Stage_Document_Store::completed_stage_count( $case_profile )
		);

		$case_profile['workflow_state']  = $this->workflow_engine->resolve_state(
			$workflow,
			$facts,
			$advanced_node,
			$required_defs,
			Completed_Stage_Document_Store::completed_stage_count( $case_profile )
		);
		$case_profile                    = $this->refresh_roadmap( $case_profile, $next_context );
		$case_profile                    = ( new Case_Stage_Integrity( $this->workflow_engine ) )->reconcile_case_profile( $case_profile, true );
		$actions                         = $this->actions->resolve( $case_profile );
		$next_context                    = is_array( $actions['stage_context'] ?? null ) ? $actions['stage_context'] : $next_context;

		if ( '' !== trim( $session_id ) && is_user_logged_in() ) {
			$this->persistence->update_session_context( $session_id, $case_profile, array(), $actions );
		}

		return array(
			'advanced'        => true,
			'case_profile'    => $case_profile,
			'stage_context'   => $next_context,
			'actions'         => $actions,
			'completed_stage' => $current_stage,
			'procedural_node' => $advanced_node,
			'message'         => $this->stage_complete_message( $next_context, $workflow, true, $current_stage ),
		);
	}

	/**
	 * User-facing message after confirming a procedural stage is complete.
	 *
	 * @param array<string, mixed> $stage_ctx       Current or next stage context.
	 * @param string               $workflow        Workflow key.
	 * @param bool                 $advanced        Whether the workflow moved forward.
	 * @param string               $completed_stage Completed stage slug.
	 * @return string
	 */
	private function stage_complete_message( array $stage_ctx, string $workflow, bool $advanced, string $completed_stage = '' ): string {
		if ( ! $advanced ) {
			$title = trim( (string) ( $stage_ctx['current_stage']['title'] ?? '' ) );

			if ( '' !== $title ) {
				/* translators: %s: current procedural stage title. */
				return sprintf(
					__( 'You are already at the latest available step (%s). There is nothing further to advance right now.', 'prose-core' ),
					$title
				);
			}

			return __( 'You are already at the latest available step. There is nothing further to advance right now.', 'prose-core' );
		}

		$next_title = trim( (string) ( $stage_ctx['current_stage']['title'] ?? '' ) );
		$next_step  = trim( (string) ( $stage_ctx['next_action']['message'] ?? '' ) );
		$parts      = array();

		if ( '' !== $completed_stage && '' !== $next_title ) {
			$completed_label = ucwords( str_replace( '_', ' ', $completed_stage ) );
			/* translators: 1: completed stage label, 2: next stage title. */
			$parts[] = sprintf(
				__( 'Thanks — I marked %1$s as complete. Your case is now at the %2$s stage.', 'prose-core' ),
				$completed_label,
				$next_title
			);
		} elseif ( '' !== $next_title ) {
			/* translators: %s: next procedural stage title. */
			$parts[] = sprintf( __( 'Thanks — your case is now at the %s stage.', 'prose-core' ), $next_title );
		}

		if ( '' !== $next_step ) {
			$parts[] = $next_step;
		}

		if ( ! empty( $stage_ctx['forms_visible'] ) ) {
			$parts[] = __( 'Use Get Documents in Case Actions when you are ready for the forms for this step.', 'prose-core' );
		}

		unset( $workflow );

		return implode( ' ', $parts );
	}

	/**
	 * @param array<string, mixed> $case_profile   Case profile.
	 * @param string               $completed_stage Completed stage slug.
	 * @param string               $advanced_node   Advanced procedural node.
	 * @return array<string, mixed>
	 */
	private function record_lifecycle_for_stage( array $case_profile, string $completed_stage, string $advanced_node ): array {
		$case_profile = $this->lifecycle->append_events_if_missing(
			$case_profile,
			array( Case_Lifecycle_Service::EVENT_USER_MARKED_COMPLETE ),
			array(
				'stage'           => $completed_stage,
				'procedural_node' => $advanced_node,
				'source'          => 'user',
			)
		);

		$built = $this->lifecycle->build( $case_profile, array( 'intake_complete' => true ) );
		$case_profile['lifecycle_stage']  = (string) ( $built['stage'] ?? Case_Lifecycle_Service::STAGE_INTAKE );
		$case_profile['lifecycle_branch'] = (string) ( $built['branch'] ?? '' );

		return $case_profile;
	}

	/**
	 * @param array<string, mixed> $case_profile Case profile.
	 * @param array<string, mixed> $stage_ctx    Stage context.
	 * @return array<string, mixed>
	 */
	private function refresh_roadmap( array $case_profile, array $stage_ctx ): array {
		$facts    = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		$roadmap = $this->roadmap_presenter->present(
			array(
				'issue'                => (string) ( $case_profile['issue'] ?? $facts['issue'] ?? 'divorce' ),
				'facts'                => $facts,
				'workflow'             => $workflow,
				'completion'           => (int) ( $case_profile['progress'] ?? 0 ),
				'missing_fields'       => array(),
				'stage_context'        => $stage_ctx,
				'procedural_navigator' => array(),
				'workflow_resolved'    => true,
				'intake_complete'      => true,
				'procedural_node'      => (string) ( $case_profile['procedural_node'] ?? '' ),
			)
		);

		$case_profile['roadmap']             = $roadmap;
		$case_profile['roadmap_fingerprint'] = (string) ( $roadmap['fingerprint'] ?? '' );
		$case_profile['progress']            = (int) ( $roadmap['progress_percentage'] ?? 0 );

		return $case_profile;
	}

	/**
	 * @param string $workflow Workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	private function required_field_defs( string $workflow ): array {
		if ( '' === $workflow ) {
			return array();
		}

		$definition = $this->workflows->by_key( $workflow );
		$fields     = is_array( $definition['required_fields'] ?? null ) ? $definition['required_fields'] : array();

		return $fields;
	}
}
