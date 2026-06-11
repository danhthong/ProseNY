<?php
/**
 * Document audit trail DTO.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Document_Audit_Trail
 *
 * Immutable provenance record for a generated document: when and by whom
 * it was generated, the generation version, and the source case / package
 * the document was assembled from.
 */
final class Document_Audit_Trail {

	/**
	 * Generation timestamp (Y-m-d H:i:s, UTC).
	 *
	 * @var string
	 */
	private string $generated_at;

	/**
	 * User ID that generated the document (0 = system).
	 *
	 * @var int
	 */
	private int $generated_by;

	/**
	 * Generation version.
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * Source case ID.
	 *
	 * @var int
	 */
	private int $source_case_id;

	/**
	 * Source package key.
	 *
	 * @var string
	 */
	private string $source_package_id;

	/**
	 * Constructor.
	 *
	 * @param string $generated_at      Generation timestamp.
	 * @param int    $generated_by      User ID.
	 * @param int    $version           Version number.
	 * @param int    $source_case_id    Source case ID.
	 * @param string $source_package_id Source package key.
	 */
	public function __construct(
		string $generated_at = '',
		int $generated_by = 0,
		int $version = 1,
		int $source_case_id = 0,
		string $source_package_id = ''
	) {
		$this->generated_at      = '' !== $generated_at ? $generated_at : gmdate( 'Y-m-d H:i:s' );
		$this->generated_by      = $generated_by;
		$this->version           = $version > 0 ? $version : 1;
		$this->source_case_id    = $source_case_id;
		$this->source_package_id = $source_package_id;
	}

	/**
	 * @return string
	 */
	public function generated_at(): string {
		return $this->generated_at;
	}

	/**
	 * @return int
	 */
	public function generated_by(): int {
		return $this->generated_by;
	}

	/**
	 * @return int
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * @return int
	 */
	public function source_case_id(): int {
		return $this->source_case_id;
	}

	/**
	 * @return string
	 */
	public function source_package_id(): string {
		return $this->source_package_id;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'generated_at'      => $this->generated_at,
			'generated_by'      => $this->generated_by,
			'version'           => $this->version,
			'source_case_id'    => $this->source_case_id,
			'source_package_id' => $this->source_package_id,
		);
	}
}
