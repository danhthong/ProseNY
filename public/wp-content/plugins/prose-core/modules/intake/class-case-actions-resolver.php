<?php
/**
 * Case Actions Resolver — determines when document download actions are available.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Intake;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Stage_Form_Presenter;
use ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service;
use ProSe\Core\Procedural\Package_Resolver;
use ProSe\Core\Routing\Case_Profile;
use ProSe\Core\Routing\Court_Routing_Explainer;
use ProSe\Core\Routing\Routing_Engine;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Case_Actions_Resolver
 */
final class Case_Actions_Resolver {

	/**
	 * Package resolver.
	 *
	 * @var Package_Resolver
	 */
	private Package_Resolver $packages;

	/**
	 * Merged blank PDF service.
	 *
	 * @var Merged_Blank_Pdf_Service
	 */
	private Merged_Blank_Pdf_Service $merged;

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
	private Workflow_Catalog $workflows;

	/**
	 * Stage form presenter.
	 *
	 * @var Stage_Form_Presenter
	 */
	private Stage_Form_Presenter $stage_presenter;

	/**
	 * Constructor.
	 *
	 * @param Package_Resolver|null         $packages        Package resolver.
	 * @param Workflow_Catalog|null         $workflows       Workflow catalog.
	 * @param Merged_Blank_Pdf_Service|null $merged          Merged blank PDF service.
	 * @param Routing_Engine|null           $routing         Routing engine.
	 * @param Stage_Form_Presenter|null     $stage_presenter Stage form presenter.
	 */
	public function __construct(
		?Package_Resolver $packages = null,
		?Workflow_Catalog $workflows = null,
		?Merged_Blank_Pdf_Service $merged = null,
		?Routing_Engine $routing = null,
		?Stage_Form_Presenter $stage_presenter = null
	) {
		$this->packages        = $packages ?? new Package_Resolver();
		$this->workflows       = $workflows ?? new Workflow_Catalog();
		$this->merged          = $merged ?? new Merged_Blank_Pdf_Service();
		$this->routing         = $routing ?? new Routing_Engine( $this->workflows );
		$this->stage_presenter = $stage_presenter ?? new Stage_Form_Presenter();
	}

