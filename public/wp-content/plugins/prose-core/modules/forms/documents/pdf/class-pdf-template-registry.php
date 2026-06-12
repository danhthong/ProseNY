<?php
/**
 * PDF template registry — resolves a form code to its PDF template.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Template_Registry
 *
 * Maps a form_code to its PDF template descriptor:
 *   - form_code        the document model form code (e.g. UD-1)
 *   - template_version the template revision used for rendering
 *   - template_path    the source template file (an AcroForm PDF, when one
 *                      exists) the renderer would fill
 *   - renderer_type    how the form is produced (builtin text renderer or a
 *                      template-fill / acroform renderer)
 *
 * Unknown form codes resolve to a builtin descriptor so rendering never hard
 * fails on a form that has no registered template.
 */
final class Pdf_Template_Registry {

	// Renderer strategies.
	public const RENDERER_BUILTIN  = 'builtin';
	public const RENDERER_ACROFORM = 'acroform';

	/**
	 * Directory holding template source PDFs.
	 *
	 * @var string
	 */
	private string $template_dir;

	/**
	 * Constructor.
	 *
	 * @param string $template_dir Template directory (trailing slash optional).
	 */
	public function __construct( string $template_dir = '' ) {
		if ( '' === $template_dir && defined( 'PROSE_CORE_PATH' ) ) {
			$template_dir = PROSE_CORE_PATH . 'modules/forms/documents/pdf/templates/';
		}

		$this->template_dir = '' === $template_dir ? '' : rtrim( $template_dir, '/\\' ) . '/';
	}

	/**
	 * Registered template versions keyed by form code.
	 *
	 * @return array<string, string>
	 */
	private function versions(): array {
		return array(
			'UD-1' => '1.0',
			'UD-2' => '1.0',
			'UD-3' => '1.0',
			'UD-4' => '1.0',
			'UD-5' => '1.0',
			'UD-6' => '1.0',
			'UD-7' => '1.0',
			'UD-8' => '1.0',
			'FC-1' => '1.0',
			'FC-2' => '1.0',
			'FC-3' => '1.0',
			'FC-7' => '1.0',
		);
	}

	/**
	 * All registered templates keyed by form code.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function templates(): array {
		$templates = array();

		foreach ( $this->versions() as $form_code => $version ) {
			$templates[ $form_code ] = $this->descriptor( $form_code, $version );
		}

		return $templates;
	}

	/**
	 * Whether a form code has a registered template.
	 *
	 * @param string $form_code Form code.
	 * @return bool
	 */
	public function has( string $form_code ): bool {
		return array_key_exists( $form_code, $this->versions() );
	}

	/**
	 * Resolve a form code to its template descriptor.
	 *
	 * @param string $form_code Form code.
	 * @return array<string, string>
	 */
	public function resolve( string $form_code ): array {
		$versions = $this->versions();

		if ( isset( $versions[ $form_code ] ) ) {
			return $this->descriptor( $form_code, $versions[ $form_code ] );
		}

		return $this->descriptor( $form_code, '0.0' );
	}

	/**
	 * Template version for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	public function template_version( string $form_code ): string {
		return (string) $this->resolve( $form_code )['template_version'];
	}

	/**
	 * Template source path for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	public function template_path( string $form_code ): string {
		return (string) $this->resolve( $form_code )['template_path'];
	}

	/**
	 * Renderer type for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	public function renderer_type( string $form_code ): string {
		return (string) $this->resolve( $form_code )['renderer_type'];
	}

	/**
	 * Build a template descriptor.
	 *
	 * The renderer type is ACROFORM when a template PDF exists on disk and
	 * BUILTIN otherwise, so the renderer can fall back to the text engine.
	 *
	 * @param string $form_code Form code.
	 * @param string $version   Template version.
	 * @return array<string, string>
	 */
	private function descriptor( string $form_code, string $version ): array {
		$path          = '' === $this->template_dir ? '' : $this->template_dir . $form_code . '.pdf';
		$renderer_type = self::RENDERER_BUILTIN;

		if ( '' !== $path && is_readable( $path ) ) {
			$renderer_type = self::RENDERER_ACROFORM;
		}

		return array(
			'form_code'        => $form_code,
			'template_version' => $version,
			'template_path'    => $path,
			'renderer_type'    => $renderer_type,
		);
	}
}
