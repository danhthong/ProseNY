<?php
/**
 * Resolve form dependency relationships by form code.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Dependency_Resolver
 */
final class Dependency_Resolver {

	/**
	 * Form code => required companion form codes (legacy flat list).
	 *
	 * @var array<string, string[]>
	 */
	private const DEPENDENCIES = array(
		'UD-1'  => array( 'UD-2' ),
		'UD-3'  => array( 'UD-4' ),
		'UD-5'  => array( 'UD-6' ),
		'UD-7'  => array( 'UD-8' ),
		'UD-11' => array( 'UD-12' ),
	);

	/**
	 * Form code => forms required before filing.
	 *
	 * @var array<string, string[]>
	 */
	private const REQUIRED_BEFORE = array(
		'UD-2'  => array( 'UD-1' ),
		'UD-3'  => array( 'UD-1', 'UD-2' ),
		'UD-4'  => array( 'UD-3' ),
		'UD-5'  => array( 'UD-1', 'UD-2' ),
		'UD-6'  => array( 'UD-3', 'UD-4' ),
		'UD-7'  => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6' ),
		'UD-8'  => array( 'UD-1', 'UD-2' ),
		'UD-11' => array( 'UD-7' ),
		'UD-12' => array( 'UD-11' ),
		'RJI'   => array( 'UD-1', 'UD-2' ),
		'NOI'   => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4' ),
	);

	/**
	 * Form code => forms typically filed after.
	 *
	 * @var array<string, string[]>
	 */
	private const REQUIRED_AFTER = array(
		'UD-1'  => array( 'UD-2', 'UD-3', 'UD-4' ),
		'UD-2'  => array( 'UD-3', 'UD-4' ),
		'UD-3'  => array( 'UD-6', 'UD-7' ),
		'UD-4'  => array( 'UD-6', 'UD-7' ),
		'UD-5'  => array( 'UD-6' ),
		'UD-6'  => array( 'UD-7' ),
		'UD-7'  => array( 'UD-11' ),
		'UD-11' => array( 'UD-12' ),
	);

	/**
	 * Form code => prerequisite forms.
	 *
	 * @var array<string, string[]>
	 */
	private const PREREQUISITE_FORMS = array(
		'RJI' => array( 'UD-1', 'UD-2', 'AFFIDAVIT_OF_SERVICE' ),
		'NOI' => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'AFFIDAVIT_OF_SERVICE' ),
		'UD-7' => array( 'FINDINGS_OF_FACT', 'NOI', 'AFFIDAVIT_OF_REGULARITY' ),
		'UD-6' => array( 'UD-3', 'UD-4' ),
	);

	/**
	 * Form code => dependent forms.
	 *
	 * @var array<string, string[]>
	 */
	private const DEPENDENT_FORMS = array(
		'UD-1' => array( 'UD-2', 'UD-3', 'UD-4', 'RJI' ),
		'UD-2' => array( 'UD-3', 'UD-4', 'UD-5', 'RJI' ),
		'UD-3' => array( 'UD-6', 'UD-7' ),
		'UD-4' => array( 'UD-6', 'UD-7' ),
		'UD-7' => array( 'UD-11', 'UD-12' ),
	);

	/**
	 * Form code => related forms.
	 *
	 * @var array<string, string[]>
	 */
	private const RELATED_FORMS = array(
		'UD-1' => array( 'UD-2', 'UD-3', 'UD-4', 'UD-5', 'UD-6', 'UD-7' ),
		'UD-7' => array( 'UD-6', 'NOI', 'RJI' ),
		'RJI'  => array( 'NOI', 'UD-7' ),
	);

	/**
	 * Form code => package dependencies.
	 *
	 * @var array<string, string[]>
	 */
	private const PACKAGE_DEPENDENCIES = array(
		'RJI' => array( Vocabulary::PKG_CONTESTED_COMMENCEMENT ),
		'NOI' => array( Vocabulary::PKG_JUDGMENT ),
		'UD-7' => array( Vocabulary::PKG_JUDGMENT ),
	);

	/**
	 * Form code => workflow dependencies.
	 *
	 * @var array<string, string[]>
	 */
	private const WORKFLOW_DEPENDENCIES = array(
		'UD-7' => array( Vocabulary::WF_UNCONTESTED_DIVORCE, Vocabulary::WF_CONTESTED_DIVORCE ),
		'RJI'  => array( Vocabulary::WF_CONTESTED_DIVORCE, Vocabulary::WF_MOTION_PRACTICE ),
	);

	/**
	 * Resolve dependencies for a form code (legacy flat list).
	 *
	 * @param string $form_code Form code (e.g. UD-1).
	 * @return string[]
	 */
	public function resolve( string $form_code ): array {
		$full = $this->resolve_full( $form_code );

		return $full['dependencies'];
	}

	/**
	 * Resolve full dependency graph for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return array{
	 *     dependencies: string[],
	 *     required_before: string[],
	 *     required_after: string[],
	 *     prerequisite_forms: string[],
	 *     dependent_forms: string[],
	 *     related_forms: string[],
	 *     package_dependencies: string[],
	 *     workflow_dependencies: string[]
	 * }
	 */
	public function resolve_full( string $form_code ): array {
		$form_code = strtoupper( trim( $form_code ) );

		$result = array(
			'dependencies'          => self::DEPENDENCIES[ $form_code ] ?? array(),
			'required_before'       => self::REQUIRED_BEFORE[ $form_code ] ?? array(),
			'required_after'        => self::REQUIRED_AFTER[ $form_code ] ?? array(),
			'prerequisite_forms'    => self::PREREQUISITE_FORMS[ $form_code ] ?? array(),
			'dependent_forms'       => self::DEPENDENT_FORMS[ $form_code ] ?? array(),
			'related_forms'         => self::RELATED_FORMS[ $form_code ] ?? array(),
			'package_dependencies'  => self::PACKAGE_DEPENDENCIES[ $form_code ] ?? array(),
			'workflow_dependencies' => self::WORKFLOW_DEPENDENCIES[ $form_code ] ?? array(),
		);

		/**
		 * Filter full dependency resolution.
		 *
		 * @param array<string, string[]> $result    Dependency arrays.
		 * @param string                  $form_code Form code.
		 */
		return apply_filters( 'prose_core_form_dependencies_full', $result, $form_code );
	}
}
