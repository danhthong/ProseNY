<?php
/**
 * Document validation result DTO.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Document_Validation_Result
 *
 * Immutable outcome of validating a generated document against its
 * required fields, conditionally-required fields, workflow requirements,
 * package requirements, and county requirements.
 */
final class Document_Validation_Result {

	/**
	 * Missing required field keys.
	 *
	 * @var string[]
	 */
	private array $missing_required;

	/**
	 * Missing conditionally-required field keys.
	 *
	 * @var string[]
	 */
	private array $missing_conditional;

	/**
	 * Unmet workflow requirement codes.
	 *
	 * @var string[]
	 */
	private array $workflow_errors;

	/**
	 * Unmet package requirement codes.
	 *
	 * @var string[]
	 */
	private array $package_errors;

	/**
	 * Unmet county requirement codes.
	 *
	 * @var string[]
	 */
	private array $county_errors;

	/**
	 * Constructor.
	 *
	 * @param string[] $missing_required    Missing required fields.
	 * @param string[] $missing_conditional Missing conditional fields.
	 * @param string[] $workflow_errors     Workflow requirement errors.
	 * @param string[] $package_errors      Package requirement errors.
	 * @param string[] $county_errors       County requirement errors.
	 */
	public function __construct(
		array $missing_required = array(),
		array $missing_conditional = array(),
		array $workflow_errors = array(),
		array $package_errors = array(),
		array $county_errors = array()
	) {
		$this->missing_required    = array_values( array_map( 'strval', $missing_required ) );
		$this->missing_conditional = array_values( array_map( 'strval', $missing_conditional ) );
		$this->workflow_errors     = array_values( array_map( 'strval', $workflow_errors ) );
		$this->package_errors      = array_values( array_map( 'strval', $package_errors ) );
		$this->county_errors       = array_values( array_map( 'strval', $county_errors ) );
	}

	/**
	 * Whether the document passed every validation check.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return empty( $this->missing_required )
			&& empty( $this->missing_conditional )
			&& empty( $this->workflow_errors )
			&& empty( $this->package_errors )
			&& empty( $this->county_errors );
	}

	/**
	 * @return string[]
	 */
	public function missing_required(): array {
		return $this->missing_required;
	}

	/**
	 * @return string[]
	 */
	public function missing_conditional(): array {
		return $this->missing_conditional;
	}

	/**
	 * @return string[]
	 */
	public function workflow_errors(): array {
		return $this->workflow_errors;
	}

	/**
	 * @return string[]
	 */
	public function package_errors(): array {
		return $this->package_errors;
	}

	/**
	 * @return string[]
	 */
	public function county_errors(): array {
		return $this->county_errors;
	}

	/**
	 * All missing field keys (required and conditional).
	 *
	 * @return string[]
	 */
	public function missing_fields(): array {
		return array_values( array_unique( array_merge( $this->missing_required, $this->missing_conditional ) ) );
	}

	/**
	 * Flat list of all error codes.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		$errors = array();

		foreach ( $this->missing_required as $key ) {
			$errors[] = 'required:' . $key;
		}

		foreach ( $this->missing_conditional as $key ) {
			$errors[] = 'conditional:' . $key;
		}

		foreach ( $this->workflow_errors as $code ) {
			$errors[] = 'workflow:' . $code;
		}

		foreach ( $this->package_errors as $code ) {
			$errors[] = 'package:' . $code;
		}

		foreach ( $this->county_errors as $code ) {
			$errors[] = 'county:' . $code;
		}

		return $errors;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'is_valid'            => $this->is_valid(),
			'missing_required'    => $this->missing_required,
			'missing_conditional' => $this->missing_conditional,
			'workflow_errors'     => $this->workflow_errors,
			'package_errors'      => $this->package_errors,
			'county_errors'       => $this->county_errors,
			'errors'              => $this->errors(),
		);
	}
}
