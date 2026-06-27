<?php
$_SERVER['HTTP_HOST']      = 'proseny.local';
$_SERVER['REQUEST_URI']    = '/wp-admin/admin.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL']= 'HTTP/1.1';

chdir( dirname( __DIR__, 5 ) );
ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

require 'wp-load.php';

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

echo 'user=' . wp_get_current_user()->user_login . PHP_EOL;
echo 'can_manage=' . ( current_user_can( 'manage_options' ) ? 'yes' : 'no' ) . PHP_EOL;

// Load admin bootstrap without redirect noise.
define( 'WP_ADMIN', true );
require_once ABSPATH . 'wp-admin/includes/admin.php';

auth_redirect(); // would redirect if not logged in - we're logged in via wp_set_current_user

echo 'past_auth_redirect=yes' . PHP_EOL;
