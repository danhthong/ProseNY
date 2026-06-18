<?php
/**
 * Tests for Form_Record_Enricher asset computation.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Form_Record_Enricher;

/**
 * Class FormRecordEnricherTest
 */
class FormRecordEnricherTest extends TestCase {

	/**
	 * Readable pdf slot marks the record generation-ready.
	 */
	public function test_apply_computed_fields_marks_generation_ready(): void {
		$dir = sys_get_temp_dir() . '/prose-enricher-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0775, true );

		$pdf_path = $dir . '/ud-1.pdf';
		file_put_contents( $pdf_path, '%PDF-1.4 test' );

		$record = array(
			'form_code'     => 'UD-1',
			'source_files'  => array(
				'pdf' => array(
					'filename'        => 'ud-1.pdf',
					'path'            => $pdf_path,
					'source_url'      => '',
					'download_status' => 'success',
				),
			),
			'field_mapping_status' => 'unmapped',
		);

		$enriched = ( new Form_Record_Enricher() )->apply_computed_fields( $record );

		$this->assertTrue( $enriched['generation_ready'] );
		$this->assertSame( 'pdf', $enriched['preferred_source'] );
		$this->assertSame( 'complete', $enriched['import_status'] );

		unlink( $pdf_path );
		rmdir( $dir );
	}

	/**
	 * Field mapping status is preserved when writing through the enricher helper.
	 */
	public function test_preserve_field_mapping_status(): void {
		$dir  = sys_get_temp_dir() . '/prose-enricher-' . bin2hex( random_bytes( 4 ) );
		$path = $dir . '/ud-1.json';
		mkdir( $dir, 0775, true );

		file_put_contents(
			$path,
			wp_json_encode(
				array(
					'form_code'            => 'UD-1',
					'field_mapping_status' => 'mapped',
				)
			)
		);

		$record = array(
			'form_code'            => 'UD-1',
			'field_mapping_status' => 'unmapped',
		);

		$preserved = ( new Form_Record_Enricher() )->preserve_field_mapping_status( $record, $path );

		$this->assertSame( 'mapped', $preserved['field_mapping_status'] );

		unlink( $path );
		rmdir( $dir );
	}
}
