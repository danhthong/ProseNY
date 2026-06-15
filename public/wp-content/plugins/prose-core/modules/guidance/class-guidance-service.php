<?php
/**
 * Guidance Service — facade for guidance generation, coverage, and validation.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Guidance_Service
 */
final class Guidance_Service {

	/**
	 * Guidance engine.
	 *
	 * @var Guidance_Engine
	 */
	private Guidance_Engine $engine;

	/**
	 * Guidance repository.
	 *
	 * @var Guidance_Repository
	 */
	private Guidance_Repository $repository;

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $catalog;

	/**
	 * Validator.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Constructor.
	 *
	 * @param Guidance_Engine|null      $engine     Guidance engine.
	 * @param Guidance_Repository|null  $repository Guidance repository.
	 * @param Workflow_Catalog|null     $catalog    Workflow catalog.
	 * @param Validator|null            $validator  Validator.
	 */
	public function __construct(
		?Guidance_Engine $engine = null,
		?Guidance_Repository $repository = null,
		?Workflow_Catalog $catalog = null,
		?Validator $validator = null
	) {
		$this->repository = $repository ?? new Guidance_Repository();
		$this->catalog    = $catalog ?? new Workflow_Catalog();
		$this->validator  = $validator ?? new Validator();
		$this->engine     = $engine ?? new Guidance_Engine(
			new Step_Resolver( $this->catalog, $this->repository, $this->validator ),
			new County_Guidance_Resolver( $this->repository, $this->validator ),
			$this->validator
		);
	}

	/**
	 * Get procedural guidance for a workflow.
	 *
	 * @param string $workflow_key Workflow catalog key.
	 * @param string $county       Optional county name.
	 * @return array<string, mixed>
	 */
	public function get_guidance( string $workflow_key, string $county = '' ): array {
		$result = $this->engine->generate( $workflow_key, $county );

		if ( isset( $result['success'] ) && false === $result['success'] ) {
			return $result;
		}

		return array(
			'success'  => true,
			'guidance' => $result,
		);
	}

	/**
	 * Calculate guidance coverage across all workflows.
	 *
	 * @return array<string, mixed>
	 */
	public function coverage(): array {
		$all_workflows = $this->catalog->all();
		$stage_ids     = array();
		$missing       = array();

		foreach ( $all_workflows as $workflow_key => $definition ) {
			$stages = is_array( $definition['stages'] ?? null ) ? $definition['stages'] : array();

			foreach ( $stages as $stage ) {
				$stage_id = trim( (string) $stage );

				if ( '' === $stage_id ) {
					continue;
				}

				$stage_ids[ $stage_id ] = true;

				if ( ! $this->repository->stage_file_exists( $stage_id ) ) {
					$missing[ $stage_id ] = true;
				}
			}
		}

		$total_stages    = count( $stage_ids );
		$missing_stages  = count( $missing );
		$covered_stages  = max( 0, $total_stages - $missing_stages );
		$coverage_percent = 0;

		if ( $total_stages > 0 ) {
			$coverage_percent = (int) round( ( $covered_stages / $total_stages ) * 100 );
		}

		return array(
			'workflow_count'    => count( $all_workflows ),
			'stage_count'       => $total_stages,
			'covered_stages'    => $covered_stages,
			'missing_stages'    => $missing_stages,
			'coverage_percent'  => $coverage_percent,
			'missing_stage_ids' => array_keys( $missing ),
		);
	}

	/**
	 * Validate all workflow stage guidance.
	 *
	 * @return array<string, mixed>
	 */
	public function validate_all(): array {
		$all_workflows = $this->catalog->all();
		$warnings      = array();
		$errors        = array();
		$stage_ids     = array();

		foreach ( $all_workflows as $workflow_key => $definition ) {
			$stages = is_array( $definition['stages'] ?? null ) ? $definition['stages'] : array();

			foreach ( $stages as $stage ) {
				$stage_id = trim( (string) $stage );

				if ( '' === $stage_id ) {
					continue;
				}

				$stage_ids[ $stage_id ] = true;
				$raw                    = $this->repository->read_stage_raw( $stage_id );

				if ( null === $raw ) {
					$warnings[] = $this->validator->warning(
						Validator::WARN_GUIDANCE_MISSING,
						array(
							'stage'    => $stage_id,
							'workflow' => $workflow_key,
						)
					);
					continue;
				}

				$normalized = $this->repository->normalize_stage( $stage_id, $raw );
				$warnings   = array_merge(
					$warnings,
					$this->validator->validate_stage_guidance( $stage_id, $normalized )
				);
			}
		}

		foreach ( $this->repository->list_stage_keys() as $stage_key ) {
			if ( ! isset( $stage_ids[ $stage_key ] ) ) {
				$errors[] = array(
					'code'    => Validator::CODE_STAGE_NOT_FOUND,
					'stage'   => $stage_key,
					'message' => sprintf(
						/* translators: %s: stage id */
						__( 'Guidance file exists for unknown stage: %s', 'prose-core' ),
						$stage_key
					),
				);
			}
		}

		return array(
			'success'  => empty( $errors ),
			'warnings' => $warnings,
			'errors'   => $errors,
		);
	}

	/**
	 * Repository accessor.
	 *
	 * @return Guidance_Repository
	 */
	public function get_repository(): Guidance_Repository {
		return $this->repository;
	}
}