	/**
	 * Resolve case action visibility and summary for the chat UI.
	 *
	 * @param array<string, mixed> $case_profile     Case profile.
	 * @param array<string, mixed> $interpret_result Optional interpreter turn data.
	 * @return array<string, mixed>
	 */
	public function resolve( array $case_profile, array $interpret_result = array() ): array {
		$facts      = is_array( $case_profile['facts'] ?? null ) ? $case_profile['facts'] : array();
		$completion = (int) ( $case_profile['progress'] ?? $interpret_result['completion'] ?? 0 );
		$intent     = (string) ( $interpret_result['intent'] ?? '' );
		$missing    = is_array( $interpret_result['missing_fields'] ?? null ) ? $interpret_result['missing_fields'] : null;

		$routing_status = $this->resolve_routing_status( $case_profile, $interpret_result, $facts );
		$workflow       = (string) ( $routing_status['workflow'] ?? '' );
		$issue          = $this->resolve_issue( $case_profile, $interpret_result, $facts );
		$routing_missing = is_array( $routing_status['routing_missing'] ?? null )
			? $routing_status['routing_missing']
			: array();

		$workflow_resolved = ! empty( $routing_status['resolved'] );
		$case_known        = $workflow_resolved || '' !== $issue || $this->has_case_signals( $facts );
		$intake_complete   = $this->is_intake_complete( $workflow_resolved, $completion, $intent, $missing );
		$procedural_node   = trim( (string) ( $case_profile['procedural_node'] ?? '' ) );
		$stage_context     = $this->stage_presenter->present(
			array(
				'workflow'        => $workflow,
				'facts'           => $facts,
				'intake_complete' => $workflow_resolved,
				'issue'           => $issue,
				'routing_missing' => $routing_missing,
				'current_node'    => $procedural_node,
			)
		);
		$current_stage     = is_array( $stage_context['current_stage'] ?? null )
			? (string) ( $stage_context['current_stage']['id'] ?? '' )
			: null;

		$package_id       = '';
		$package_resolved = false;
		$package_label    = '';
		$blank_pdf        = array(
			'available'    => false,
			'download_url' => '',
		);

		if ( $workflow_resolved && ! empty( $stage_context['forms_visible'] ) ) {
			$blank_pdf = $this->merged->status( $workflow, $current_stage, $facts );

			$resolved = $this->packages->resolve( $workflow, $facts );

			if ( is_array( $resolved ) && ! empty( $resolved['id'] ) ) {
				$package_id       = (string) $resolved['id'];
				$package_resolved = true;
				$package_label    = $this->package_label( $package_id );
			}
		}

		$forms_matched    = count( (array) ( $stage_context['stage_forms'] ?? array() ) );
		$show_documents   = $case_known && ! empty( $stage_context['forms_visible'] );
		$download_enabled = $workflow_resolved
			&& ! empty( $stage_context['forms_visible'] )
			&& (
				$package_resolved
				|| $forms_matched > 0
				|| ! empty( $blank_pdf['available'] )
				|| ! empty( $stage_context['stage_download']['available'] )
			);
		$court_routing   = $this->build_court_routing( $case_profile, $interpret_result );

		$case_summary_rows = array();
		$roadmap           = is_array( $case_profile['roadmap'] ?? null ) ? $case_profile['roadmap'] : array();

		if ( $workflow_resolved && ! empty( $stage_context['forms_visible'] ) ) {
			$presenter         = new Case_Summary_Presenter();
			$case_summary_rows = $presenter->to_action_rows(
				$presenter->build(
					array(
						'workflow'        => $workflow,
						'facts'           => $facts,
						'stage_context'   => $stage_context,
						'roadmap'         => $roadmap,
						'procedural_node' => $procedural_node,
						'completion'      => $completion,
						'court'           => (string) ( $case_profile['court'] ?? '' ),
						'issue'           => $issue,
					)
				)
			);
		}

		$summary_rows = array_merge(
			$case_summary_rows,
			$this->build_summary( $workflow, $facts, $package_id, $package_label, $issue, $court_routing )
		);

		return array(
			'case_known'          => $case_known,
			'intake_complete'     => $intake_complete,
			'workflow_resolved'   => $workflow_resolved,
			'issue'               => $issue,
			'package_resolved'    => $package_resolved,
			'blank_pdf_available' => ! empty( $blank_pdf['available'] ),
			'forms_matched'       => $forms_matched,
			'show_documents'      => $show_documents,
			'download_enabled'    => $download_enabled,
			'download_mode'       => $workflow_resolved ? 'merged' : '',
			'package_id'          => $package_id,
			'package_label'       => $package_label,
			'workflow'            => $workflow,
			'workflow_title'      => $this->workflow_title( $workflow ),
			'court_routing'       => $court_routing,
			'stage_context'       => $stage_context,
			'summary'             => $summary_rows,
		);
	}

