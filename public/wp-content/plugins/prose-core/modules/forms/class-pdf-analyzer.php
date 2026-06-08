<?php
/**
 * PDF analysis service (stub — not yet implemented).
 *
 * Extension point for the future PDF Analysis Engine.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Analyzer
 */
class Pdf_Analyzer {

	/**
	 * Form repository.
	 *
	 * @var Form_Repository
	 */
	private Form_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Form_Repository $repository Form repository.
	 */
	public function __construct( Form_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Analyze a form's PDF and persist metadata.
	 *
	 * @param int $post_id Form post ID.
	 * @return array<string, mixed>
	 *
	 * @throws Not_Implemented_Exception When called before implementation.
	 */
	public function analyze( int $post_id ): array {
		throw new Not_Implemented_Exception(
			__( 'PDF analysis is not yet implemented.', 'prose-core' )
		);
	}

	/**
	 * Extract raw fields from a PDF file.
	 *
	 * @param string $file_path Local PDF path.
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws Not_Implemented_Exception When called before implementation.
	 */
	public function extract_fields( string $file_path ): array {
		throw new Not_Implemented_Exception(
			__( 'PDF field extraction is not yet implemented.', 'prose-core' )
		);
	}

	/**
	 * Normalize extracted PDF fields into ProSe schema.
	 *
	 * @param array<int, array<string, mixed>> $fields Raw fields.
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws Not_Implemented_Exception When called before implementation.
	 */
	public function normalize_fields( array $fields ): array {
		throw new Not_Implemented_Exception(
			__( 'PDF field normalization is not yet implemented.', 'prose-core' )
		);
	}

	/**
	 * Save PDF analysis metadata to a form post.
	 *
	 * @param int                  $post_id Form post ID.
	 * @param array<string, mixed> $data    Analysis data.
	 * @return bool
	 *
	 * @throws Not_Implemented_Exception When called before implementation.
	 */
	public function save_metadata( int $post_id, array $data ): bool {
		throw new Not_Implemented_Exception(
			__( 'PDF metadata saving via analyzer is not yet implemented.', 'prose-core' )
		);
	}
}
