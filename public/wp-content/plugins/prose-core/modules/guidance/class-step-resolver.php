<?php
/**
 * Step Resolver — enrich workflow stages with procedural guidance.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Step_Resolver
 */
final class Step_Resolver {

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Guidance repository.
	 *
	 * @var Guidance_Repository
	 */
	private Guidance_Repository $repository;

	/**
	 * Validator.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null    $catalog    Workflow catalog.
	 * @param Guidance_Repository|null $repository Guidance repository.
	 * @param Validator|null           $validator  Validator.
	 */
	public function __construct(
		?Workflow_Catalog $catalog = null,
		?Guidance_Repository $repository = null,
		?Validator $validator = null
	) {
		$this->catalog    = $catalog ?? new Workflow_Catalog();
		$this->repository = $repository ?? new Guidance_Repository();
		$this->validator  = $validator ?? new Validator();
	}

	/**
	 * Resolve ordered guidance steps for a workflow.
	 *
	 * @param string $workflow_key Workflow catalog key.
	 * @return array{steps: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>, error: array<string, mixed>|null}
	 */
	public function resolve( string $workflow_key ): array {
		$definition = $this->catalog->by_key( $workflow_key );

		if ( null === $definition ) {
			return array(
				'steps'    => array(),
				'warnings' => array(),
				'error'    => array(
					'code'    => Validator::CODE_WORKFLOW_NOT_FOUND,
					'message' => sprintf(
						/* translators: %s: workflow key */
						__( 'Workflow not found: %s', 'prose-core' ),
						$workflow_key
					),
				),
			);
		}

		$stages   = is_array( $definition['stages'] ?? null ) ? $definition['stages'] : array();
		$steps    = array();
		$warnings = array();
		$order    = 1;

		foreach ( $stages as $stage ) {
			$stage_id = trim( (string) $stage );

			if ( '' === $stage_id ) {
				continue;
			}

			$raw      = $this->repository->read_stage_raw( $stage_id );
			$step     = $this->build_step( $stage_id, $raw, $order );
			$warnings = array_merge( $warnings, $step['warnings'] );
			$steps[]  = $step['step'];
			++$order;
		}

		return array(
			'steps'    => $steps,
			'warnings' => $warnings,
			'error'    => null,
		);
	}

	/**
	 * Build a single step with guidance enrichment.
	 *
	 * @param string                    $stage_id Stage identifier.
	 * @param array<string, mixed>|null $raw      Raw guidance data.
	 * @param int                       $order    Step order.
	 * @return array{step: array<string, mixed>, warnings: array<int, array<string, mixed>>}
	 */
	private function build_step( string $stage_id, ?array $raw, int $order ): array {
		$warnings = array();

		if ( null === $raw ) {
			$warnings[] = $this->validator->warning(
				$this->repository->stage_file_exists( $stage_id )
					? Validator::WARN_MALFORMED_GUIDANCE_FILE
					: Validator::WARN_GUIDANCE_MISSING,
				array( 'stage' => $stage_id )
			);

			return array(
				'step' => array(
					'order'          => $order,
					'id'             => $stage_id,
					'title'          => $this->stage_title( $stage_id ),
					'description'    => '',
					'tips'           => array(),
					'warnings'       => array(),
					'related_forms'  => array(),
					'resources'      => array(),
					'estimated_time' => null,
				),
				'warnings' => $warnings,
			);
		}

		if ( ! is_array( $raw ) ) {
			$warnings[] = $this->validator->warning(
				Validator::WARN_MALFORMED_GUIDANCE_FILE,
				array( 'stage' => $stage_id )
			);

			return array(
				'step' => array(
					'order'          => $order,
					'id'             => $stage_id,
					'title'          => $this->stage_title( $stage_id ),
					'description'    => '',
					'tips'           => array(),
					'warnings'       => array(),
					'related_forms'  => array(),
					'resources'      => array(),
					'estimated_time' => null,
				),
				'warnings' => $warnings,
			);
		}

		$normalized = $this->repository->normalize_stage( $stage_id, $raw );
		$warnings   = array_merge( $warnings, $this->validator->validate_stage_guidance( $stage_id, $normalized ) );

		if ( '' === $normalized['title'] ) {
			$normalized['title'] = $this->stage_title( $stage_id );
		}

		$step = array_merge(
			array( 'order' => $order ),
			$normalized
		);

		return array(
			'step'     => $step,
			'warnings' => $warnings,
		);
	}

	/**
	 * Convert a stage slug to a human-readable title.
	 *
	 * @param string $stage_id Stage slug.
	 * @return string
	 */
	private function stage_title( string $stage_id ): string {
		$words = explode( '_', $stage_id );
		$words = array_map(
			static function ( string $word ): string {
				return ucfirst( strtolower( $word ) );
			},
			$words
		);

		return implode( ' ', $words );
	}
}
