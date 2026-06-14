<?php
/**
 * Form resolver — resolves form codes from a package via the Document Assembly Engine.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural;

use ProSe\Core\Assembly\Package_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Resolver
 */
final class Form_Resolver {

	/**
	 * Package loader.
	 *
	 * @var Package_Loader
	 */
	private Package_Loader $packages;

	/**
	 * Constructor.
	 *
	 * @param Package_Loader|null $packages Package loader.
	 */
	public function __construct( ?Package_Loader $packages = null ) {
		$this->packages = $packages ?? new Package_Loader();
	}

	/**
	 * Resolve form codes for a package enum.
	 *
	 * @param string $package_id Package enum.
	 * @return string[]
	 */
	public function resolve( string $package_id ): array {
		$package = $this->packages->load( $package_id );

		if ( null === $package ) {
			return array();
		}

		$forms   = array();
		$seen    = array();
		$package_forms = is_array( $package['forms'] ?? null ) ? $package['forms'] : array();

		foreach ( $package_forms as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$form_id = trim( (string) ( $row['form_id'] ?? '' ) );

			if ( '' === $form_id || isset( $seen[ $form_id ] ) ) {
				continue;
			}

			$seen[ $form_id ] = true;
			$forms[]          = $form_id;
		}

		return $forms;
	}

	/**
	 * Load the normalized package definition.
	 *
	 * @param string $package_id Package enum.
	 * @return array<string, mixed>|null
	 */
	public function load_package( string $package_id ): ?array {
		return $this->packages->load( $package_id );
	}
}
