<?php
/**
 * Tests for Form_File_Manager multi-file helpers.
 *
 * @package ProSeCore
 */

use PHPUnit\Framework\TestCase;
use ProSe\Core\Forms\Form_File_Manager;

/**
 * Class FormFileManagerTest
 */
class FormFileManagerTest extends TestCase {

	/**
	 * Supported extensions include court document formats.
	 */
	public function test_is_supported_extension(): void {
		$manager = new Form_File_Manager();

		$this->assertTrue( $manager->is_supported_extension( 'pdf' ) );
		$this->assertTrue( $manager->is_supported_extension( 'wpd' ) );
		$this->assertTrue( $manager->is_supported_extension( 'docx' ) );
		$this->assertFalse( $manager->is_supported_extension( 'exe' ) );
	}

	/**
	 * Skip download when URL already succeeded in metadata and file exists.
	 */
	public function test_should_skip_download_for_existing_url(): void {
		$manager = new Form_File_Manager();
		$tmpdir  = sys_get_temp_dir() . '/prose-test-' . uniqid( '', true );
		mkdir( $tmpdir, 0777, true );
		$dest = $tmpdir . '/ud-12.pdf';
		file_put_contents( $dest, 'pdf-bytes' );

		$existing = array(
			array(
				'source_url'      => 'https://example.com/ud-12.pdf',
				'download_status' => 'success',
				'local_path'      => $dest,
			),
		);

		$this->assertTrue(
			$manager->should_skip_download(
				'https://example.com/ud-12.pdf',
				$dest,
				$existing
			)
		);

		unlink( $dest );
		rmdir( $tmpdir );
	}

	/**
	 * Skip download when destination file already exists.
	 */
	public function test_should_skip_download_when_dest_exists(): void {
		$manager = new Form_File_Manager();
		$tmpdir  = sys_get_temp_dir() . '/prose-test-' . uniqid( '', true );
		mkdir( $tmpdir, 0777, true );
		$dest = $tmpdir . '/ud-12.wpd';
		file_put_contents( $dest, 'wpd-bytes' );

		$this->assertTrue(
			$manager->should_skip_download(
				'https://example.com/ud-12.wpd',
				$dest,
				array()
			)
		);

		unlink( $dest );
		rmdir( $tmpdir );
	}

	/**
	 * Legacy flat adoption copies an existing uploads/prose/forms/{filename} file.
	 */
	public function test_adopt_legacy_flat_file(): void {
		$manager    = new Form_File_Manager();
		$upload_dir = $manager->get_upload_dir();
		$this->assertIsArray( $upload_dir );

		$legacy_name = 'prose-adopt-test-' . uniqid( '', true ) . '.pdf';
		$legacy_path = $upload_dir['path'] . $legacy_name;
		file_put_contents( $legacy_path, '%PDF-1.4 test' );

		$adopted = $manager->adopt_legacy_flat_file(
			'https://example.com/' . $legacy_name,
			'adopt-test',
			$legacy_name
		);

		$this->assertIsArray( $adopted );
		$this->assertSame( 'success', $adopted['download_status'] );
		$this->assertTrue( is_readable( (string) $adopted['local_path'] ) );

		unlink( $legacy_path );
		if ( is_readable( (string) $adopted['local_path'] ) ) {
			unlink( (string) $adopted['local_path'] );
		}
	}
}
