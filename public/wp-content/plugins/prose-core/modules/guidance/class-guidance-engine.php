<?php
/**
 * Guidance Engine — convert workflow stages into procedural guidance.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Guidance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Guidance_Engine
 */
final class Guidance_Engine {

	/**
	 * Step resolver.
	 *
	 * @var Step_Resolver
	 */
	private Step_Resolver $step_resolver;

	/**
	 * County guidance resolver.
	 *
	 * @var County_Guidance_Resolver
	 */
	private County_Guidance_Resolver $county_resolver;

	/**
	 * Validator.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Constructor.
	 *
	 * @param Step_Resolver|null            $step_resolver   Step resolver.
	 * @param County_Guidance_Resolver|null $county_resolver County resolver.
	 * @param Validator|null                $validator       Validator.
	 */
	public function __construct(
		?Step_Resolver $step_resolver = null,
		?County_Guidance_Resolver $county_resolver = null,
		?Validator $validator = null
	) {
		$this->step_resolver   = $step_resolver ?? new Step_Resolver();
		$this->county_resolver = $county_resolver ?? new County_Guidance_Resolver();
		$this->validator       = $validator ?? new Validator();
	}

	/**
	 * Generate procedural guidance for a workflow.
	 *
	 * @param string $workflow_key Workflow catalog key.
	 * @param string $county       Optional county name.
	 * @return array<string, mixed>
	 */
	public function generate( string $workflow_key, string $county = '' ): array {
		$workflow_key = trim( $workflow_key );

		if ( '' === $workflow_key ) {
			return $this->validator->failure(
				Validator::CODE_WORKFLOW_NOT_FOUND,
				__( 'Workflow key is required.', 'prose-core' )
			);
		}

		$steps_result = $this->step_resolver->resolve( $workflow_key );

		if ( null !== $steps_result['error'] ) {
			return $this->validator->failure(
				(string) $steps_result['error']['code'],
				(string) $steps_result['error']['message']
			);
		}

		$county_result = $this->county_resolver->resolve( $county );
		$warnings      = array_merge( $steps_result['warnings'], $county_result['warnings'] );

		return array(
			'workflow'        => $workflow_key,
			'steps'           => $steps_result['steps'],
			'county_guidance' => $county_result['county_guidance'],
			'warnings'        => $warnings,
		);
	}
}
