<?php
/**
 * Selects the best available PDF engine (Python sidecar or pure PHP).
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pdf_Engine_Factory
 */
final class Pdf_Engine_Factory {

	/**
	 * Cached engine instance.
	 *
	 * @var Pdf_Engine_Interface|null
	 */
	private static ?Pdf_Engine_Interface $engine = null;

	/**
	 * Get the active PDF engine.
	 *
	 * @return Pdf_Engine_Interface
	 */
	public static function get_engine(): Pdf_Engine_Interface {
		if ( null !== self::$engine ) {
			return self::$engine;
		}

		/**
		 * Force a specific PDF engine: php or python.
		 *
		 * @param string|null $forced Engine id or null for auto-detect.
		 */
		$forced = apply_filters( 'prose_core_pdf_engine', null );

		$python = new Python_Pdf_Engine();
		$php    = new Php_Pdf_Engine();

		if ( 'php' === $forced ) {
			self::$engine = $php;
			return self::$engine;
		}

		if ( 'python' === $forced && $python->is_available() ) {
			self::$engine = $python;
			return self::$engine;
		}

		if ( $python->is_available() ) {
			self::$engine = $python;
			return self::$engine;
		}

		self::$engine = $php;

		return self::$engine;
	}

	/**
	 * Reset cached engine (for tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$engine = null;
	}
}
