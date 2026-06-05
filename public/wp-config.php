<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'local' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          '`ZWLkea8=(&*f#,Z4qs%6UDv):B#Lb!B<^>YU.wWKRp,.EEymQo`.`A<tU-~)f:g' );
define( 'SECURE_AUTH_KEY',   ':Mo?kBPWNO.m#mUJW*KHU 9$F_uUFC6(g[#V,R;e|H,^_`-ee`o5DB<3=jwDwmU$' );
define( 'LOGGED_IN_KEY',     ':lzyu?`?Ddo&R5(1`c5!zh^E$;b1>E^|:DdNC`V.t>P_aDEd}WS^;2L3foW0t5*q' );
define( 'NONCE_KEY',         'fG.1u{qCFRgV>ykcKd<eQ|)esxV3s1>_hZvW6o<o)uG?dHbWcaO^}j0L`U$MnOCC' );
define( 'AUTH_SALT',         'gQ@8:PpE6FnVH|::(Zw/OEJt-jv<=bNJ$>Kpzaf$_JIZmpMq/d(T}qHy>ID+jrT/' );
define( 'SECURE_AUTH_SALT',  'j2W;4xF_^:%BZA?NU?8a8Q2H@OPExDHoDN4Yd2$WBN%#&(0B3o_j?xDNWaQpE3JD' );
define( 'LOGGED_IN_SALT',    '7_D@zgqul^$3|rY]r;t-mnfO0LQc%W~TlluYSzU.c,`@$XQaNH|agXjF,G1%=7ui' );
define( 'NONCE_SALT',        'm]@>*qCE{&jE]``DQlsa;m^D.RqA=gJ]5eaOs~dz%*CjZ{35Q{EF7*H *|:6[$ri' );
define( 'WP_CACHE_KEY_SALT', 'M%LN1T[kmF)hfM9$exP;@fpnRBWr&4W#fmf0Kr+5QrSdYh*!SE&FdCV)~=vC(vP*' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
// if ( ! defined( 'WP_DEBUG' ) ) {
// 	define( 'WP_DEBUG', false );
// }

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', true );

define( 'WP_ENVIRONMENT_TYPE', 'local' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
