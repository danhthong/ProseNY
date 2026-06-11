<?php
/**
 * Phase-A pre-flight import validator.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Database\Import;

use ProSe\Core\Forms\Form_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Import_Validator
 */
final class Import_Validator {

	/**
	 * Critical packages requiring 90% coverage.
	 *
	 * @var string[]
	 */
	private const CRITICAL_PACKAGES = array(
		'PKG_UNCONTESTED_NO_CHILDREN',
		'PKG_UNCONTESTED_WITH_CHILDREN',
		'PKG_CONTESTED_COMMENCEMENT',
		'PKG_CUSTODY_PETITION',
		'PKG_CHILD_SUPPORT_PETITION',
		'PKG_ORDER_OF_PROTECTION',
	);

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $forms;

	/**
	 * Alias registry.
	 *
	 * @var Alias_Registry
	 */
	private Alias_Registry $aliases;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->forms   = new Form_Repository();
		$this->aliases = new Alias_Registry();
	}

	/**
	 * Run pre-flight validation over all artifacts.
	 *
	 * @param array<string, array<string, mixed>> $artifacts Loaded artifacts.
	 * @param bool                                $strict    Promote soft issues to hard.
	 * @return array{passed: bool, hard: string[], soft: string[], report_path: string}
	 */
	public function validate( array $artifacts, bool $strict = false ): array {
		$hard = array();
		$soft = array();

		$workflows = $this->index_workflows( $artifacts['workflow'] ?? array() );
		$nodes     = $this->index_nodes( $artifacts['node'] ?? array() );
		$packages  = $this->index_packages( $artifacts['package'] ?? array() );

		$this->aliases->load_from_artifact( $artifacts['alias'] ?? array(), false );
		$alias_validation = $this->aliases->validate();
		$hard             = array_merge( $hard, $alias_validation['hard'] );
		$soft             = array_merge( $soft, $alias_validation['soft'] );

		// workflow exists.
		foreach ( (array) ( $artifacts['node']['nodes'] ?? array() ) as $node ) {
			$wf = (string) ( $node['workflow_key'] ?? '' );
			if ( '' !== $wf && ! isset( $workflows[ $wf ] ) ) {
				$hard[] = sprintf( 'Node %s references unknown workflow_key %s', $node['node_key'] ?? '?', $wf );
			}
		}

		foreach ( (array) ( $artifacts['package']['packages'] ?? array() ) as $pkg ) {
			$wf = (string) ( $pkg['workflow_key'] ?? '' );
			if ( '' !== $wf && ! isset( $workflows[ $wf ] ) ) {
				$hard[] = sprintf( 'Package %s references unknown workflow_key %s', $pkg['package_key'] ?? '?', $wf );
			}
		}

		// node exists.
		foreach ( (array) ( $artifacts['node']['edges'] ?? array() ) as $edge ) {
			foreach ( array( 'from_node', 'to_node' ) as $field ) {
				$nk = (string) ( $edge[ $field ] ?? '' );
				if ( '' !== $nk && ! isset( $nodes[ $nk ] ) ) {
					$hard[] = sprintf( 'Edge references unknown node %s', $nk );
				}
			}
		}

		foreach ( (array) ( $artifacts['package']['packages'] ?? array() ) as $pkg ) {
			$pn = (string) ( $pkg['primary_node'] ?? '' );
			if ( '' !== $pn && ! isset( $nodes[ $pn ] ) ) {
				$hard[] = sprintf( 'Package %s references unknown primary_node %s', $pkg['package_key'] ?? '?', $pn );
			}
		}

		// package exists.
		foreach ( (array) ( $artifacts['package']['relations'] ?? array() ) as $rel ) {
			foreach ( array( 'from_package_key', 'to_package_key' ) as $field ) {
				$pk = (string) ( $rel[ $field ] ?? '' );
				if ( '' !== $pk && ! isset( $packages[ $pk ] ) ) {
					$hard[] = sprintf( 'Relation references unknown package %s', $pk );
				}
			}
		}

		foreach ( (array) ( $artifacts['form_package']['mappings'] ?? array() ) as $mapping ) {
			$pk = (string) ( $mapping['package_key'] ?? '' );
			if ( '' !== $pk && ! isset( $packages[ $pk ] ) ) {
				$hard[] = sprintf( 'Mapping references unknown package %s', $pk );
			}
		}

		// form exists + alias resolves.
		$coverage = $this->compute_coverage( $artifacts, $packages );

		foreach ( (array) ( $artifacts['form_package']['mappings'] ?? array() ) as $mapping ) {
			$form_class = (string) ( $mapping['form_class'] ?? 'import_backed' );
			if ( 'generated' === $form_class ) {
				continue;
			}

			$code      = (string) ( $mapping['form_code'] ?? '' );
			$canonical = $this->aliases->resolve( $code );

			if ( '' === $canonical ) {
				$hard[] = sprintf( 'Empty form_code in mapping for package %s', $mapping['package_key'] ?? '?' );
				continue;
			}

			if ( 'required' === ( $mapping['requirement'] ?? '' ) ) {
				$post = $this->forms->get_by_form_code( $canonical );
				if ( ! $post ) {
					$soft[] = sprintf(
						'Required form %s (canonical %s) has no prose_form record',
						$code,
						$canonical
					);
				}
			}
		}

		foreach ( $coverage as $pkg_key => $stats ) {
			$threshold = in_array( $pkg_key, self::CRITICAL_PACKAGES, true ) ? 90 : 80;
			if ( $stats['coverage_percentage'] < $threshold ) {
				$msg = sprintf(
					'Coverage for %s is %.1f%% (threshold %d%%)',
					$pkg_key,
					$stats['coverage_percentage'],
					$threshold
				);
				$soft[] = $msg;
			}
		}

		if ( $strict ) {
			$hard = array_merge( $hard, $soft );
			$soft = array();
		}

		$passed = empty( $hard );
		$path   = $this->write_report( $artifacts, $hard, $soft, $coverage, $passed );

		return array(
			'passed'      => $passed,
			'hard'        => $hard,
			'soft'        => $soft,
			'report_path' => $path,
		);
	}

	/**
	 * @param array<string, mixed> $workflow_artifact Workflow artifact.
	 * @return array<string, true>
	 */
	private function index_workflows( array $workflow_artifact ): array {
		$index = array();
		foreach ( (array) ( $workflow_artifact['workflows'] ?? array() ) as $wf ) {
			$key = (string) ( $wf['workflow_key'] ?? '' );
			if ( '' !== $key ) {
				$index[ $key ] = true;
			}
		}
		return $index;
	}

	/**
	 * @param array<string, mixed> $node_artifact Node artifact.
	 * @return array<string, true>
	 */
	private function index_nodes( array $node_artifact ): array {
		$index = array();
		foreach ( (array) ( $node_artifact['nodes'] ?? array() ) as $node ) {
			$key = (string) ( $node['node_key'] ?? '' );
			if ( '' !== $key ) {
				$index[ $key ] = true;
			}
		}
		return $index;
	}

	/**
	 * @param array<string, mixed> $package_artifact Package artifact.
	 * @return array<string, true>
	 */
	private function index_packages( array $package_artifact ): array {
		$index = array();
		foreach ( (array) ( $package_artifact['packages'] ?? array() ) as $pkg ) {
			$key = (string) ( $pkg['package_key'] ?? '' );
			if ( '' !== $key ) {
				$index[ $key ] = true;
			}
		}
		return $index;
	}

	/**
	 * Compute per-package required-form coverage.
	 *
	 * @param array<string, array<string, mixed>> $artifacts All artifacts.
	 * @param array<string, true>                 $packages  Package index.
	 * @return array<string, array{coverage_percentage: float, required: int, mapped: int}>
	 */
	private function compute_coverage( array $artifacts, array $packages ): array {
		$required_by_pkg = array();
		foreach ( (array) ( $artifacts['package']['packages'] ?? array() ) as $pkg ) {
			$key = (string) ( $pkg['package_key'] ?? '' );
			$meta = (array) ( $pkg['form_metadata'] ?? array() );
			$req = 0;
			foreach ( (array) ( $pkg['required_forms'] ?? array() ) as $code ) {
				$form_class = $meta[ $code ]['form_class'] ?? 'import_backed';
				if ( 'generated' !== $form_class ) {
					++$req;
				}
			}
			$required_by_pkg[ $key ] = $req;
		}

		$mapped_required = array_fill_keys( array_keys( $packages ), 0 );

		foreach ( (array) ( $artifacts['form_package']['mappings'] ?? array() ) as $mapping ) {
			if ( 'required' !== ( $mapping['requirement'] ?? '' ) ) {
				continue;
			}
			if ( 'generated' === ( $mapping['form_class'] ?? '' ) ) {
				continue;
			}

			$pk   = (string) ( $mapping['package_key'] ?? '' );
			$code = $this->aliases->resolve( (string) ( $mapping['form_code'] ?? '' ) );
			if ( $this->forms->get_by_form_code( $code ) ) {
				$mapped_required[ $pk ] = ( $mapped_required[ $pk ] ?? 0 ) + 1;
			}
		}

		$stats = array();
		foreach ( $required_by_pkg as $pkg_key => $required ) {
			$mapped = min( $mapped_required[ $pkg_key ] ?? 0, $required );
			$pct    = $required > 0 ? ( $mapped / $required ) * 100 : 100.0;
			$stats[ $pkg_key ] = array(
				'required'             => $required,
				'mapped'               => $mapped,
				'coverage_percentage'  => round( $pct, 1 ),
			);
		}

		return $stats;
	}

	/**
	 * Write import-validation-report.md.
	 *
	 * @param array<string, array<string, mixed>>                          $artifacts All artifacts.
	 * @param string[]                                                     $hard      Hard failures.
	 * @param string[]                                                     $soft      Soft warnings.
	 * @param array<string, array{coverage_percentage: float, required: int, mapped: int}> $coverage  Coverage stats.
	 * @param bool                                                         $passed    Overall pass.
	 * @return string Report path.
	 */
	private function write_report( array $artifacts, array $hard, array $soft, array $coverage, bool $passed ): string {
		$loader = new Seeder_Artifact_Loader();
		$path   = trailingslashit( $loader->get_data_dir() ) . 'import-validation-report.md';
		$version = (string) ( $artifacts['package']['catalog_version'] ?? 'unknown' );

		$lines = array(
			'# Import Validation Report',
			'',
			sprintf( 'Generated: %s', gmdate( 'Y-m-d H:i:s' ) ),
			sprintf( 'Catalog version: %s', $version ),
			sprintf( 'Status: **%s**', $passed ? 'PASS' : 'FAIL' ),
			'',
			'## Validation Rules',
			'',
			'- workflow exists',
			'- node exists',
			'- package exists',
			'- form exists (import_backed only)',
			'- alias resolves',
			'',
		);

		if ( ! empty( $hard ) ) {
			$lines[] = '## Hard Failures';
			$lines[] = '';
			foreach ( $hard as $issue ) {
				$lines[] = '- ' . $issue;
			}
			$lines[] = '';
		}

		if ( ! empty( $soft ) ) {
			$lines[] = '## Soft Warnings';
			$lines[] = '';
			foreach ( $soft as $issue ) {
				$lines[] = '- ' . $issue;
			}
			$lines[] = '';
		}

		$lines[] = '## Per-Package Coverage';
		$lines[] = '';
		$lines[] = '| Package | Required | Mapped | Coverage % |';
		$lines[] = '|---------|----------|--------|------------|';

		foreach ( $coverage as $pkg_key => $stats ) {
			$lines[] = sprintf(
				'| %s | %d | %d | %.1f%% |',
				$pkg_key,
				$stats['required'],
				$stats['mapped'],
				$stats['coverage_percentage']
			);
		}

		$lines[] = '';

		file_put_contents( $path, implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $path;
	}
}