	/**
	 * Resolve workflow and routing completion from the profile and interpreter.
	 *
	 * @param array<string, mixed> $case_profile     Case profile.
	 * @param array<string, mixed> $interpret_result Interpreter turn data.
	 * @param array<string, mixed> $facts            Plain facts.
	 * @return array{workflow: string, routing_missing: string[], resolved: bool}
	 */
	private function resolve_routing_status( array $case_profile, array $interpret_result, array $facts ): array {
		$stored_workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		if ( '' !== $stored_workflow ) {
			return array(
				'workflow'        => $stored_workflow,
				'routing_missing' => array(),
				'resolved'        => true,
			);
		}

		$routing_missing = is_array( $interpret_result['routing_missing'] ?? null )
			? array_values( array_map( 'strval', $interpret_result['routing_missing'] ) )
			: array();

		$profile = Case_Profile::from_array( $case_profile );
		$routed  = $this->routing->route_profile( '', $profile );

		if ( empty( $routing_missing ) ) {
			$routing_missing = $routed->missing_fields();
		}

		$workflow = trim( (string) ( $interpret_result['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			$state = is_array( $interpret_result['state'] ?? null ) ? $interpret_result['state'] : array();
			$workflow = trim( (string) ( $state['workflow'] ?? '' ) );
		}

		$routed_workflow = null !== $routed->workflow() ? trim( (string) $routed->workflow() ) : '';

		if ( '' === $workflow && '' !== $routed_workflow ) {
			$workflow = $routed_workflow;
		}

		if ( '' !== $workflow && '' !== $routed_workflow && $workflow === $routed_workflow ) {
			return array(
				'workflow'        => $workflow,
				'routing_missing' => array(),
				'resolved'        => true,
			);
		}

		$resolved = '' !== $workflow && empty( $routing_missing );

		return array(
			'workflow'        => $workflow,
			'routing_missing' => $routing_missing,
			'resolved'        => $resolved,
		);
	}

	/**
	 * Resolve the best workflow key for actions and downloads.
	 *
	 * @param array<string, mixed> $case_profile     Case profile.
	 * @param array<string, mixed> $interpret_result Interpreter turn data.
	 * @param array<string, mixed> $facts            Plain facts.
	 * @return string
	 */
	private function resolve_workflow_key( array $case_profile, array $interpret_result, array $facts ): string {
		$workflow = trim( (string) ( $case_profile['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			$workflow = trim( (string) ( $interpret_result['workflow'] ?? '' ) );
		}

		if ( '' === $workflow ) {
			$state = is_array( $interpret_result['state'] ?? null ) ? $interpret_result['state'] : array();
			$workflow = trim( (string) ( $state['workflow'] ?? '' ) );
		}

		if ( '' !== $workflow ) {
			return $workflow;
		}

		$candidates = $this->candidate_workflows( $case_profile, $interpret_result );

		if ( ! empty( $candidates ) ) {
			return $this->pick_candidate_workflow( $candidates );
		}

		$profile = Case_Profile::from_array( $case_profile );
		$routed  = $this->routing->route_profile( '', $profile );

		if ( null !== $routed->workflow() && '' !== $routed->workflow() ) {
			return (string) $routed->workflow();
		}

		$candidates = $routed->candidate_workflows();

		if ( ! empty( $candidates ) ) {
			return $this->pick_candidate_workflow( $candidates );
		}

		$issue = $this->resolve_issue( $case_profile, $interpret_result, $facts );

		if ( '' !== $issue ) {
			$by_issue = $this->workflows->by_issue( $this->base_issue( $issue ) );

			if ( ! empty( $by_issue ) ) {
				return $this->pick_candidate_workflow( array_keys( $by_issue ) );
			}
		}

		return '';
	}

	/**
	 * Resolve the current issue type.
	 *
	 * @param array<string, mixed> $case_profile     Case profile.
	 * @param array<string, mixed> $interpret_result Interpreter turn data.
	 * @param array<string, mixed> $facts            Plain facts.
	 * @return string
	 */
	private function resolve_issue( array $case_profile, array $interpret_result, array $facts ): string {
		foreach ( array(
			(string) ( $case_profile['issue'] ?? '' ),
			(string) ( $interpret_result['issue'] ?? '' ),
			$this->fact_string( $facts, array( 'issue' ) ),
		) as $candidate ) {
			$candidate = trim( $candidate );

			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		$profile = Case_Profile::from_array( $case_profile );
		$routed  = $this->routing->route_profile( '', $profile );
		$issue   = $routed->issue();

		return null !== $issue ? trim( (string) $issue ) : '';
	}

	/**
	 * Whether we have enough signal to treat the matter as identified.
	 *
	 * @param array<string, mixed> $facts Plain facts.
	 * @return bool
	 */
	private function has_case_signals( array $facts ): bool {
		if ( '' !== $this->fact_string( $facts, array( 'issue' ) ) ) {
			return true;
		}

		foreach ( array( 'spouse_agrees', 'has_minor_children', 'children', 'child_count' ) as $key ) {
			if ( array_key_exists( $key, $facts ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Candidate workflows from profile or interpreter state.
	 *
	 * @param array<string, mixed> $case_profile     Case profile.
	 * @param array<string, mixed> $interpret_result Interpreter turn data.
	 * @return string[]
	 */
	private function candidate_workflows( array $case_profile, array $interpret_result ): array {
		$candidates = array();

		if ( is_array( $case_profile['candidate_workflows'] ?? null ) ) {
			$candidates = array_merge( $candidates, $case_profile['candidate_workflows'] );
		}

		$state = is_array( $interpret_result['state'] ?? null ) ? $interpret_result['state'] : array();

		if ( is_array( $state['candidate_workflows'] ?? null ) ) {
			$candidates = array_merge( $candidates, $state['candidate_workflows'] );
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $candidates ) ) ) );
	}

	/**
	 * Pick the best workflow from routing candidates.
	 *
	 * @param string[] $candidates Workflow keys.
	 * @return string
	 */
	private function pick_candidate_workflow( array $candidates ): string {
		$best      = '';
		$best_prio = -1;

		foreach ( $candidates as $key ) {
			$key = trim( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			$definition = $this->workflows->by_key( $key );
			$priority   = is_array( $definition ) ? (int) ( $definition['intake_priority'] ?? 0 ) : 0;

			if ( $priority > $best_prio ) {
				$best_prio = $priority;
				$best      = $key;
			}
		}

		if ( '' !== $best ) {
			return $best;
		}

		return trim( (string) ( $candidates[0] ?? '' ) );
	}

	/**
	 * Base issue without refinements.
	 *
	 * @param string $issue Issue type.
	 * @return string
	 */
	private function base_issue( string $issue ): string {
		if ( str_starts_with( $issue, 'divorce' ) ) {
			return 'divorce';
		}

		return $issue;
	}

	/**
	 * Count required and optional forms defined for a workflow.
	 *
	 * @param string $workflow Workflow key.
	 * @return int
	 */
	private function count_workflow_forms( string $workflow ): int {
		if ( '' === $workflow ) {
			return 0;
		}

		$definition = $this->workflows->by_key( $workflow );

		if ( ! is_array( $definition ) ) {
			return 0;
		}

		$count = count( $this->workflows->required_form_codes( $definition ) );

		foreach ( (array) ( $definition['optional_forms'] ?? array() ) as $stage ) {
			foreach ( (array) ( $stage['forms'] ?? array() ) as $form ) {
				if ( ! empty( $form['code'] ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Whether intake is complete for action purposes.
	 *
	 * @param bool                   $workflow_resolved Workflow is set.
	 * @param int                    $completion        Completion percent.
	 * @param string                 $intent            Turn intent.
	 * @param array<int, mixed>|null $missing           Missing fields, if known.
	 * @return bool
	 */
	private function is_intake_complete(
		bool $workflow_resolved,
		int $completion,
		string $intent,
		?array $missing
	): bool {
		if ( ! $workflow_resolved ) {
			return false;
		}

		if ( 'intake_complete' === $intent ) {
			return true;
		}

		if ( null !== $missing && empty( $missing ) ) {
			return true;
		}

		return $completion >= 100;
	}

	/**
	 * Build court routing metadata from the stored profile or routing engine.
	 *
	 * @param array<string, mixed> $case_profile     Case profile.
	 * @param array<string, mixed> $interpret_result Interpreter turn data.
	 * @return array<string, mixed>
	 */
	private function build_court_routing( array $case_profile, array $interpret_result ): array {
		$courts = is_array( $case_profile['courts'] ?? null ) ? array_values( $case_profile['courts'] ) : array();
		$court  = trim( (string) ( $case_profile['court'] ?? '' ) );

		if ( ! empty( $courts ) || '' !== $court || ! empty( $case_profile['overlap'] ) || ! empty( $case_profile['routing_explanation'] ) || ! empty( $case_profile['routing_note'] ) ) {
			return array(
				'court'               => '' !== $court ? $court : ( $courts[0] ?? '' ),
				'courts'              => $courts,
				'overlap'             => ! empty( $case_profile['overlap'] ),
				'overlap_reason'      => isset( $case_profile['overlap_reason'] ) ? (string) $case_profile['overlap_reason'] : '',
				'routing_explanation' => (string) ( $case_profile['routing_explanation'] ?? '' ),
				'routing_note'        => (string) ( $case_profile['routing_note'] ?? '' ),
			);
		}

		$profile = Case_Profile::from_array( $case_profile );
		$routed  = $this->routing->route_profile( '', $profile );

		return array(
			'court'               => (string) ( $routed->court() ?? '' ),
			'courts'              => $routed->courts(),
			'overlap'             => $routed->overlap(),
			'overlap_reason'      => (string) ( $routed->overlap_reason() ?? '' ),
			'routing_explanation' => $routed->routing_explanation(),
			'routing_note'        => $routed->routing_note(),
		);
	}

	/**
	 * Build the case summary rows for the action panel.
	 *
	 * @param string               $workflow      Workflow key.
	 * @param array<string, mixed> $facts         Plain facts.
	 * @param string               $package_id    Package enum id.
	 * @param string               $package_label Human package label.
	 * @param string               $issue         Issue type.
	 * @param array<string, mixed> $court_routing Court routing metadata.
	 * @return array<int, array{label: string, value: string}>
	 */
	private function build_summary( string $workflow, array $facts, string $package_id, string $package_label, string $issue = '', array $court_routing = array() ): array {
		$rows = array();

		$courts = is_array( $court_routing['courts'] ?? null ) ? $court_routing['courts'] : array();
		$court_label = Court_Routing_Explainer::courts_summary( $courts );

		if ( '' === $court_label && ! empty( $court_routing['court'] ) ) {
			$court_label = Court_Routing_Explainer::court_label( (string) $court_routing['court'] );
		}

		if ( '' !== $court_label ) {
			$rows[] = array(
				'label' => ! empty( $court_routing['overlap'] )
					? __( 'Courts involved', 'prose-core' )
					: __( 'Court', 'prose-core' ),
				'value' => $court_label,
			);
		}

		$explanation = trim( (string) ( $court_routing['routing_explanation'] ?? '' ) );
		$note        = trim( (string) ( $court_routing['routing_note'] ?? '' ) );

		if ( '' !== $explanation ) {
			$rows[] = array(
				'label' => __( 'Court routing', 'prose-core' ),
				'value' => $explanation,
			);
		} elseif ( '' !== $note ) {
			$rows[] = array(
				'label' => __( 'Court routing', 'prose-core' ),
				'value' => $note,
			);
		}

		$county = $this->fact_string( $facts, array( 'county' ) );

		if ( '' !== $county ) {
			$rows[] = array(
				'label' => __( 'County', 'prose-core' ),
				'value' => $county,
			);
		}

		$matter = $this->workflow_title( $workflow );

		if ( '' === $matter && '' !== $issue ) {
			$matter = ucwords( str_replace( array( '_', '-' ), ' ', $issue ) );
		}

		if ( '' !== $matter ) {
			$rows[] = array(
				'label' => __( 'Matter', 'prose-core' ),
				'value' => $matter,
			);
		}

		$children = $this->children_summary( $facts );

		if ( '' !== $children ) {
			$rows[] = array(
				'label' => __( 'Children', 'prose-core' ),
				'value' => $children,
			);
		}

		if ( '' !== $package_label ) {
			$rows[] = array(
				'label' => __( 'Package', 'prose-core' ),
				'value' => $package_label,
			);
		} elseif ( '' !== $package_id ) {
			$rows[] = array(
				'label' => __( 'Package', 'prose-core' ),
				'value' => $package_id,
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $facts Fact map.
	 * @param string[]             $keys  Candidate keys.
	 * @return string
	 */
	private function fact_string( array $facts, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( ! isset( $facts[ $key ] ) ) {
				continue;
			}

			$value = $facts[ $key ];

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $facts Plain facts.
	 * @return string
	 */
	private function children_summary( array $facts ): string {
		foreach ( array( 'child_count', 'children_count' ) as $key ) {
			if ( isset( $facts[ $key ] ) && is_numeric( $facts[ $key ] ) ) {
				return (string) (int) $facts[ $key ];
			}
		}

		foreach ( array( 'has_minor_children', 'children', 'minor_children_involved' ) as $key ) {
			if ( ! isset( $facts[ $key ] ) ) {
				continue;
			}

			$value = $facts[ $key ];

			if ( is_bool( $value ) ) {
				return $value ? '1' : '0';
			}

			if ( is_numeric( $value ) ) {
				return (string) (int) $value;
			}
		}

		return '';
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

	/**
	 * @param string $package_id Package enum id.
	 * @return string
	 */
	private function package_label( string $package_id ): string {
		$row = $this->packages->package_row( $package_id );

		if ( is_array( $row ) && ! empty( $row['package_name'] ) ) {
			return (string) $row['package_name'];
		}

		$catalog = Vocabulary::package_catalog();
		$entry   = $catalog[ $package_id ] ?? null;

		if ( is_array( $entry ) && ! empty( $entry['package_name'] ) ) {
			return (string) $entry['package_name'];
		}

		return $package_id;
	}
}
