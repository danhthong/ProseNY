<?php
/**
 * Unit tests for Form_Source_Selector.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Form_Source_Selector;

/**
 * Class FormSourceSelectorTest
 */
class FormSourceSelectorTest extends TestCase {

	/**
	 * DOCX is preferred over fillable PDF and static PDF.
	 *
	 * @return void
	 */
	public function test_prefers_docx_over_pdf(): void {
		$docx = tempnam( sys_get_temp_dir(), 'ud2' ) . '.docx';
		$pdf  = tempnam( sys_get_temp_dir(), 'ud2' ) . '.pdf';
		file_put_contents( $docx, 'docx' );
		file_put_contents( $pdf, '%PDF' );

		$source_files = array(
			'docx' => array(
				'filename'        => 'ud-2.docx',
				'path'            => $docx,
				'source_url'      => 'https://example.test/ud-2.docx',
				'download_status' => 'success',
			),
			'pdf'  => array(
				'filename'        => 'ud-2.pdf',
				'path'            => $pdf,
				'source_url'      => 'https://example.test/ud-2.pdf',
				'download_status' => 'success',
			),
		);

		$result = Form_Source_Selector::compute( $source_files );

		$this->assertSame( 'docx', $result['preferred_source'] );
		$this->assertSame( 'docx_template', $result['fillable_strategy'] );
		$this->assertTrue( $result['generation_ready'] );

		@unlink( $docx );
		@unlink( $pdf );
	}

	/**
	 * Converted DOCX is used when native DOCX is unavailable.
	 *
	 * @return void
	 */
	public function test_prefers_converted_docx_over_fillable_pdf(): void {
		$source_files = array(
			'converted_docx' => array(
				'filename'        => 'ud-12.docx',
				'path'            => '/tmp/ud-12.docx',
				'source_url'      => '',
				'download_status' => 'success',
			),
			'fillable_pdf'   => array(
				'filename'        => 'fillable-ud-12.pdf',
				'path'            => '/tmp/fillable-ud-12.pdf',
				'source_url'      => 'https://example.test/fillable-ud-12.pdf',
				'download_status' => 'success',
			),
		);

		$result = Form_Source_Selector::compute( $source_files );

		$this->assertSame( 'converted_docx', $result['preferred_source'] );
		$this->assertSame( 'docx_template', $result['fillable_strategy'] );
	}

	/**
	 * Static PDF falls back to overlay strategy.
	 *
	 * @return void
	 */
	public function test_pdf_overlay_when_only_static_pdf_exists(): void {
		$source_files = array(
			'pdf' => array(
				'filename'        => 'gf-17.pdf',
				'path'            => '/tmp/gf-17.pdf',
				'source_url'      => 'https://example.test/gf-17.pdf',
				'download_status' => 'success',
			),
		);

		$result = Form_Source_Selector::compute( $source_files );

		$this->assertSame( 'pdf', $result['preferred_source'] );
		$this->assertSame( 'pdf_overlay', $result['fillable_strategy'] );
	}

	/**
	 * No usable source yields none strategy and generation_ready false.
	 *
	 * @return void
	 */
	public function test_none_when_no_sources(): void {
		$result = Form_Source_Selector::compute( array() );

		$this->assertSame( '', $result['preferred_source'] );
		$this->assertSame( 'none', $result['fillable_strategy'] );
		$this->assertFalse( $result['generation_ready'] );
	}
}
