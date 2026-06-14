<?php
/**
 * Package loader — resolves package definitions from the package catalog.
 *
 * Package Builder / Package_Repository remains the source of truth. This loader
 * reads via Package_Object_Builder when WordPress is available, otherwise falls
 * back to the static Vocabulary catalog (unit tests, CLI).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Assembly;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Package_Object_Builder;
use ProSe\Core\Forms\Package_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Loader
 */
class Package_Loader {

	/**
	 * Package object builder.
	 *
	 * @var Package_Object_Builder|null
	 */
	private ?Package_Object_Builder $object_builder;

	/**
	 * Constructor.
	 *
	 * @param Package_Object_Builder|null $object_builder Optional builder override.
	 */
	public function __construct( ?Package_Object_Builder $object_builder = null ) {
		$this->object_builder = $object_builder;
	}

	/**
	 * Load a normalized package definition by enum ID.
	 *
	 * @param string $package_id Package enum (e.g. PKG_UNCONTESTED_WITH_CHILDREN).
	 * @return array<string, mixed>|null
	 */
	public function load( string $package_id ): ?array {
		$package_id = trim( $package_id );

		if ( '' === $package_id ) {
			return null;
		}

		$definition = $this->load_from_repository( $package_id );

		if ( null !== $definition ) {
			return $definition;
		}

		return $this->load_from_catalog( $package_id );
	}

	/**
	 * Load from WordPress package repository when available.
	 *
	 * @param string $package_id Package enum.
	 * @return array<string, mixed>|null
	 */
	private function load_from_repository( string $package_id ): ?array {
		if ( ! function_exists( 'get_post' ) ) {
			return null;
		}

		$builder = $this->object_builder ?? new Package_Object_Builder( new Package_Repository() );
		$object  = $builder->build_by_package_id( $package_id );

		if ( empty( $object['package_id'] ) ) {
			return null;
		}

		return $this->normalize( $object );
	}

	/**
	 * Load from the static vocabulary catalog.
	 *
	 * @param string $package_id Package enum.
	 * @return array<string, mixed>|null
	 */
	private function load_from_catalog( string $package_id ): ?array {
		$catalog = Vocabulary::package_catalog();

		if ( ! isset( $catalog[ $package_id ] ) ) {
			return null;
		}

		$row = $catalog[ $package_id ];
		$row['package_id'] = $package_id;

		return $this->normalize( $row );
	}

	/**
	 * Normalize a raw package row into the assembly shape.
	 *
	 * @param array<string, mixed> $raw Raw package data.
	 * @return array<string, mixed>
	 */
	private function normalize( array $raw ): array {
		$forms = array();

		foreach ( (array) ( $raw['required_forms'] ?? array() ) as $code ) {
			$form_id = $this->normalize_form_code( (string) $code );

			if ( '' === $form_id ) {
				continue;
			}

			$forms[] = array(
				'form_id'  => $form_id,
				'required' => true,
			);
		}

		foreach ( (array) ( $raw['optional_forms'] ?? array() ) as $code ) {
			$form_id = $this->normalize_form_code( (string) $code );

			if ( '' === $form_id ) {
				continue;
			}

			$forms[] = array(
				'form_id'  => $form_id,
				'required' => false,
			);
		}

		foreach ( (array) ( $raw['supporting_documents'] ?? array() ) as $code ) {
			$form_id = $this->normalize_form_code( (string) $code );

			if ( '' === $form_id ) {
				continue;
			}

			$forms[] = array(
				'form_id'  => $form_id,
				'required' => false,
			);
		}

		return array(
			'package_id'   => (string) ( $raw['package_id'] ?? '' ),
			'package_name' => (string) ( $raw['package_name'] ?? $raw['package_id'] ?? '' ),
			'court'        => (string) ( $raw['court'] ?? '' ),
			'forms'        => $forms,
		);
	}

	/**
	 * Normalize a form code to canonical uppercase form.
	 *
	 * @param string $code Form code.
	 * @return string
	 */
	private function normalize_form_code( string $code ): string {
		return strtoupper( trim( $code ) );
	}
}
