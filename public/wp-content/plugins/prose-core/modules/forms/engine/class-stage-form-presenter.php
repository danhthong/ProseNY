<?php
/**
 * Stage Form Presenter — stage-gated form disclosure for procedural workflows.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Guidance\Filing_Guidance_Brief_Resolver;
use ProSe\Core\Guidance\Guidance_Repository;
use ProSe\Core\Forms\Form_Page_Resolver;
use ProSe\Core\PackageBuilder\Merged_Blank_Pdf_Service;
use ProSe\Core\Packet\Pdf_Resolver;
use ProSe\Core\Routing\Fact_Store;
use ProSe\Core\Routing\Routing_Engine;
use ProSe\Core\Routing\Validators\Missing_Info_Detector;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Stage_Form_Presenter
 *
 * Single authority for which forms are visible in the UI at a given moment.
 */
final class Stage_Form_Presenter {

	/**
	 * Routing discriminator questions used during divorce intake.
	 *
	 * @var string[]
	 */
	private const DIVORCE_ROUTING_KEYS = array(
		'spouse_agrees',
		'children',
		'marital_property_resolved',
		'active_divorce',
		'spouse_responded',
	);

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Progression service.
	 *
	 * @var Workflow_Progression_Service
	 */
	private Workflow_Progression_Service $progression;

	/**
	 * Guidance repository.
	 *
	 * @var Guidance_Repository
	 */
	private Guidance_Repository $guidance;

	/**
	 * Merged blank PDF service.
	 *
	 * @var Merged_Blank_Pdf_Service
	 */
	private Merged_Blank_Pdf_Service $merged;

	/**
	 * Missing info detector.
	 *
	 * @var Missing_Info_Detector
	 */
	private Missing_Info_Detector $missing_detector;

	/**
	 * Routing engine.
	 *
	 * @var Routing_Engine
	 */
	private Routing_Engine $routing;

	/**
	 * PDF resolver.
	 *
	 * @var Pdf_Resolver
	 */
	private Pdf_Resolver $pdf_resolver;

	/**
	 * Form page resolver.
	 *
	 * @var Form_Page_Resolver
	 */
	private Form_Page_Resolver $form_pages;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null            $catalog          Catalog.
	 * @param Workflow_Progression_Service|null $progression      Progression.
	 * @param Guidance_Repository|null         $guidance         Guidance repo.
	 * @param Merged_Blank_Pdf_Service|null    $merged           Merged PDFs.
	 * @param Missing_Info_Detector|null       $missing_detector Missing detector.
	 * @param Routing_Engine|null              $routing          Routing engine.
	 * @param Pdf_Resolver|null                $pdf_resolver     PDF resolver.
	 * @param Form_Page_Resolver|null          $form_pages       Form page resolver.
	 */
	public function __construct(
		?Workflow_Catalog $catalog = null,
		?Workflow_Progression_Service $progression = null,
		?Guidance_Repository $guidance = null,
		?Merged_Blank_Pdf_Service $merged = null,
		?Missing_Info_Detector $missing_detector = null,
		?Routing_Engine $routing = null,
		?Pdf_Resolver $pdf_resolver = null,
		?Form_Page_Resolver $form_pages = null
	) {
		$this->catalog          = $catalog ?? new Workflow_Catalog();
		$this->progression      = $progression ?? new Workflow_Progression_Service( $this->catalog );
		$this->guidance         = $guidance ?? new Guidance_Repository();
		$this->merged           = $merged ?? new Merged_Blank_Pdf_Service( $this->catalog );
		$this->missing_detector = $missing_detector ?? new Missing_Info_Detector();
		$this->routing          = $routing ?? new Routing_Engine( $this->catalog );
		$this->pdf_resolver     = $pdf_resolver ?? new Pdf_Resolver();
		$this->form_pages       = $form_pages ?? new Form_Page_Resolver();
	}

