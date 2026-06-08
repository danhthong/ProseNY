<?php
/**
 * Build workflow package metadata from case type.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Classification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Workflow_Package_Builder
 */
final class Workflow_Package_Builder {

	/**
	 * Case type => required form codes in a filing packet.
	 *
	 * @var array<string, string[]>
	 */
	private const PACKAGES = array(
		'Uncontested Divorce'      => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7' ),
		'Contested Divorce'        => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-5', 'UD-6', 'UD-7' ),
		'Divorce With Children'    => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7', 'UD-8' ),
		'Divorce Without Children' => array( 'UD-1', 'UD-2', 'UD-3', 'UD-4', 'UD-6', 'UD-7' ),
		'Post Divorce'             => array( 'UD-11', 'UD-12' ),
		'Child Support'            => array( 'FC-1', 'FC-2' ),
		'Child Custody'            => array( 'FC-1', 'FC-3' ),
		'Visitation'               => array( 'FC-1', 'FC-4' ),
		'Paternity'                => array( 'FC-1', 'FC-5' ),
		'Family Offense'           => array( 'FC-1', 'FC-6' ),
		'Orders of Protection'     => array( 'FC-1', 'FC-7' ),
	);

	/**
	 * Build workflow package for a case type.
	 *
	 * @param string $case_type Detected case type.
	 * @param string $form_code Current form code (included if not in package).
	 * @return string[]
	 */
	public function build( string $case_type, string $form_code = '' ): array {
		$package = self::PACKAGES[ $case_type ] ?? array();

		if ( '' !== $form_code ) {
			$form_code = strtoupper( trim( $form_code ) );

			if ( '' !== $form_code && ! in_array( $form_code, $package, true ) ) {
				array_unshift( $package, $form_code );
			}
		}

		/**
		 * Filter workflow package form codes.
		 *
		 * @param string[] $package   Form codes in the packet.
		 * @param string   $case_type Case type.
		 * @param string   $form_code Current form code.
		 */
		return apply_filters( 'prose_core_workflow_packages', $package, $case_type, $form_code );
	}
}
