<?php
chdir( dirname( __DIR__, 5 ) );
require 'wp-load.php';

echo 'siteurl=' . get_option( 'siteurl' ) . PHP_EOL;
echo 'home=' . get_option( 'home' ) . PHP_EOL;

foreach ( get_users( array( 'number' => 10 ) ) as $user ) {
	echo 'user=' . $user->user_login . ' roles=' . implode( ',', $user->roles ) . PHP_EOL;
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! empty( $admin[0] ) ) {
	wp_set_current_user( $admin[0]->ID );
	echo 'simulated_admin=' . $admin[0]->user_login . PHP_EOL;
	echo 'can_manage_options=' . ( current_user_can( 'manage_options' ) ? 'yes' : 'no' ) . PHP_EOL;
}
