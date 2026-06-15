<?php
/**
 * PSR-4-style autoloader for ProSe Core.
 *
 * Maps ProSe\Core\* classes to includes/ and modules/ directories.
 *
 * @package ProSeCore
 */

namespace ProSe\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Autoloader
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Autoload a class file.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	public static function autoload( string $class ): void {
		if ( strpos( $class, 'ProSe\\Core\\' ) !== 0 ) {
			return;
		}

		$relative   = substr( $class, strlen( 'ProSe\\Core\\' ) );
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts );
		$slug       = strtolower( str_replace( '_', '-', $class_name ) );

		$search_paths = array(
			PROSE_CORE_PATH . 'includes/class-' . $slug . '.php',
			PROSE_CORE_PATH . 'includes/interface-' . $slug . '.php',
		);

		if ( str_ends_with( $class_name, '_Interface' ) ) {
			$interface_base   = strtolower( str_replace( '_', '-', substr( $class_name, 0, -strlen( '_Interface' ) ) ) );
			$search_paths[] = PROSE_CORE_PATH . 'includes/interface-' . $interface_base . '.php';
		}

		if ( ! empty( $parts ) ) {
			$module_dir = strtolower( implode( '/', $parts ) );
			$search_paths[] = PROSE_CORE_PATH . 'modules/' . $module_dir . '/class-' . $slug . '.php';

			if ( str_ends_with( $class_name, '_Interface' ) ) {
				$interface_base   = strtolower( str_replace( '_', '-', substr( $class_name, 0, -strlen( '_Interface' ) ) ) );
				$search_paths[] = PROSE_CORE_PATH . 'modules/' . $module_dir . '/interface-' . $interface_base . '.php';
			}

			$hyphen_dir = str_replace( '_', '-', $module_dir );
			if ( $hyphen_dir !== $module_dir ) {
				$search_paths[] = PROSE_CORE_PATH . 'modules/' . $hyphen_dir . '/class-' . $slug . '.php';

				if ( str_ends_with( $class_name, '_Interface' ) ) {
					$interface_base   = strtolower( str_replace( '_', '-', substr( $class_name, 0, -strlen( '_Interface' ) ) ) );
					$search_paths[] = PROSE_CORE_PATH . 'modules/' . $hyphen_dir . '/interface-' . $interface_base . '.php';
				}
			}
		}

		foreach ( $search_paths as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
