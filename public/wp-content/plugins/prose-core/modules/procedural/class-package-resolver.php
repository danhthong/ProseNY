<?php
/**
 * Package resolver — bridges workflow keys to PKG_* enums.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Procedural;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Engine\Package_Resolver as Engine_Package_Resolver;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Resolver
 */
final class Package_Resolver {

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Engine package resolver.
	 *
	 * @var Engine_Package_Resolver
	 */
	private Engine_Package_Resolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null         $workflows Workflow catalog.
	 * @param Engine_Package_Resolver|null $resolver  Engine package resolver.
	 */
	public function __construct( ?Workflow_Catalog $workflows = null, ?Engine_Package_Resolver $resolver = null ) {
		$this->workflows = $workflows ?? new Workflow_Catalog();
		$this->resolver  = $resolver ?? new Engine_Package_Resolver();
	}

	/**
	 * Resolve a package enum for a workflow key and facts.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param array<string, mixed> $facts        Intake facts.
	 * @return array{id: string}|null
	 */
	public function resolve( string $workflow_key, array $facts = array() ): ?array {
		$definition = $this->workflows->by_key( $workflow_key );

		if ( null === $definition ) {
			return null;
		}

		$workflow_enum = $this->workflow_enum( $definition );
		$result        = $this->resolver->resolve( $workflow_enum, $facts );
		$packages      = is_array( $result['available_packages'] ?? null ) ? $result['available_packages'] : array();

		if ( empty( $packages ) ) {
			return null;
		}

		return array(
			'id' => (string) $packages[0],
		);
	}

	/**
	 * Resolve the workflow enum used for package lookup.
	 *
	 * @param array<string, mixed> $definition Workflow definition.
	 * @return string
	 */
	public function workflow_enum( array $definition ): string {
		$internal = is_array( $definition['internal'] ?? null ) ? $definition['internal'] : array();

		if ( ! empty( $internal['workflow_enum_base'] ) ) {
			return (string) $internal['workflow_enum_base'];
		}

		if ( ! empty( $internal['workflow_enum'] ) ) {
			return (string) $internal['workflow_enum'];
		}

		return '';
	}

	/**
	 * Load a package catalog row for validation.
	 *
	 * @param string $package_id Package enum.
	 * @return array<string, mixed>|null
	 */
	public function package_row( string $package_id ): ?array {
		$catalog = Vocabulary::package_catalog();

		return $catalog[ $package_id ] ?? null;
	}
}
