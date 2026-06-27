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
	 * Constructor.
	 *
	 * @param Stage_Form_Presenter|null         $stage_presenter   Stage presenter.
	 * @param Case_Actions_Resolver|null        $actions             Actions resolver.
	 * @param Case_Lifecycle_Service|null       $lifecycle           Lifecycle service.
	 * @param Procedural_Roadmap_Presenter|null $roadmap_presenter   Roadmap presenter.
	 * @param Conversation_Persistence|null     $persistence         Conversation persistence.
	 */
	public function __construct(
		?Stage_Form_Presenter $stage_presenter = null,
		?Case_Actions_Resolver $actions = null,
		?Case_Lifecycle_Service $lifecycle = null,
		?Procedural_Roadmap_Presenter $roadmap_presenter = null,
		?Conversation_Persistence $persistence = null
	) {
		$this->stage_presenter   = $stage_presenter ?? new Stage_Form_Presenter();
		$this->actions           = $actions ?? new Case_Actions_Resolver();
		$this->lifecycle         = $lifecycle ?? new Case_Lifecycle_Service();
		$this->roadmap_presenter = $roadmap_presenter ?? new Procedural_Roadmap_Presenter();
		$this->persistence       = $persistence ?? new Conversation_Persistence();
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
		$stage_context    = $this->stage_presenter->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'intake_complete'   => true,
				'issue'           => (string) ( $case_profile['issue'] ?? $facts['issue'] ?? 'divorce' ),
				'current_node'    => $procedural_node,
			)
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
				'advanced'       => false,
				'case_profile'   => $case_profile,
				'stage_context'  => $stage_context,
				'actions'        => $this->actions->resolve( $case_profile ),
				'completed_stage' => $current_stage,
			);
		}

		$case_profile['procedural_node'] = $advanced_node;
		$case_profile                    = $this->record_lifecycle_for_stage( $case_profile, $current_stage, $advanced_node );

		$next_context = $this->stage_presenter->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'intake_complete'   => true,
				'issue'           => (string) ( $case_profile['issue'] ?? $facts['issue'] ?? 'divorce' ),
				'current_node'    => $advanced_node,
			)
		);

		$case_profile = $this->refresh_roadmap( $case_profile, $next_context );
		$actions      = $this->actions->resolve( $case_profile );

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
		);
	}

	/**
	 * @param array<string, mixed> $case_profile   Case profile.
	 * @param string               $completed_stage Completed stage slug.
	 * @param string               $advanced_node   Advanced procedural node.
	 * @return array<string, mixed>
	 */
	private function record_lifecycle_for_stage( array $case_profile, string $completed_stage, string $advanced_node ): array {
		$to_add = array( Case_Lifecycle_Service::EVENT_FORMS_GENERATED );

		if ( 'commencement' === $completed_stage || Vocabulary::NODE_1002_SERVICE_COMPLETE === $advanced_node ) {
			$to_add[] = Case_Lifecycle_Service::EVENT_FILED;
		}

		$case_profile = $this->lifecycle->append_events_if_missing(
			$case_profile,
			$to_add,
			array(
				'stage'           => $completed_stage,
				'procedural_node' => $advanced_node,
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

		return $case_profile;
	}
}