	/**
	 * Whether stage-gated form display is active.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		/**
		 * Filter stage-gated form disclosure.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'prose_stage_gated_forms', true );
	}

	/**
	 * Build the stage context DTO for API / UI consumption.
	 *
	 * @param array<string, mixed> $input {
	 *     @type string               $workflow         Resolved workflow key (may be empty).
	 *     @type array<string, mixed> $facts            Plain facts.
	 *     @type bool                 $intake_complete  Whether intake is complete.
	 *     @type string               $current_node     Procedural node key (optional).
	 *     @type string               $issue            Issue type (optional).
	 *     @type string[]             $routing_missing  Precomputed routing missing keys (optional).
	 * }
	 * @return array<string, mixed>
	 */
	public function present( array $input ): array {
		if ( ! $this->is_enabled() ) {
			return $this->legacy_flat_context( $input );
		}

		$workflow        = trim( (string) ( $input['workflow'] ?? '' ) );
		$facts           = is_array( $input['facts'] ?? null ) ? $input['facts'] : array();
		$intake_complete = ! empty( $input['intake_complete'] );
		$context         = $facts;
		$routing_missing = $this->resolve_routing_missing( $workflow, $facts, $input );

		if ( '' === $workflow || ! empty( $routing_missing ) ) {
			return $this->empty_context( $routing_missing );
		}

		// Blank forms unlock once routing resolves the workflow. Personal intake
		// fields (names, dates, income, etc.) are optional for download.

		$current_node  = $this->resolve_current_node( $workflow, $input, $context );
		$current_stage = $this->progression->get_current_stage( $workflow, $current_node, $context );
		$stages        = $this->progression->get_stages( $workflow, $context );

		if ( null === $current_stage || '' === $current_stage ) {
			$current_stage = $stages[0] ?? 'commencement';
		}

		$stage_index   = array_search( $current_stage, $stages, true );
		$partition     = $this->progression->partition_stage_forms( $workflow, $current_stage, $context );
		$stage_forms   = $this->build_stage_forms( $partition['applicable'] );
		$skipped_forms = $this->build_skipped_forms( $partition['skipped'] );
		$pending_forms = $this->build_skipped_forms( $partition['pending'] );
		$form_groups   = ( new Stage_Form_Group_Presenter() )->present( $partition, $workflow, $current_stage );
		$guidance      = $this->guidance->read_stage( $current_stage );
		$future_stages = $this->build_future_stages( $stages, $stage_index );
		$merged        = $this->merged->status( $workflow, $current_stage, $context );
		$download_opts = ( new Filing_Guidance_Brief_Resolver() )->download_options(
			array(
				'workflow'    => $workflow,
				'facts'       => $context,
				'stage'       => $current_stage,
				'stage_forms' => $stage_forms,
			)
		);

		return array(
			'forms_visible'    => true,
			'current_stage'    => array(
				'id'          => $current_stage,
				'title'       => (string) ( $guidance['title'] ?? $this->humanize_stage( $current_stage ) ),
				'description' => (string) ( $guidance['description'] ?? '' ),
				'status'      => 'current',
			),
			'stage_forms'      => $stage_forms,
			'skipped_forms'    => $skipped_forms,
			'pending_forms'    => $pending_forms,
			'form_groups'      => $form_groups,
			'future_stages'    => $future_stages,
			'next_action'      => $this->next_action( $current_stage, $guidance, $workflow ),
			'stage_download'   => array(
				'available'    => ! empty( $merged['available'] ),
				'download_url' => (string) ( $merged['download_url'] ?? '' ),
			),
			'procedural_node'  => $current_node,
			'download_options' => $download_opts,
		);
	}

