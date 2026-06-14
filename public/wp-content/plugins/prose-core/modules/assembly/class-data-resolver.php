<?php
/**
 * Assembly data resolver — normalizes intake and detects missing data paths.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Assembly;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Data_Resolver
 */
final class Data_Resolver {

	/**
	 * Flatten nested intake data to dot-notation paths.
	 *
	 * Example: { client: { full_name: "John" } } => { "client.full_name": "John" }
	 *
	 * @param array<string, mixed> $intake Intake payload.
	 * @return array<string, mixed>
	 */
	public function resolve( array $intake ): array {
		$flat = array();
		$this->flatten( $intake, '', $flat );

		return $flat;
	}

	/**
	 * Identify unresolved required data paths for the assembly payload.
	 *
	 * Field mappings are not yet implemented. Required paths may be supplied via
	 * the prose_assembly_required_data_paths filter until the Mapping Repository
	 * exists. Paths missing from normalized data or empty after trimming are
	 * returned so the Intake Chat Agent can request them.
	 *
	 * @param string               $package_id Package enum.
	 * @param array<string, mixed> $package    Normalized package definition.
	 * @param array<string, mixed> $data       Flattened intake data.
	 * @param array<int, array<string, mixed>> $forms Resolved form definitions.
	 * @return string[]
	 */
	public function missing_data( string $package_id, array $package, array $data, array $forms ): array {
		/**
		 * Filter required data paths for a package assembly.
		 *
		 * Future Mapping Repository will populate this list. Until then the default
		 * is an empty array.
		 *
		 * @param string[]             $paths      Required dot-notation data paths.
		 * @param string               $package_id Package enum.
		 * @param array<string, mixed> $package    Package definition.
		 * @param array<int, array<string, mixed>> $forms Form definitions.
		 */
		$required_paths = apply_filters(
			'prose_assembly_required_data_paths',
			array(),
			$package_id,
			$package,
			$forms
		);

		if ( ! is_array( $required_paths ) || empty( $required_paths ) ) {
			return array();
		}

		$missing = array();

		foreach ( $required_paths as $path ) {
			$path = trim( (string) $path );

			if ( '' === $path ) {
				continue;
			}

			if ( ! $this->path_is_present( $data, $path ) ) {
				$missing[] = $path;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Recursively flatten a value into dot-notation keys.
	 *
	 * @param mixed                 $value  Current value.
	 * @param string                $prefix Current key prefix.
	 * @param array<string, mixed>  $target Target array (by reference).
	 * @return void
	 */
	private function flatten( $value, string $prefix, array &$target ): void {
		if ( is_array( $value ) ) {
			if ( $this->is_list( $value ) ) {
				foreach ( $value as $index => $item ) {
					$key = '' === $prefix ? (string) $index : $prefix . '.' . $index;
					$this->flatten( $item, $key, $target );
				}

				return;
			}

			foreach ( $value as $key => $item ) {
				$key_string = (string) $key;
				$next       = '' === $prefix ? $key_string : $prefix . '.' . $key_string;
				$this->flatten( $item, $next, $target );
			}

			return;
		}

		if ( '' === $prefix ) {
			return;
		}

		$target[ $prefix ] = $value;
	}

	/**
	 * Determine whether a flattened path is present and non-empty.
	 *
	 * @param array<string, mixed> $data Flattened data.
	 * @param string               $path Dot-notation path.
	 * @return bool
	 */
	private function path_is_present( array $data, string $path ): bool {
		if ( ! array_key_exists( $path, $data ) ) {
			return false;
		}

		$value = $data[ $path ];

		if ( null === $value ) {
			return false;
		}

		if ( is_string( $value ) && '' === trim( $value ) ) {
			return false;
		}

		if ( is_array( $value ) && empty( $value ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether an array is a list (sequential numeric keys).
	 *
	 * @param array<mixed> $array Array to inspect.
	 * @return bool
	 */
	private function is_list( array $array ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $array );
		}

		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}
