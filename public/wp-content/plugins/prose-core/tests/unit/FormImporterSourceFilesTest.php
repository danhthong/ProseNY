<?php
/**
 * Tests for Form_Importer multi-file source helpers.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Form_File_Manager;
use ProSe\Core\Forms\Form_Importer;
use ProSe\Core\Forms\Form_Repository;

/**
 * Class FormImporterSourceFilesTest
 */
class FormImporterSourceFilesTest extends TestCase {

	/**
	 * Build pairs from pipe-delimited CSV values.
	 */
	public function test_build_source_file_pairs(): void {
		$importer = new Form_Importer( new Form_Repository(), new Form_File_Manager() );

		$pairs = $importer->build_source_file_pairs(
			array(
				'https://example.com/ud-12.pdf',
				'https://example.com/ud-12-fillable.pdf',
				'https://example.com/ud-12.wpd',
			),
			array(
				'ud-12.pdf',
				'ud-12-fillable.pdf',
				'ud-12.wpd',
			)
		);

		$this->assertCount( 3, $pairs );
		$this->assertSame( 'ud-12.wpd', $pairs[2]['filename'] );
	}

	/**
	 * Duplicate URLs within a row are deduplicated.
	 */
	public function test_build_source_file_pairs_deduplicates_urls(): void {
		$importer = new Form_Importer( new Form_Repository(), new Form_File_Manager() );

		$pairs = $importer->build_source_file_pairs(
			array(
				'https://example.com/ud-12.pdf',
				'https://example.com/ud-12.pdf',
			),
			array(
				'ud-12.pdf',
				'ud-12-copy.pdf',
			)
		);

		$this->assertCount( 1, $pairs );
	}

	/**
	 * Primary PDF selection prefers the first PDF entry.
	 */
	public function test_select_primary_pdf(): void {
		$importer = new Form_Importer( new Form_Repository(), new Form_File_Manager() );

		$primary = $importer->select_primary_pdf(
			array(
				array(
					'filename'        => 'ud-12.wpd',
					'extension'       => 'wpd',
					'source_url'      => 'https://example.com/ud-12.wpd',
					'local_url'       => 'http://site.test/forms/ud-12/original/ud-12.wpd',
					'download_status' => 'success',
				),
				array(
					'filename'        => 'ud-12.pdf',
					'extension'       => 'pdf',
					'source_url'      => 'https://example.com/ud-12.pdf',
					'local_url'       => 'http://site.test/forms/ud-12/original/ud-12.pdf',
					'download_status' => 'success',
				),
			)
		);

		$this->assertSame( 'https://example.com/ud-12.pdf', $primary['url'] );
		$this->assertSame( 'ud-12.pdf', $primary['filename'] );
	}

	/**
	 * Failed entries are skipped when choosing the primary PDF.
	 */
	public function test_select_primary_pdf_skips_failed(): void {
		$importer = new Form_Importer( new Form_Repository(), new Form_File_Manager() );

		$primary = $importer->select_primary_pdf(
			array(
				array(
					'filename'        => 'ud-12.pdf',
					'extension'       => 'pdf',
					'source_url'      => 'https://example.com/ud-12.pdf',
					'download_status' => 'failed',
				),
				array(
					'filename'        => 'ud-12-fillable.pdf',
					'extension'       => 'pdf',
					'source_url'      => 'https://example.com/ud-12-fillable.pdf',
					'local_url'       => 'http://site.test/forms/ud-12/original/ud-12-fillable.pdf',
					'download_status' => 'success',
				),
			)
		);

		$this->assertSame( 'https://example.com/ud-12-fillable.pdf', $primary['url'] );
	}
}
