<?php
/**
 * Orchestrates the 12-step form classification pipeline.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

use ProSe\Core\Forms\Form_Meta;
use ProSe\Core\Forms\Form_Repository;
use ProSe\Core\Forms\Pdf_Analyzer;
use ProSe\Core\Forms\Classification\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Classification_Engine
 */
final class Classification_Engine {

	/**
	 * PDF analyzer.
	 *
	 * @var Pdf_Analyzer
	 */
	private Pdf_Analyzer $pdf_analyzer;

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $repository;

	/**
	 * Court classifier.
	 *
	 * @var Court_Classifier
	 */
	private Court_Classifier $court_classifier;

	/**
	 * County classifier.
	 *
	 * @var County_Classifier
	 */
	private County_Classifier $county_classifier;

	/**
	 * Case type classifier.
	 *
	 * @var Case_Type_Classifier
	 */
	private Case_Type_Classifier $case_type_classifier;

	/**
	 * Workflow classifier.
	 *
	 * @var Workflow_Classifier
	 */
	private Workflow_Classifier $workflow_classifier;

	/**
	 * Questionnaire mapper.
	 *
	 * @var Questionnaire_Mapper
	 */
	private Questionnaire_Mapper $questionnaire_mapper;

	/**
	 * Dependency resolver.
	 *
	 * @var Dependency_Resolver
	 */
	private Dependency_Resolver $dependency_resolver;

	/**
	 * Workflow package builder.
	 *
	 * @var Workflow_Package_Builder
	 */
	private Workflow_Package_Builder $package_builder;

	/**
	 * AI summarizer.
	 *
	 * @var Ai_Summarizer
	 */
	private Ai_Summarizer $ai_summarizer;

	/**
	 * Workflow mapper.
	 *
	 * @var Workflow_Mapper
	 */
	private Workflow_Mapper $workflow_mapper;

	/**
	 * Court router.
	 *
	 * @var Court_Router
	 */
	private Court_Router $court_router;

	/**
	 * Workflow node mapper.
	 *
	 * @var Workflow_Node_Mapper
	 */
	private Workflow_Node_Mapper $node_mapper;

	/**
	 * Package detector.
	 *
	 * @var Package_Detector
	 */
	private Package_Detector $package_detector;

	/**
	 * Constructor.
	 *
	 * @param Pdf_Analyzer             $pdf_analyzer         PDF analyzer.
	 * @param Form_Repository          $repository           Repository.
	 * @param Court_Classifier         $court_classifier     Court classifier.
	 * @param County_Classifier        $county_classifier    County classifier.
	 * @param Case_Type_Classifier     $case_type_classifier Case type classifier.
	 * @param Workflow_Classifier      $workflow_classifier  Workflow classifier.
	 * @param Questionnaire_Mapper     $questionnaire_mapper Questionnaire mapper.
	 * @param Dependency_Resolver      $dependency_resolver  Dependency resolver.
	 * @param Workflow_Package_Builder $package_builder      Package builder.
	 * @param Ai_Summarizer            $ai_summarizer        AI summarizer.
	 * @param Workflow_Mapper          $workflow_mapper      Workflow mapper.
	 * @param Court_Router             $court_router         Court router.
	 * @param Workflow_Node_Mapper     $node_mapper          Node mapper.
	 * @param Package_Detector         $package_detector     Package detector.
	 */
	public function __construct(
		Pdf_Analyzer $pdf_analyzer,
		Form_Repository $repository,
		Court_Classifier $court_classifier,
		County_Classifier $county_classifier,
		Case_Type_Classifier $case_type_classifier,
		Workflow_Classifier $workflow_classifier,
		Questionnaire_Mapper $questionnaire_mapper,
		Dependency_Resolver $dependency_resolver,
		Workflow_Package_Builder $package_builder,
		Ai_Summarizer $ai_summarizer,
		Workflow_Mapper $workflow_mapper,
		Court_Router $court_router,
		Workflow_Node_Mapper $node_mapper,
		Package_Detector $package_detector
	) {
		$this->pdf_analyzer         = $pdf_analyzer;
		$this->repository           = $repository;
		$this->court_classifier     = $court_classifier;
		$this->county_classifier    = $county_classifier;
		$this->case_type_classifier = $case_type_classifier;
		$this->workflow_classifier  = $workflow_classifier;
		$this->questionnaire_mapper = $questionnaire_mapper;
		$this->dependency_resolver  = $dependency_resolver;
		$this->package_builder      = $package_builder;
		$this->ai_summarizer        = $ai_summarizer;
		$this->workflow_mapper      = $workflow_mapper;
		$this->court_router         = $court_router;
		$this->node_mapper          = $node_mapper;
		$this->package_detector     = $package_detector;
	}

