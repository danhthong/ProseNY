<?php
/**
 * Probe WordPress bootstrap for fatal errors.
 */

$_SERVER['HTTP_HOST']       = 'proseny.local';
$_SERVER['REQUEST_URI']     = '/wp-admin/admin.php';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

$public = dirname( __DIR__, 5 );
chdir( $public );

ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

register_shutdown_function(
	static function (): void {
		$error = error_get_last();
		if ( null !== $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			echo 'SHUTDOWN_FATAL: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] . PHP_EOL;
		}
	}
);

echo 'STEP=load wp-load' . PHP_EOL;

require 'wp-load.php';

echo 'STEP=wp-load ok, ABSPATH=' . ABSPATH . PHP_EOL;
echo 'STEP=active plugins=' . implode( ',', (array) get_option( 'active_plugins', array() ) ) . PHP_EOL;

if ( function_exists( 'is_user_logged_in' ) ) {
	echo 'STEP=logged_in=' . ( is_user_logged_in() ? 'yes' : 'no' ) . PHP_EOL;
}

ob_start();
require 'wp-admin/admin.php';
$output = ob_get_clean();

echo 'STEP=admin output bytes=' . strlen( $output ) . PHP_EOL;
echo 'STEP=headers_sent=' . ( headers_sent() ? 'yes' : 'no' ) . PHP_EOL;

if ( strlen( $output ) > 0 ) {
	echo substr( $output, 0, 800 ) . PHP_EOL;
} else {
	echo 'STEP=admin body empty (likely redirect to login or fatal before output)' . PHP_EOL;
}
