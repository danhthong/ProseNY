<?php
/**
 * Seeds canonical form records for workflow-referenced forms missing from CSV.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Repository_Seeder
 */
final class Form_Repository_Seeder {

	/**
	 * NYC counties supported by default.
	 *
	 * @var string[]
	 */
	private const NYC_COUNTIES = array(
		'New York',
		'Kings',
		'Queens',
		'Bronx',
		'Richmond',
	);

	/**
	 * Ensure every workflow-referenced form has a canonical record.
	 *
	 * @param array<string, array<string, mixed>> $records Existing records keyed by form code.
	 * @return array<string, array<string, mixed>>
	 */
	public static function ensure_workflow_stubs( array $records ): array {
		$workflow_base = PROSE_CORE_PATH . 'docs/workflows';
		$files           = array_merge(
			glob( $workflow_base . '/divorce/*.json' ) ?: array(),
			glob( $workflow_base . '/family_court/*.json' ) ?: array()
		);

		foreach ( $files as $file ) {
			$raw = file_get_contents( $file );

			if ( false === $raw ) {
				continue;
			}

			$workflow = json_decode( $raw, true );

			if ( ! is_array( $workflow ) || empty( $workflow['workflow'] ) ) {
				continue;
			}

			$court    = (string) ( $workflow['court'] ?? 'family_court' );
			$category = self::workflow_category( $workflow );

			foreach ( array( 'required_forms', 'optional_forms' ) as $form_key ) {
				foreach ( (array) ( $workflow[ $form_key ] ?? array() ) as $stage_block ) {
					foreach ( (array) ( $stage_block['forms'] ?? array() ) as $form ) {
						$code = (string) ( $form['code'] ?? '' );

						if ( '' === $code || isset( $records[ $code ] ) ) {
							continue;
						}

						$records[ $code ] = self::stub_record(
							$code,
							(string) ( $form['internal_code'] ?? $code ),
							(string) ( $form['title'] ?? $code ),
							$court,
							$category
						);
					}
				}
			}
		}

		return $records;
	}

	/**
	 * Build a minimal stub record for a workflow-referenced form.
	 *
	 * @param string $form_code     Form code.
	 * @param string $internal_code Internal code.
	 * @param string $title         Title.
	 * @param string $court         Court.
	 * @param string $category      Category.
	 * @return array<string, mixed>
	 */
	public static function stub_record(
		string $form_code,
		string $internal_code,
		string $title,
		string $court,
		string $category
	): array {
		if ( 'null' === strtolower( $internal_code ) ) {
			$internal_code = $form_code;
		}

		return array(
			'form_code'                => $form_code,
			'internal_code'            => $internal_code,
			'title'                    => $title,
			'court'                    => in_array( $court, array( 'supreme_court', 'family_court' ), true ) ? $court : 'family_court',
			'category'                 => $category,
			'county_specific'          => false,
			'counties_supported'       => self::NYC_COUNTIES,
			'source_files'             => array(),
			'preferred_source'         => '',
			'editable_source'          => '',
			'fillable_pdf_available'   => false,
			'docx_available'           => false,
			'wpd_available'            => false,
			'import_status'            => 'pending',
			'official_url'             => '',
			'case_types'               => array(),
			'aliases'                  => array(),
			'workflow_references'      => array(),
			'fillable_strategy'        => 'none',
			'field_mapping_status'     => 'unmapped',
			'generation_ready'         => false,
		);
	}

	/**
	 * Resolve repository category from workflow metadata.
	 *
	 * @param array<string, mixed> $workflow Workflow definition.
	 * @return string
	 */
	private static function workflow_category( array $workflow ): string {
		$issue_type = (string) ( $workflow['issue_type'] ?? '' );
		$category   = (string) ( $workflow['workflow_category'] ?? '' );

		if ( 'divorce' === $issue_type || 'divorce' === $category ) {
			return 'divorce';
		}

		return str_replace( ' ', '_', strtolower( $issue_type ) );
	}
}
