<?php
/**
 * Filesystem paths for Forms Repository JSON records.
 *
 * @package ProSeCore
 */

namespace ProSe\Core\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Record_Paths
 */
final class Form_Record_Paths {

	/**
	 * Repository base directory (trailing slash).
	 *
	 * @return string
	 */
	public static function base_dir(): string {
		return trailingslashit( PROSE_CORE_PATH . 'docs/forms' );
	}

	/**
	 * JSON path for a form record.
	 *
	 * @param string $court     Court key.
	 * @param string $form_code Form code.
	 * @return string
	 */
	public static function record_path( string $court, string $form_code ): string {
		$court_dir = in_array( $court, array( 'supreme_court', 'family_court' ), true ) ? $court : 'family_court';

		return self::base_dir() . $court_dir . '/' . self::form_filename( $form_code );
	}

	/**
	 * Locate an existing record file for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string Empty when not found.
	 */
	public static function find_existing_path( string $form_code ): string {
		foreach ( array( 'supreme_court', 'family_court' ) as $court ) {
			$path = self::record_path( $court, $form_code );

			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Build a safe JSON filename for a form code.
	 *
	 * @param string $form_code Form code.
	 * @return string
	 */
	public static function form_filename( string $form_code ): string {
		$slug = strtolower( $form_code );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );

		return ( '' !== $slug ? $slug : 'form' ) . '.json';
	}
}
