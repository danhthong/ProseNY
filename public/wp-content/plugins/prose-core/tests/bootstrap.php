<?php
/**
 * PHPUnit bootstrap for prose-core unit tests.
 *
 * @package ProSeCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'PROSE_CORE_PATH' ) ) {
	define( 'PROSE_CORE_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str String.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * @param string $string URL or path.
	 * @return string
	 */
	function trailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type Type.
	 * @return string
	 */
	function current_time( $type ) {
		unset( $type );
		return gmdate( 'Y-m-d H:i:s' );
	}
}

require_once PROSE_CORE_PATH . 'includes/class-autoloader.php';
ProSe\Core\Autoloader::register();
