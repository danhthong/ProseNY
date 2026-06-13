<?php
/**
 * Package Builder — orchestrates form selection, asset resolution, manifest
 * assembly, and ZIP packaging from an already-resolved workflow.
 *
 * Consumes existing systems and never re-implements workflow resolution or
 * form-selection logic:
 *  - Workflow_Catalog  : workflow definition + stage blocks
 *  - Forms_Catalog     : canonical form records
 *  - Asset_Source      : per-form asset resolution (blank vs filled)
 *
 * The Package Builder never modifies documents and never chooses workflows.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\PackageBuilder;

use ProSe\Core\Forms\Forms_Catalog;
use ProSe\Core\Routing\Workflow_Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Builder
 */
final class Package_Builder {

	/**
	 * Workflow catalog.
	 *
	 * @var Workflow_Catalog
	 */
	private Workflow_Catalog $workflows;

	/**
	 * Forms catalog.
	 *
	 * @var Forms_Catalog
	 */
	private Forms_Catalog $forms;

	/**
	 * Asset source.
	 *
	 * @var Asset_Source
	 */
	private Asset_Source $asset_source;

	/**
	 * Workflow snapshot fields frozen into the manifest.
	 */
	private const SNAPSHOT_FIELDS = array(
		'workflow',
		'workflow_category',
		'issue_type',
		'court',
		'counties_supported',
		'stages',
		'required_forms',
		'optional_forms',
		'supporting_documents',
	);

	/**
	 * Constructor.
	 *
	 * @param Workflow_Catalog|null $workflows    Workflow catalog.
	 * @param Forms_Catalog|null    $forms        Forms catalog.
	 * @param Asset_Source|null     $asset_source Asset source (defaults to blank).
	 */
	public function __construct(
		?Workflow_Catalog $workflows = null,
		?Forms_Catalog $forms = null,
		?Asset_Source $asset_source = null
	) {
		$this->workflows    = $workflows ?? new Workflow_Catalog();
		$this->forms        = $forms ?? new Forms_Catalog();
		$this->asset_source = $asset_source ?? new Blank_Asset_Source();
	}

	/**
	 * Build a package manifest (no disk writes).
	 *
	 * @param array<string, mixed> $input { conversation_id, workflow, facts, package_type }.
	 * @return array<string, mixed>
	 */
	public function build_manifest( array $input ): array {
		return $this->assemble( $input )->to_array();
	}

	/**
	 * Build a downloadable package (manifest + assets + ZIP) when ready.
	 *
	 * @param array<string, mixed> $input { conversation_id, workflow, facts, package_type }.
	 * @return array<string, mixed>
	 */
	public function build_package( array $input ): array {
		$manifest = $this->assemble( $input );
		$result   = $manifest->to_array();

		if ( Package_Status::READY !== $manifest->package_status() ) {
			return $result;
		}

		$manifest->set_manifest_status( Manifest_Status::READY );

		$writer  = new Package_Zip_Writer( $this->asset_source );
		$output  = $writer->write( $manifest );

		return array_merge( $manifest->to_array(), $output );
	}

	/**
	 * Assemble a manifest object from input.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return Package_Manifest
	 */
	private function assemble( array $input ): Package_Manifest {
		$conversation_id = (string) ( $input['conversation_id'] ?? '' );
		$workflow_key    = (string) ( $input['workflow'] ?? '' );
		$package_type    = (string) ( $input['package_type'] ?? Package_Type::BLANK );

		if ( '' === $package_type ) {
			$package_type = Package_Type::BLANK;
		}

		$definition = '' !== $workflow_key ? $this->workflows->by_key( $workflow_key ) : null;
		$snapshot   = is_array( $definition ) ? $this->snapshot( $definition ) : array();

		$manifest = new Package_Manifest(
			$this->generate_package_id(),
			$conversation_id,
			$workflow_key,
			$package_type,
			$snapshot
		);

		if ( '' === $workflow_key ) {
			$manifest->add_error( '', 'required', 'No workflow provided. Package Builder requires a resolved workflow.' );

			return $manifest;
		}

		if ( ! Package_Type::is_valid( $package_type ) ) {
			$manifest->add_error( '', 'required', sprintf( 'Unknown package_type "%s".', $package_type ) );

			return $manifest;
		}

		if ( ! Package_Type::is_supported( $package_type ) ) {
			$manifest->add_error( '', 'required', sprintf( 'Package type "%s" is not yet supported. MVP generates blank packages only.', $package_type ) );

			return $manifest;
		}

		if ( null === $definition ) {
			$manifest->add_error( '', 'required', sprintf( 'Workflow "%s" not found in Workflow Repository.', $workflow_key ) );

			return $manifest;
		}

		$this->resolve_stage_blocks( $manifest, $definition['required_forms'] ?? array(), 'required' );
		$this->resolve_stage_blocks( $manifest, $definition['optional_forms'] ?? array(), 'optional' );

		return $manifest;
	}

	/**
	 * Resolve a set of workflow stage blocks into manifest form entries.
	 *
	 * @param Package_Manifest $manifest    Manifest.
	 * @param mixed            $stage_blocks Stage blocks from the workflow.
	 * @param string           $requirement  required|optional.
	 * @return void
	 */
	private function resolve_stage_blocks( Package_Manifest $manifest, $stage_blocks, string $requirement ): void {
		foreach ( (array) $stage_blocks as $block ) {
			$stage = (string) ( $block['stage'] ?? '' );

			foreach ( (array) ( $block['forms'] ?? array() ) as $form ) {
				$code = (string) ( $form['code'] ?? '' );

				if ( '' === $code ) {
					continue;
				}

				$record = $this->forms->by_code( $code );
				$entry  = $this->asset_source->resolve( is_array( $record ) ? $record : array(), $code, $stage, $requirement );

				if ( isset( $entry['error'] ) ) {
					$manifest->add_error( $code, $requirement, (string) $entry['error'] );
				} elseif ( 'required' === $requirement && empty( $entry['generation_ready'] ) ) {
					$manifest->add_error( $code, $requirement, sprintf( 'Required form %s is not generation ready.', $code ) );
				}

				$manifest->add_form( $entry );
			}
		}
	}

	/**
	 * Freeze the key fields of a workflow definition.
	 *
	 * @param array<string, mixed> $definition Workflow definition.
	 * @return array<string, mixed>
	 */
	private function snapshot( array $definition ): array {
		$snapshot = array();

		foreach ( self::SNAPSHOT_FIELDS as $field ) {
			if ( array_key_exists( $field, $definition ) ) {
				$snapshot[ $field ] = $definition[ $field ];
			}
		}

		return $snapshot;
	}

	/**
	 * Generate a stable package identifier.
	 *
	 * @return string
	 */
	private function generate_package_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$uuid = str_replace( '-', '', wp_generate_uuid4() );
		} else {
			$uuid = bin2hex( random_bytes( 8 ) );
		}

		return 'pkg_' . substr( $uuid, 0, 12 );
	}
}
