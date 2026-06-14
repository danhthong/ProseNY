<?php
/**
 * Document assembler — orchestrates package resolution and assembly payload output.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Assembly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Document_Assembler
 */
final class Document_Assembler {

	/**
	 * Validator.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Package loader.
	 *
	 * @var Package_Loader
	 */
	private Package_Loader $packages;

	/**
	 * Form loader.
	 *
	 * @var Form_Loader
	 */
	private Form_Loader $forms;

	/**
	 * Manifest builder.
	 *
	 * @var Manifest_Builder
	 */
	private Manifest_Builder $manifests;

	/**
	 * Data resolver.
	 *
	 * @var Data_Resolver
	 */
	private Data_Resolver $data;

	/**
	 * Constructor.
	 *
	 * @param Validator|null        $validator Validator.
	 * @param Package_Loader|null   $packages  Package loader.
	 * @param Form_Loader|null      $forms     Form loader.
	 * @param Manifest_Builder|null $manifests Manifest builder.
	 * @param Data_Resolver|null    $data      Data resolver.
	 */
	public function __construct(
		?Validator $validator = null,
		?Package_Loader $packages = null,
		?Form_Loader $forms = null,
		?Manifest_Builder $manifests = null,
		?Data_Resolver $data = null
	) {
		$this->validator = $validator ?? new Validator();
		$this->packages  = $packages ?? new Package_Loader();
		$this->forms     = $forms ?? new Form_Loader();
		$this->manifests = $manifests ?? new Manifest_Builder();
		$this->data      = $data ?? new Data_Resolver();
	}

	/**
	 * Assemble document data for a package and intake payload.
	 *
	 * @param array<string, mixed> $intake     Intake data.
	 * @param string               $package_id Package enum.
	 * @return array<string, mixed>
	 */
	public function assemble( array $intake, string $package_id ): array {
		$request = $this->validator->validate_request( $intake, $package_id );

		if ( empty( $request['valid'] ) ) {
			return $this->failure( (array) ( $request['error'] ?? array() ) );
		}

		$package = $this->packages->load( $package_id );
		$package_check = $this->validator->validate_package( $package );

		if ( empty( $package_check['valid'] ) ) {
			return $this->failure( (array) ( $package_check['error'] ?? array() ) );
		}

		$package       = (array) $package;
		$package_forms = (array) ( $package['forms'] ?? array() );
		$form_codes    = array_map(
			static function ( array $row ): string {
				return (string) ( $row['form_id'] ?? '' );
			},
			$package_forms
		);

		$loaded_map = $this->forms->load_many( $form_codes );
		$manifest   = $this->manifests->build( $package_id, $package_forms, $loaded_map );
		$flat_data  = $this->data->resolve( $intake );

		$form_definitions = array_values(
			array_filter(
				$loaded_map,
				static function ( $record ): bool {
					return is_array( $record );
				}
			)
		);

		$missing_data = $this->data->missing_data( $package_id, $package, $flat_data, $form_definitions );

		return array(
			'success'  => true,
			'assembly' => array(
				'package_id'   => $package_id,
				'manifest'     => $manifest,
				'forms'        => $form_definitions,
				'data'         => $flat_data,
				'missing_data' => $missing_data,
			),
		);
	}

	/**
	 * Build a failure response.
	 *
	 * @param array<string, string> $error Error payload.
	 * @return array<string, mixed>
	 */
	private function failure( array $error ): array {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => (string) ( $error['code'] ?? 'assembly_failed' ),
				'message' => (string) ( $error['message'] ?? __( 'Document assembly failed.', 'prose-core' ) ),
			),
		);
	}
}
