<?php
/**
 * Package resolver — maps a workflow key (and answers) to available packages.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Engine;

use ProSe\Core\Forms\Classification\Vocabulary;
use ProSe\Core\Forms\Package_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Resolver
 *
 * Resolves the package keys available at intake for a resolved workflow.
 * Candidate selection is deterministic and driven by the vocabulary
 * package catalog; when a package repository is supplied, candidates are
 * filtered to those that are active in the catalog post type.
 */
final class Package_Resolver {

	use Answer_Normalizer;

	/**
	 * Optional package repository.
	 *
	 * @var Package_Repository|null
	 */
	private ?Package_Repository $packages;

	/**
	 * Constructor.
	 *
	 * @param Package_Repository|null $packages Package repository.
	 */
	public function __construct( ?Package_Repository $packages = null ) {
		$this->packages = $packages;
	}

	/**
	 * Resolve available packages for a workflow.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param array<string, mixed> $answers      Intake answers.
	 * @return array{available_packages: string[], confidence_score: float}
	 */
	public function resolve( string $workflow_key, array $answers = array() ): array {
		$candidates = $this->candidates( $workflow_key, $answers );

		if ( empty( $candidates ) ) {
			return array(
				'available_packages' => array(),
				'confidence_score'   => 0.0,
			);
		}

		if ( $this->packages instanceof Package_Repository ) {
			$verified = array();

			foreach ( $candidates as $package_key ) {
				if ( $this->packages->get_active_by_key( $package_key ) instanceof \WP_Post ) {
					$verified[] = $package_key;
				}
			}

			if ( ! empty( $verified ) ) {
				return array(
					'available_packages' => array_values( array_unique( $verified ) ),
					'confidence_score'   => 1.0,
				);
			}
		}

		return array(
			'available_packages' => array_values( array_unique( $candidates ) ),
			'confidence_score'   => 0.9,
		);
	}

	/**
	 * Determine candidate package keys for a workflow and answers.
	 *
	 * @param string               $workflow_key Workflow key.
	 * @param array<string, mixed> $answers      Intake answers.
	 * @return string[]
	 */
	private function candidates( string $workflow_key, array $answers ): array {
		$has_children = $this->to_bool( $answers['children'] ?? null );

		switch ( $workflow_key ) {
			case Vocabulary::WF_UNCONTESTED_DIVORCE:
				return array(
					true === $has_children
						? Vocabulary::PKG_UNCONTESTED_WITH_CHILDREN
						: Vocabulary::PKG_UNCONTESTED_NO_CHILDREN,
				);
			case Vocabulary::WF_CONTESTED_DIVORCE:
				return array( Vocabulary::PKG_CONTESTED_COMMENCEMENT );
			case Vocabulary::WF_DEFAULT_DIVORCE:
				return array( Vocabulary::PKG_DEFAULT_DIVORCE );
			case Vocabulary::WF_CUSTODY:
				return array( Vocabulary::PKG_CUSTODY_PETITION );
			case Vocabulary::WF_CHILD_SUPPORT:
				return array( Vocabulary::PKG_CHILD_SUPPORT_PETITION );
			case Vocabulary::WF_ORDER_OF_PROTECTION:
				return array( Vocabulary::PKG_ORDER_OF_PROTECTION );
			default:
				return $this->from_catalog( $workflow_key );
		}
	}

	/**
	 * Fall back to every catalog package mapped to the workflow.
	 *
	 * @param string $workflow_key Workflow key.
	 * @return string[]
	 */
	private function from_catalog( string $workflow_key ): array {
		$out = array();

		foreach ( Vocabulary::package_catalog() as $package_key => $data ) {
			if ( (string) ( $data['workflow_id'] ?? '' ) === $workflow_key ) {
				$out[] = (string) $package_key;
			}
		}

		return $out;
	}
}
