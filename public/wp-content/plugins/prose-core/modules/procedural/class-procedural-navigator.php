<?php
/**
 * Procedural Navigator — orchestrates court, workflow, package, forms, and guidance.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Procedural_Navigator
 */
final class Procedural_Navigator {

	/**
	 * Court label map (presentation only).
	 *
	 * @var array<string, string>
	 */
	private const COURT_LABELS = array(
		'supreme_court' => 'Supreme Court',
		'family_court'  => 'Family Court',
	);

	/**
	 * Validator.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Workflow resolver.
	 *
	 * @var Workflow_Resolver
	 */
	private Workflow_Resolver $workflows;

	/**
	 * Package resolver.
	 *
	 * @var Package_Resolver
	 */
	private Package_Resolver $packages;

	/**
	 * Form resolver.
	 *
	 * @var Form_Resolver
	 */
	private Form_Resolver $forms;

	/**
	 * Guidance resolver.
	 *
	 * @var Guidance_Resolver
	 */
	private Guidance_Resolver $guidance;

	/**
	 * Constructor.
	 *
	 * @param Validator|null         $validator Validator.
	 * @param Workflow_Resolver|null $workflows Workflow resolver.
	 * @param Package_Resolver|null  $packages  Package resolver.
	 * @param Form_Resolver|null     $forms     Form resolver.
	 * @param Guidance_Resolver|null $guidance  Guidance resolver.
	 */
	public function __construct(
		?Validator $validator = null,
		?Workflow_Resolver $workflows = null,
		?Package_Resolver $packages = null,
		?Form_Resolver $forms = null,
		?Guidance_Resolver $guidance = null
	) {
		$this->validator = $validator ?? new Validator();
		$this->workflows = $workflows ?? new Workflow_Resolver();
		$this->packages  = $packages ?? new Package_Resolver();
		$this->forms     = $forms ?? new Form_Resolver();
		$this->guidance  = $guidance ?? new Guidance_Resolver();
	}

	/**
	 * Navigate intake data into court, workflow, package, forms, and guidance.
	 *
	 * @param array<string, mixed> $intake Intake payload.
	 * @return array<string, mixed>
	 */
	public function navigate( array $intake ): array {
		$intake_check = $this->validator->validate_intake( $intake );

		if ( empty( $intake_check['valid'] ) ) {
			return $this->validator->failure( (array) ( $intake_check['error'] ?? array() ) );
		}

		$issue           = trim( (string) ( $intake['issue'] ?? '' ) );
		$facts           = is_array( $intake['facts'] ?? null ) ? $intake['facts'] : array();
		$preset_workflow = isset( $intake['workflow'] ) ? trim( (string) $intake['workflow'] ) : null;
		$county          = $this->resolve_county( $intake, $facts );

		$resolved = $this->workflows->resolve( $issue, $facts, $preset_workflow );

		$issue_check = $this->validator->validate_issue( $resolved['issue'] ?? null );

		if ( empty( $issue_check['valid'] ) ) {
			return $this->validator->failure( (array) ( $issue_check['error'] ?? array() ) );
		}

		$court_check = $this->validator->validate_court( $resolved['court'] ?? null );

		if ( empty( $court_check['valid'] ) ) {
			return $this->validator->failure( (array) ( $court_check['error'] ?? array() ) );
		}

		$workflow_check = $this->validator->validate_workflow(
			$resolved['workflow'] ?? null,
			$resolved['definition'] ?? null
		);

		if ( empty( $workflow_check['valid'] ) ) {
			return $this->validator->failure( (array) ( $workflow_check['error'] ?? array() ) );
		}

		$workflow_key = (string) $resolved['workflow'];
		$definition   = (array) $resolved['definition'];
		$court_id     = (string) $resolved['court'];
		$court_label  = $this->court_label( $court_id );

		$package = $this->packages->resolve( $workflow_key, $facts );
		$package_check = $this->validator->validate_package( $package['id'] ?? null );

		if ( empty( $package_check['valid'] ) ) {
			return $this->validator->failure( (array) ( $package_check['error'] ?? array() ) );
		}

		$package_id = (string) $package['id'];
		$form_codes = $this->forms->resolve( $package_id );

		$forms_check = $this->validator->validate_forms( $package_id, $form_codes );

		if ( empty( $forms_check['valid'] ) ) {
			return $this->validator->failure( (array) ( $forms_check['error'] ?? array() ) );
		}

		$workflow_enum = $this->packages->workflow_enum( $definition );
		$package_row   = $this->packages->package_row( $package_id );

		if ( is_array( $package_row ) ) {
			$match_check = $this->validator->validate_workflow_package_match(
				$package_id,
				$workflow_enum,
				$package_row
			);

			if ( empty( $match_check['valid'] ) ) {
				return $this->validator->failure( (array) ( $match_check['error'] ?? array() ) );
			}
		}

		return array(
			'success'    => true,
			'navigation' => array(
				'court'        => array(
					'id'    => $court_id,
					'label' => $court_label,
				),
				'workflow'     => array(
					'id' => $workflow_key,
				),
				'package'      => array(
					'id' => $package_id,
				),
				'forms'        => $form_codes,
				'next_steps'   => $this->guidance->next_steps( $definition ),
				'instructions' => $this->guidance->instructions( $county, $court_label ),
			),
		);
	}

	/**
	 * Resolve county from intake payload or facts.
	 *
	 * @param array<string, mixed> $intake Intake payload.
	 * @param array<string, mixed> $facts  Intake facts.
	 * @return string
	 */
	private function resolve_county( array $intake, array $facts ): string {
		if ( isset( $intake['county'] ) && is_string( $intake['county'] ) && '' !== trim( $intake['county'] ) ) {
			return trim( $intake['county'] );
		}

		if ( isset( $facts['county'] ) && is_string( $facts['county'] ) && '' !== trim( (string) $facts['county'] ) ) {
			return trim( (string) $facts['county'] );
		}

		return '';
	}

	/**
	 * Map a court id to a human-readable label.
	 *
	 * @param string $court_id Court id.
	 * @return string
	 */
	private function court_label( string $court_id ): string {
		if ( isset( self::COURT_LABELS[ $court_id ] ) ) {
			return self::COURT_LABELS[ $court_id ];
		}

		$words = explode( '_', $court_id );
		$words = array_map(
			static function ( string $word ): string {
				return ucfirst( strtolower( $word ) );
			},
			$words
		);

		return implode( ' ', $words );
	}
}
