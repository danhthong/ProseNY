<?php
/**
 * Probe wp-admin render as administrator.
 */

$_SERVER['HTTP_HOST']       = 'proseny.local';
$_SERVER['REQUEST_URI']     = '/wp-admin/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

chdir( dirname( __DIR__, 5 ) );

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

register_shutdown_function(
	static function (): void {
		$error = error_get_last();
		if ( null !== $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			file_put_contents(
				__DIR__ . '/_admin_probe_fatal.txt',
				$error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . PHP_EOL
			);
		}
	}
);

require 'wp-load.php';

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $admin[0] ) ) {
	file_put_contents( __DIR__ . '/_admin_probe_fatal.txt', 'no admin user' );
	exit( 1 );
}

wp_set_current_user( $admin[0]->ID );
wp_set_auth_cookie( $admin[0]->ID );

ob_start();
require 'wp-admin/index.php';
$output = ob_get_clean();

file_put_contents( __DIR__ . '/_admin_probe_output.html', $output );
file_put_contents(
	__DIR__ . '/_admin_probe_fatal.txt',
	'bytes=' . strlen( $output ) . PHP_EOL . 'title=' . ( preg_match( '/<title>([^<]+)</', $output, $m ) ? $m[1] : 'none' ) . PHP_EOL
);
