<?php
/**
 * Detect package IDs for a form from case type and form code.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Detector
 */
final class Package_Detector {

	/**
	 * Form code => package IDs.
	 *
	 * @var array<string, string[]>
	 */
	private const FORM_CODE_PACKAGES = array(
		'UD-1'  => array( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, Vocabulary::PKG_CONTESTED_COMMENCEMENT ),
		'UD-2'  => array( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, Vocabulary::PKG_CONTESTED_COMMENCEMENT ),
		'UD-3'  => array( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, Vocabulary::PKG_CONTESTED_COMMENCEMENT ),
		'UD-4'  => array( Vocabulary::PKG_UNCONTESTED_NO_CHILDREN, Vocabulary::PKG_CONTESTED_COMMENCEMENT ),
		'UD-5'  => array( Vocabulary::PKG_CONTESTED_COMMENCEMENT ),
		'UD-6'  => array( Vocabulary::PKG_JUDGMENT, Vocabulary::PKG_DEFAULT_DIVORCE ),
		'UD-7'  => array( Vocabulary::PKG_JUDGMENT, Vocabulary::PKG_DEFAULT_DIVORCE ),
		'UD-8'  => array( Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN ),
		'UD-11' => array( Vocabulary::PKG_MODIFICATION ),
		'UD-12' => array( Vocabulary::PKG_MODIFICATION ),
		'RJI'   => array( Vocabulary::PKG_MOTION, Vocabulary::PKG_CONTESTED_COMMENCEMENT ),
		'NOI'   => array( Vocabulary::PKG_JUDGMENT ),
	);

	/**
	 * Stage => additional package.
	 *
	 * @var array<string, string>
	 */
	private const STAGE_PACKAGES = array(
		'Discovery'  => Vocabulary::PKG_DISCOVERY,
		'Settlement' => Vocabulary::PKG_SETTLEMENT,
		'Trial'      => Vocabulary::PKG_TRIAL,
		'Judgment'   => Vocabulary::PKG_JUDGMENT,
		'Enforcement' => Vocabulary::PKG_ENFORCEMENT,
		'Modification' => Vocabulary::PKG_MODIFICATION,
	);

	/**
	 * Detect package IDs.
	 *
	 * @param array<string, mixed> $ctx Context (case_type, form_code, workflow_stage, text).
	 * @return string[]
	 */
	public function detect( array $ctx ): array {
		$case_type = (string) ( $ctx['case_type'] ?? '' );
		$form_code = strtoupper( trim( (string) ( $ctx['form_code'] ?? '' ) ) );
		$stage     = (string) ( $ctx['workflow_stage'] ?? '' );
		$text      = strtoupper( (string) ( $ctx['text'] ?? '' ) . ' ' . (string) ( $ctx['title'] ?? '' ) );

		$packages = Vocabulary::packages_for_case_type( $case_type );

		if ( isset( self::FORM_CODE_PACKAGES[ $form_code ] ) ) {
			$packages = array_merge( $packages, self::FORM_CODE_PACKAGES[ $form_code ] );
		}

		if ( isset( self::STAGE_PACKAGES[ $stage ] ) ) {
			$packages[] = self::STAGE_PACKAGES[ $stage ];
		}

		if ( str_contains( $text, 'DEFAULT' ) ) {
			$packages[] = Vocabulary::PKG_DEFAULT_DIVORCE;
		}

		if ( str_contains( $text, 'ORDER OF PROTECTION' ) || str_contains( $text, 'FAMILY OFFENSE' ) ) {
			$packages[] = Vocabulary::PKG_ORDER_OF_PROTECTION;
		}

		if ( preg_match( '/^FC-3/', $form_code ) || str_contains( $text, 'CUSTODY' ) ) {
			$packages[] = Vocabulary::PKG_CUSTODY_PETITION;
		}

		if ( preg_match( '/^FC-2/', $form_code ) || preg_match( '/^4-/', $form_code ) ) {
			$packages[] = Vocabulary::PKG_CHILD_SUPPORT_PETITION;
		}

		$packages = array_values( array_unique( $packages ) );

		/**
		 * Filter detected package IDs.
		 *
		 * @param string[]             $packages Package enum values.
		 * @param array<string, mixed> $ctx      Context.
		 */
		return apply_filters( 'prose_core_package_ids', $packages, $ctx );
	}

	/**
	 * Get package catalog for seeding.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_catalog(): array {
		return Vocabulary::package_catalog();
	}
}
