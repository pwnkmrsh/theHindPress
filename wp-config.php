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
 * * ABSPATH
 *
 * @link https://wordpress.org/documentation/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'nukkadne_thehindpress' );

/** MySQL database username */
define( 'DB_USER', 'nukkadne_pawan' );

/** MySQL database password */
define( 'DB_PASSWORD', 'b%@vyq-xBQcd' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

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
define( 'AUTH_KEY',         '&!]tr/{! r@RY8k8ik7-}HkD]:L/RRB)p1D F80!~T#h-{E9Bww(plF:_l=.eOuE' );
define( 'SECURE_AUTH_KEY',  ']Kt%_P.>eaTYXt2qLwpR4x|lC5z;rOp3Xm,!k;/*@XZU4`1Yg)Gm6PLxO-:4gVoY' );
define( 'LOGGED_IN_KEY',    'l@u6kN[l8lw8P0KLZ$2J[KLyn7qL,/SvE`J)yM8L}hOT9SmeVL5,QN;|pyJkxXBo' );
define( 'NONCE_KEY',        '`3hO@m;m 7?|0bXK IH8VI >JR O~(Koas/:d_694>VyT-D?KWj$0ZzR|PYTYsyW' );
define( 'AUTH_SALT',        ')bN?k2+G]QpIAB=GI#b[0EbS}+. B{u7>u+{T7T7_M|k#rran|2/Aamh*n38JvJm' );
define( 'SECURE_AUTH_SALT', 'yg;N+o;~ev&Udnv9[0N1,aW`T)]>eqk-t5Zv[kCOn<H(L)N$i&}l@&ifHs&olZ(8' );
define( 'LOGGED_IN_SALT',   'FbxV~-`S2?W~^*{f5L+W.2OT_XpV+A-nGvgS-)*JU(FwBNu+fT EW4brg:nl_Q= ' );
define( 'NONCE_SALT',       '`Gbf^iu3]f|^3RQG_`Po/J}r TfvHZR8cwv2h:oP7^!CBBne$ld4DQ5`GIr%85bc' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'mi_';

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
 * @link https://wordpress.org/documentation/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