	/**
	 * Current-stage form codes only (deprecated alias helper).
	 *
	 * @param array<string, mixed> $input Presenter input.
	 * @return array<int, string>
	 */
	public function current_stage_form_codes( array $input ): array {
		$context = $this->present( $input );

		if ( empty( $context['forms_visible'] ) ) {
			return array();
		}

		$codes = array();

		foreach ( (array) ( $context['stage_forms'] ?? array() ) as $form ) {
			$code = trim( (string) ( $form['code'] ?? '' ) );

			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * Resolve trigger for completing a procedural stage.
	 *
	 * @param string $workflow_key Workflow key.
	 * @param string $stage_slug   Stage slug being completed.
	 * @param array<string, mixed> $facts Facts.
	 * @return array{kind: string, value: string}|null
	 */
	public function completion_trigger( string $workflow_key, string $stage_slug, array $facts = array() ): ?array {
		$package = $this->package_for_stage( $workflow_key, $stage_slug, $facts );

		if ( null !== $package ) {
			return array(
				'kind'  => Case_Catalog::COND_PACKAGE,
				'value' => $package,
			);
		}

		$event = $this->event_for_stage( $stage_slug );

		if ( null !== $event ) {
			return array(
				'kind'  => Case_Catalog::COND_EVENT,
				'value' => $event,
			);
		}

		return null;
	}

	/**
	 * Advance procedural node after stage completion.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param string               $current_node Current node.
	 * @param string               $stage_slug   Completed stage.
	 * @param array<string, mixed> $facts        Facts.
	 * @return string Advanced node key.
	 */
	public function advance_after_stage( string $workflow_key, string $current_node, string $stage_slug, array $facts = array() ): string {
		$next_stage = $this->progression->get_next_stage( $workflow_key, $stage_slug, $facts );
		$map        = $this->progression->stage_node_map( $workflow_key, $facts );

		if ( null !== $next_stage && isset( $map[ $next_stage ] ) ) {
			return (string) $map[ $next_stage ];
		}

		$trigger = $this->completion_trigger( $workflow_key, $stage_slug, $facts );

		if ( null === $trigger ) {
			return $current_node;
		}

		$advanced = $this->progression->advance(
			$workflow_key,
			$current_node,
			$trigger['kind'],
			$trigger['value'],
			$facts
		);

		return '' !== $advanced ? $advanced : $current_node;
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	private function legacy_flat_context( array $input ): array {
		$workflow = trim( (string) ( $input['workflow'] ?? '' ) );

		if ( '' === $workflow ) {
			return $this->empty_context( array() );
		}

		$definition = $this->catalog->by_key( $workflow );
		$codes      = is_array( $definition ) ? $this->catalog->required_form_codes( $definition ) : array();
		$forms      = array();

		foreach ( $codes as $code ) {
			$forms[] = array(
				'code'         => $code,
				'title'        => $code,
				'purpose'      => '',
				'url'          => $this->form_pages->resolve( $code ),
				'required'     => true,
				'download_url' => $this->form_download_url( $code ),
			);
		}

		return array(
			'forms_visible'  => ! empty( $forms ),
			'current_stage'  => null,
			'stage_forms'    => $forms,
			'future_stages'  => array(),
			'next_action'    => null,
			'stage_download' => array(
				'available'    => ! empty( $this->merged->status( $workflow )['available'] ),
				'download_url' => (string) ( $this->merged->status( $workflow )['download_url'] ?? '' ),
			),
			'procedural_node' => '',
		);
	}

	/**
	 * @param string[] $routing_missing Missing routing keys.
	 * @return array<string, mixed>
	 */
	private function empty_context( array $routing_missing ): array {
		return array(
			'forms_visible'   => false,
			'current_stage'   => null,
			'stage_forms'     => array(),
			'skipped_forms'   => array(),
			'future_stages'   => array(),
			'next_action'     => array(
				'type'    => 'assessment',
				'message' => $this->assessment_message( $routing_missing ),
			),
			'stage_download'  => array(
				'available'    => false,
				'download_url' => '',
			),
			'procedural_node' => '',
			'routing_missing' => array_values( $routing_missing ),
		);
	}

	/**
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $facts    Facts.
	 * @return array<string, mixed>
	 */
	private function pre_commencement_context( string $workflow, array $facts ): array {
		$definition = $this->catalog->by_key( $workflow );
		$title      = is_array( $definition ) ? (string) ( $definition['description'] ?? '' ) : $workflow;

		return array(
			'forms_visible'   => false,
			'current_stage'   => null,
			'stage_forms'     => array(),
			'future_stages'   => array(),
			'next_action'     => array(
				'type'    => 'case_type',
				'message' => sprintf(
					/* translators: %s: workflow description */
					__( 'Your matter matches the %s workflow. Complete the remaining intake questions and I will provide only the forms needed for your first filing step.', 'prose-core' ),
					$title
				),
			),
			'stage_download'  => array(
				'available'    => false,
				'download_url' => '',
			),
			'procedural_node' => '',
		);
	}

	/**
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $input    Input.
	 * @param array<string, mixed> $context  Facts context.
	 * @return string
	 */
	private function resolve_current_node( string $workflow, array $input, array $context ): string {
		$node = trim( (string) ( $input['current_node'] ?? '' ) );

		if ( '' !== $node && ! $this->is_intake_node_slug( $node ) ) {
			return $node;
		}

		$entry = Case_Catalog::entry_node( $workflow, $context );

		return '' !== $entry ? $entry : Vocabulary::NODE_1001_DIVORCE_FILED;
	}

	/**
	 * @param string $slug Node slug from intake UI.
	 * @return bool
	 */
	private function is_intake_node_slug( string $slug ): bool {
		return in_array(
			$slug,
			array(
				'intake_basics',
				'collect_marriage_info',
				'collect_child_information',
				'case_details',
			),
			true
		);
	}

	/**
	 * @param string               $workflow Workflow key.
	 * @param array<string, mixed> $facts    Facts.
	 * @param array<string, mixed> $input    Input.
	 * @return string[]
	 */
	private function resolve_routing_missing( string $workflow, array $facts, array $input ): array {
		if ( ! empty( $input['routing_missing'] ) && is_array( $input['routing_missing'] ) ) {
			return array_values(
				array_map(
					static fn( $key ): string => (string) $key,
					$input['routing_missing']
				)
			);
		}

		if ( '' !== $workflow ) {
			return array();
		}

		$issue = trim( (string) ( $facts['issue'] ?? $input['issue'] ?? 'divorce' ) );

		if ( ! str_contains( $issue, 'divorce' ) ) {
			return array();
		}

		$candidates = array_keys( $this->catalog->by_issue( 'divorce' ) );

		if ( empty( $candidates ) ) {
			foreach ( self::DIVORCE_ROUTING_KEYS as $key ) {
				if ( ! $this->fact_is_set( $facts, $key ) ) {
					return array( $key );
				}
			}

			return array();
		}

		$missing = $this->missing_detector->detect( $candidates, Fact_Store::from_array( $facts ) );

		foreach ( self::DIVORCE_ROUTING_KEYS as $key ) {
			if ( ! $this->fact_is_set( $facts, $key ) && ! in_array( $key, $missing, true ) ) {
				$missing[] = $key;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * @param array<string, mixed> $facts Fact map.
	 * @param string               $key   Fact key.
	 * @return bool
	 */
	private function fact_is_set( array $facts, string $key ): bool {
		if ( ! array_key_exists( $key, $facts ) ) {
			return false;
		}

		$value = $facts[ $key ];

		return null !== $value && '' !== $value;
	}

	/**
	 * @param array<int, array<string, mixed>> $forms Applicable form rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_stage_forms( array $forms ): array {
		$rows = array();

		foreach ( $forms as $form ) {
			$code = (string) ( $form['code'] ?? '' );

			$rows[] = array(
				'code'         => $code,
				'title'        => (string) ( $form['title'] ?? $code ),
				'purpose'      => (string) ( $form['title'] ?? $code ),
				'required'     => ! empty( $form['required'] ),
				'applicable'   => empty( $form['uncertain'] ),
				'uncertain'    => ! empty( $form['uncertain'] ),
				'url'          => $this->form_pages->resolve( $code ),
				'download_url' => $this->form_download_url( $code ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $forms Skipped form rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_skipped_forms( array $forms ): array {
		$rows = array();

		foreach ( $forms as $form ) {
			$code = (string) ( $form['code'] ?? '' );

			$rows[] = array(
				'code'      => $code,
				'title'     => (string) ( $form['title'] ?? $code ),
				'reason'    => (string) ( $form['reason'] ?? '' ),
				'uncertain' => ! empty( $form['uncertain'] ),
			);
		}

		return $rows;
	}

	/**
	 * @param string[]   $stages      All stage slugs.
	 * @param int|false  $stage_index Current stage index.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_future_stages( array $stages, $stage_index ): array {
		$future = array();

		if ( false === $stage_index ) {
			return $future;
		}

		foreach ( $stages as $index => $stage ) {
			if ( $index <= $stage_index ) {
				continue;
			}

			$guidance = $this->guidance->read_stage( (string) $stage );

			$future[] = array(
				'id'     => (string) $stage,
				'title'  => (string) ( $guidance['title'] ?? $this->humanize_stage( (string) $stage ) ),
				'status' => 'locked',
			);
		}

		return $future;
	}

	/**
	 * @param string                    $stage    Stage slug.
	 * @param array<string, mixed>|null $guidance Guidance block.
	 * @param string                    $workflow Workflow key.
	 * @return array{type: string, message: string}
	 */
	private function next_action( string $stage, ?array $guidance, string $workflow ): array {
		$description = is_array( $guidance ) ? trim( (string) ( $guidance['description'] ?? '' ) ) : '';

		if ( '' === $description ) {
			$description = $this->default_stage_message( $stage );
		}

		return array(
			'type'    => 'stage_' . $stage,
			'message' => $description,
		);
	}

	/**
	 * @param string $stage Stage slug.
	 * @return string
	 */
	private function default_stage_message( string $stage ): string {
		switch ( $stage ) {
			case 'commencement':
				return __( 'You must start the divorce case by filing the initial court papers.', 'prose-core' );
			case 'service':
				return __( 'After filing, you must properly serve the court papers on your spouse.', 'prose-core' );
			case 'answer':
				return __( 'Your spouse may file a response. Watch for the response deadline or default procedures.', 'prose-core' );
			case 'calendar':
				return __( 'Prepare the complete final submission package for court review before judgment.', 'prose-core' );
			case 'judgment':
				return __( 'Submit the judgment package for court review and entry.', 'prose-core' );
			default:
				return __( 'Complete the current procedural step before moving forward.', 'prose-core' );
		}
	}

	/**
	 * @param string[] $routing_missing Missing keys.
	 * @return string
	 */
	private function assessment_message( array $routing_missing ): string {
		if ( empty( $routing_missing ) ) {
			return __( 'To determine the correct forms, I need a little more information about your situation.', 'prose-core' );
		}

		return __( 'I can help with the divorce process. To determine the correct forms, I need a little more information about your situation.', 'prose-core' );
	}

	/**
	 * @param string $stage Stage slug.
	 * @return string
	 */
	private function humanize_stage( string $stage ): string {
		return ucwords( str_replace( '_', ' ', $stage ) );
	}

	/**
	 * @param string $code Form code.
	 * @return string
	 */
	private function form_download_url( string $code ): string {
		$code = strtoupper( trim( $code ) );

		if ( '' === $code || ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}

		$path = (string) ( $this->pdf_resolver->resolve( $code )['pdf_path'] ?? '' );

		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		$uploads = wp_upload_dir();

		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		$basedir = wp_normalize_path( (string) $uploads['basedir'] );
		$path    = wp_normalize_path( $path );

		if ( ! str_starts_with( $path, $basedir ) ) {
			return '';
		}

		return (string) $uploads['baseurl'] . substr( $path, strlen( $basedir ) );
	}

	/**
	 * @param string               $workflow_key Workflow key.
	 * @param string               $stage_slug   Stage slug.
	 * @param array<string, mixed> $facts        Facts.
	 * @return string|null
	 */
	private function package_for_stage( string $workflow_key, string $stage_slug, array $facts ): ?string {
		if ( 'commencement' !== $stage_slug ) {
			return null;
		}

		$resolved = ( new Package_Resolver() )->resolve( $workflow_key, $facts );

		if ( is_array( $resolved ) && ! empty( $resolved['id'] ) ) {
			return (string) $resolved['id'];
		}

		$packages = Case_Catalog::initial_packages( $workflow_key, $facts );

		return $packages[0] ?? null;
	}

	/**
	 * @param string $stage_slug Stage slug.
	 * @return string|null
	 */
	private function event_for_stage( string $stage_slug ): ?string {
		switch ( $stage_slug ) {
			case 'service':
				return Case_Catalog::EVENT_SERVICE_COMPLETED;
			case 'answer':
				return Case_Catalog::EVENT_ANSWER_RECEIVED;
			case 'preliminary_conference':
				return Case_Catalog::EVENT_PRELIMINARY_CONFERENCE_HELD;
			case 'discovery':
				return Case_Catalog::EVENT_DISCOVERY_COMPLETE;
			case 'compliance_conference':
				return Case_Catalog::EVENT_COMPLIANCE_CONFERENCE_HELD;
			case 'settlement':
				return Case_Catalog::EVENT_SETTLEMENT_REACHED;
			case 'judgment':
				return Case_Catalog::EVENT_JUDGMENT_ENTERED;
			default:
				return null;
		}
	}
}
