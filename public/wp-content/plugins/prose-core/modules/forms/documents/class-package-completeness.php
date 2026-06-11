<?php
/**
 * Package completeness DTO.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Package_Completeness
 *
 * Immutable completeness snapshot for a package, in the documented shape:
 *
 *   {
 *     package_key,
 *     completion_percentage,
 *     missing_fields,
 *     missing_forms,
 *     ready_to_generate
 *   }
 */
final class Package_Completeness {

	/**
	 * Package key.
	 *
	 * @var string
	 */
	private string $package_key;

	/**
	 * Completion percentage (0-100).
	 *
	 * @var int
	 */
	private int $completion_percentage;

	/**
	 * Missing field keys across required forms.
	 *
	 * @var string[]
	 */
	private array $missing_fields;

	/**
	 * Required form codes not yet ready.
	 *
	 * @var string[]
	 */
	private array $missing_forms;

	/**
	 * Whether the package is ready to generate.
	 *
	 * @var bool
	 */
	private bool $ready_to_generate;

	/**
	 * Constructor.
	 *
	 * @param string   $package_key           Package key.
	 * @param int      $completion_percentage Completion percentage.
	 * @param string[] $missing_fields        Missing field keys.
	 * @param string[] $missing_forms         Missing required form codes.
	 * @param bool     $ready_to_generate     Whether ready to generate.
	 */
	public function __construct(
		string $package_key,
		int $completion_percentage,
		array $missing_fields,
		array $missing_forms,
		bool $ready_to_generate
	) {
		$this->package_key           = $package_key;
		$this->completion_percentage = max( 0, min( 100, $completion_percentage ) );
		$this->missing_fields        = array_values( array_unique( array_map( 'strval', $missing_fields ) ) );
		$this->missing_forms         = array_values( array_unique( array_map( 'strval', $missing_forms ) ) );
		$this->ready_to_generate     = $ready_to_generate;
	}

	/**
	 * @return string
	 */
	public function package_key(): string {
		return $this->package_key;
	}

	/**
	 * @return int
	 */
	public function completion_percentage(): int {
		return $this->completion_percentage;
	}

	/**
	 * @return string[]
	 */
	public function missing_fields(): array {
		return $this->missing_fields;
	}

	/**
	 * @return string[]
	 */
	public function missing_forms(): array {
		return $this->missing_forms;
	}

	/**
	 * @return bool
	 */
	public function is_ready_to_generate(): bool {
		return $this->ready_to_generate;
	}

	/**
	 * Serialize to the documented array shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'package_key'           => $this->package_key,
			'completion_percentage' => $this->completion_percentage,
			'missing_fields'        => $this->missing_fields,
			'missing_forms'         => $this->missing_forms,
			'ready_to_generate'     => $this->ready_to_generate,
		);
	}
}
