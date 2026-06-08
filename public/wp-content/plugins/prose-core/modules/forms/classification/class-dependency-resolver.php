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
	 * Form code => required companion form codes.
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
	 * Resolve dependencies for a form code.
	 *
	 * @param string $form_code Form code (e.g. UD-1).
	 * @return string[]
	 */
	public function resolve( string $form_code ): array {
		$form_code = strtoupper( trim( $form_code ) );

		$deps = self::DEPENDENCIES[ $form_code ] ?? array();

		/**
		 * Filter form dependency list.
		 *
		 * @param string[] $deps      Dependency form codes.
		 * @param string   $form_code Current form code.
		 */
		return apply_filters( 'prose_core_form_dependencies', $deps, $form_code );
	}
}