	/**
	 * Classify a form and persist results.
	 *
	 * @param int                  $post_id   Form post ID.
	 * @param array<string, mixed> $csv_hints Optional CSV hints.
	 * @return array<string, mixed>
	 */
	public function classify( int $post_id, array $csv_hints = array() ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array(
				'success' => false,
				'message' => __( 'Form not found.', 'prose-core' ),
			);
		}

		$result = $this->run_pipeline( $post_id, $csv_hints, false );

		if ( ! empty( $result['success'] ) ) {
			$this->repository->save_classification( $post_id, $result );
		}

		return $result;
	}

	/**
	 * Reclassify a form (re-analyze PDF, update metadata, preserve audit trail).
	 *
	 * @param int  $post_id Form post ID.
	 * @param bool $force   Ignore manual override.
	 * @return array<string, mixed>
	 */
	public function reclassify( int $post_id, bool $force = false ): array {
		$manual = (bool) get_post_meta( $post_id, Form_Meta::META_MANUAL_OVERRIDE, true );

		if ( $manual && ! $force ) {
			return array(
				'success' => false,
				'message' => __( 'Form has manual override. Use force reclassify to overwrite.', 'prose-core' ),
			);
		}

		$result = $this->run_pipeline( $post_id, array(), $force );

		if ( ! empty( $result['success'] ) ) {
			$this->repository->save_classification( $post_id, $result, $force );
		}

		return $result;
	}

	/**
	 * Execute the full classification pipeline without persisting.
	 *
	 * @param int                  $post_id   Post ID.
	 * @param array<string, mixed> $csv_hints CSV hints.
	 * @param bool                 $force     Force reclassification.
	 * @return array<string, mixed>
	 */
	private function run_pipeline( int $post_id, array $csv_hints, bool $force ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array( 'success' => false, 'message' => __( 'Form not found.', 'prose-core' ) );
		}

		$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_CODE, true );

		if ( '' === $form_code ) {
			$form_code = (string) get_post_meta( $post_id, Form_Meta::META_FORM_ID, true );
		}

		$file_name = (string) get_post_meta( $post_id, Form_Meta::META_FILE_NAME, true );
		$file_path = $this->pdf_analyzer->resolve_pdf_path( $post_id );

		if ( '' === $file_path ) {
			return array(
				'success' => false,
				'message' => __( 'No PDF available for classification.', 'prose-core' ),
			);
		}

		// Step 1-2: Extract text and fields.
		$text   = $this->pdf_analyzer->extract_text( $file_path );
		$fields = $this->pdf_analyzer->extract_fields( $file_path );

		$base_ctx = array(
			'text'      => $text,
			'title'     => $post->post_title,
			'filename'  => $file_name,
			'form_code' => $form_code,
			'csv_court' => (string) ( $csv_hints['court'] ?? '' ),
			'csv_county' => (string) ( $csv_hints['county'] ?? '' ),
			'csv_case_type' => (string) ( $csv_hints['case_type'] ?? '' ),
			'csv_workflow_stage' => (string) ( $csv_hints['workflow_stage'] ?? '' ),
		);

		// Step 3: Case type (independent of court, feeds court fallback).
		$case_type = $this->case_type_classifier->classify( $base_ctx );

		// Step 4: Workflow package (from case type) — a court fallback signal.
		$workflow_package = $this->package_builder->build(
			(string) ( $case_type['value'] ?? '' ),
			$form_code
		);

		// Step 5: Court (multi-signal with intelligent fallback).
		$court_ctx = array_merge(
			$base_ctx,
			array(
				'case_type'        => (string) ( $case_type['value'] ?? '' ),
				'workflow_package' => $workflow_package,
			)
		);
		$court = $this->court_classifier->classify( $court_ctx );

		// Step 6: County.
		$county = $this->county_classifier->classify( $base_ctx );

		// Step 7: Workflow stage (court-aware).
		$workflow_ctx = array_merge( $base_ctx, array( 'court' => $court['value'] ?? '' ) );
		$workflow     = $this->workflow_classifier->classify( $workflow_ctx );

		// Step 8-9: Fillable fields.
		$fillable   = $this->pdf_analyzer->detect_fillable( $fields );
		$normalized = $this->pdf_analyzer->normalize_fields( $fields );

		// Step 10: Questionnaire keys.
		$questionnaire_keys = $this->questionnaire_mapper->map(
			$normalized,
			(string) ( $case_type['value'] ?? '' )
		);

		// Step 11: Dependencies.
		$deps_full    = $this->dependency_resolver->resolve_full( $form_code );
		$dependencies = $deps_full['dependencies'];

		// Step 12: CourtFlow enrichment (workflows, routing, nodes, packages).
		$enrich_ctx = array_merge(
			$base_ctx,
			array(
				'case_type'      => (string) ( $case_type['value'] ?? '' ),
				'court'          => (string) ( $court['value'] ?? '' ),
				'workflow_stage' => (string) ( $workflow['value'] ?? '' ),
			)
		);

		$workflow_map = $this->workflow_mapper->map( $enrich_ctx );
		$court_routing = $this->court_router->route(
			array_merge(
				$enrich_ctx,
				array( 'workflow_ids' => $workflow_map['workflow_ids'] )
			)
		);
		$package_ids = $this->package_detector->detect( $enrich_ctx );
		$node_map    = $this->node_mapper->map( $enrich_ctx );

		$workflow_stages = array();
		$stage_enum      = Vocabulary::stage_to_enum( (string) ( $workflow['value'] ?? '' ) );

		if ( '' !== $stage_enum ) {
			$workflow_stages[] = $stage_enum;
		}

		$document_type = Vocabulary::document_type_for_form_code( $form_code );
		$filing_party  = Vocabulary::filing_party_for_form_code( $form_code );
		$served_party  = Vocabulary::served_party_for_form_code( $form_code );

		// Step 13: AI summary.
		$summary_ctx = array(
			'court'          => $court['value'] ?? '',
			'case_type'      => $case_type['value'] ?? '',
			'workflow_stage' => $workflow['value'] ?? '',
			'form_code'      => $form_code,
			'title'          => $post->post_title,
			'court_routing'  => $court_routing,
			'next_steps'     => $node_map['next_steps'],
		);
		$ai_summary           = $this->ai_summarizer->summarize( $summary_ctx );
		$ai_summary_structured = $this->ai_summarizer->summarize_structured( $summary_ctx );

		// Court classification is the anchor: its confidence drives the headline
		// score, taxonomy auto-assignment, and the manual-review decision.
		$court_value      = (string) ( $court['value'] ?? '' );
		$court_confidence = (int) ( $court['confidence'] ?? 0 );
		$confidence       = $court_confidence;

		// Source reflects how the court was determined (pdf_content, combined_signals, etc.).
		$source = (string) ( $court['source'] ?? Classification_Result::SOURCE_AI_INFERENCE );

		// Contributing court signals (for the admin UI).
		$court_signals = array_values( (array) ( $court['signals'] ?? array() ) );

		// Warnings: CSV conflicts + unsupported court.
		$warnings = $this->build_warnings( $csv_hints, $court, $county, $case_type, $workflow );

		$supported_court = (bool) ( $court['supported'] ?? true );
		$needs_review    = '' === $court_value
			|| $court_confidence < Classification_Result::CONFIDENCE_THRESHOLD
			|| ! $supported_court;

		if ( $needs_review && $supported_court ) {
			$warnings[] = __( 'Insufficient Classification Confidence', 'prose-core' );
		}

		$warnings = array_values( array_unique( $warnings ) );

		$pdf_data = array(
			'fillable'        => $fillable,
			'field_count'     => count( $fields ),
			'fields_json'     => array(
				'field_count' => count( $fields ),
				'fields'      => array_column( $fields, 'name' ),
			),
			'fillable_fields' => $normalized,
			'analyzed_at'     => gmdate( 'c' ),
		);

		$this->pdf_analyzer->save_metadata( $post_id, $pdf_data );

		return array(
			'success'              => true,
			'force'                => $force,
			'detected_court'       => (string) ( $court['value'] ?? '' ),
			'supported_court'      => $supported_court,
			'detected_county'      => (string) ( $county['value'] ?? '' ),
			'detected_case_type'   => (string) ( $case_type['value'] ?? '' ),
			'detected_workflow_stage' => (string) ( $workflow['value'] ?? '' ),
			'classification_confidence' => $confidence,
			'classification_source'  => $source,
			'classification_signals' => $court_signals,
			'classification_warning' => implode( ' ', $warnings ),
			'needs_review'         => $needs_review,
			'pdf_fillable'         => $fillable,
			'pdf_field_count'      => count( $fields ),
			'pdf_fields_json'      => $pdf_data['fields_json'],
			'fillable_fields'      => $normalized,
			'pdf_analyzed_at'      => $pdf_data['analyzed_at'],
			'questionnaire_keys'   => $questionnaire_keys,
			'dependencies'            => $dependencies,
			'workflow_package'        => $workflow_package,
			'ai_summary'              => $ai_summary,
			'ai_summary_structured'   => $ai_summary_structured,
			'user_summary'            => (string) ( $ai_summary_structured['user_summary'] ?? '' ),
			'confidence_score'        => $confidence,
			'workflow_ids'            => $workflow_map['workflow_ids'],
			'package_ids'             => $package_ids,
			'workflow_stages'         => $workflow_stages,
			'issue_types'             => $workflow_map['issue_types'],
			'court_routing'           => $court_routing,
			'workflow_nodes'          => $node_map['workflow_nodes'],
			'trigger_events'          => $node_map['trigger_events'],
			'completion_events'       => $node_map['completion_events'],
			'next_steps'              => $node_map['next_steps'],
			'required_before'         => $deps_full['required_before'],
			'required_after'          => $deps_full['required_after'],
			'prerequisite_forms'      => $deps_full['prerequisite_forms'],
			'dependent_forms'         => $deps_full['dependent_forms'],
			'related_forms'           => $deps_full['related_forms'],
			'package_dependencies'    => $deps_full['package_dependencies'],
			'workflow_dependencies'   => $deps_full['workflow_dependencies'],
			'document_type'           => $document_type,
			'filing_party'            => $filing_party,
			'served_party'            => $served_party,
			'aliases'                 => array(),
			'classifiers'             => array(
				'court'    => $court,
				'county'   => $county,
				'case_type' => $case_type,
				'workflow' => $workflow,
			),
		);
	}

	/**
	 * Build warning messages for CSV/PDF conflicts.
	 *
	 * @param array<string, mixed> $csv_hints CSV hints.
	 * @param array<string, mixed> ...$results Classifier results.
	 * @return string[]
	 */
	private function build_warnings( array $csv_hints, array ...$results ): array {
		$warnings = array();
		$map      = array(
			'court'          => 'court',
			'case_type'      => 'case_type',
			'workflow_stage' => 'workflow_stage',
		);

		foreach ( $results as $result ) {
			if ( empty( $result['value'] ) ) {
				continue;
			}

			if ( false === ( $result['supported'] ?? true ) ) {
				$warnings[] = __( 'Unsupported Court', 'prose-core' );
			}
		}

		if ( ! empty( $csv_hints['court'] ) && ! empty( $results[0]['value'] ) ) {
			$csv_court = strtolower( (string) $csv_hints['court'] );
			$pdf_court = strtolower( (string) ( $results[0]['value'] ?? '' ) );

			if ( $csv_court !== $pdf_court && ! str_contains( $pdf_court, $csv_court ) ) {
				$warnings[] = sprintf(
					/* translators: 1: CSV value, 2: PDF value */
					__( 'CSV court "%1$s" overridden by PDF "%2$s".', 'prose-core' ),
					$csv_hints['court'],
					$results[0]['value']
				);
			}
		}

		if ( ! empty( $csv_hints['case_type'] ) && isset( $results[2]['value'] ) && '' !== $results[2]['value'] ) {
			$csv_case = strtolower( (string) $csv_hints['case_type'] );
			$pdf_case = strtolower( (string) $results[2]['value'] );

			if ( $csv_case !== $pdf_case && ! str_contains( $pdf_case, $csv_case ) ) {
				$warnings[] = sprintf(
					/* translators: 1: CSV value, 2: PDF value */
					__( 'CSV case type "%1$s" overridden by PDF "%2$s".', 'prose-core' ),
					$csv_hints['case_type'],
					$results[2]['value']
				);
			}
		}

		return array_values( array_unique( $warnings ) );
	}
}
