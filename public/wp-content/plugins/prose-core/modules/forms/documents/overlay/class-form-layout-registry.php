<?php
/**
 * Form layout registry — resolve a form code to its coordinate map.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Documents\Overlay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Layout_Registry
 *
 * Maps a form code (e.g. UD-1) to its layout JSON file under the layouts
 * directory and loads the normalized coordinate map via Coordinate_Map_Loader.
 */
final class Form_Layout_Registry {

	/**
	 * Layouts directory (trailing slash).
	 *
	 * @var string
	 */
	private string $layouts_dir;

	/**
	 * Coordinate map loader.
	 *
	 * @var Coordinate_Map_Loader
	 */
	private Coordinate_Map_Loader $loader;

	/**
	 * Constructor.
	 *
	 * @param string                     $layouts_dir Layouts directory.
	 * @param Coordinate_Map_Loader|null $loader      Map loader.
	 */
	public function __construct( string $layouts_dir = '', ?Coordinate_Map_Loader $loader = null ) {
		if ( '' === $layouts_dir && defined( 'PROSE_CORE_PATH' ) ) {
			$layouts_dir = PROSE_CORE_PATH . 'modules/forms/documents/overlay/layouts/';
		}

		$this->layouts_dir = '' === $layouts_dir ? '' : rtrim( $layouts_dir, '/\\' ) . '/';
		$this->loader      = $loader ?? new Coordinate_Map_Loader();
	}

	/**
	 * Layout directory.
	 *
	 * @return string
	 */
	public function layouts_dir(): string {
		return $this->layouts_dir;
	}

	/**
	 * Layout file path for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	public function path( string $form_code ): string {
		return '' === $this->layouts_dir ? '' : $this->layouts_dir . $form_code . '.json';
	}

	/**
	 * Whether a layout exists for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return bool
	 */
	public function has( string $form_code ): bool {
		$path = $this->path( $form_code );

		return '' !== $path && is_readable( $path );
	}

	/**
	 * Load the normalized layout for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When no layout exists.
	 */
	public function load( string $form_code ): array {
		if ( ! $this->has( $form_code ) ) {
			throw new \RuntimeException( 'No layout registered for form code: ' . $form_code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$layout = $this->loader->load( $this->path( $form_code ) );

		if ( '' === $layout['form_code'] ) {
			$layout['form_code'] = $form_code;
		}

		return $layout;
	}

	/**
	 * List the form codes with a registered layout.
	 *
	 * @return string[]
	 */
	public function codes(): array {
		if ( '' === $this->layouts_dir || ! is_dir( $this->layouts_dir ) ) {
			return array();
		}

		$codes = array();

		foreach ( (array) glob( $this->layouts_dir . '*.json' ) as $file ) {
			$codes[] = basename( (string) $file, '.json' );
		}

		sort( $codes );

		return $codes;
	}
}
