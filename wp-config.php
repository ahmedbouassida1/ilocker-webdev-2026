<?php
define('WP_CACHE', true); // WP-Optimize Cache
 // WP-Optimize Cache
 // WP-Optimize Cache
 // WP-Optimize Cache
 // WP-Optimize Cache
 
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */
// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'ptctkasilocker');
/** MySQL database username */
define('DB_USER', 'ptctkasilocker');
/** MySQL database password */
define('DB_PASSWORD', 'cZdQrWCITWzvurzs7s0DNuw');
/** MySQL hostname */
define('DB_HOST', 'ptctkasilocker.mysql.db:3306');
/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');
/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');
/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         'JLBwYflkIJ6z0JSc2iJ3AebfkU+ZBn4jegAlD4tLFf41nat2UfXEm+Or0R3P');
define('SECURE_AUTH_KEY',  'HABsZLtuRCY+v7dMe8eud2kUbfgIWqY0nt0Jv54keXqPGiSSRESNBwrN7jsi');
define('LOGGED_IN_KEY',    'nr96wSuM9yVZ/g+AYvSIk3FADoXsNGufBncgcsT8Xxl4O9jVlMosLRIxxYy9');
define('NONCE_KEY',        'SulIhDYDlZEPeE9hPeTN3WE4oAssdUjz5y4HmR1LyivwB95lfqfSd1ZC0s+z');
define('AUTH_SALT',        'mVPVe0DM5VZeem2kuT26GVV17fiwBuoi9//aHXrl5OBcpFdycGfwwe/8wA7E');
define('SECURE_AUTH_SALT', 'HUYd9RSQPvdFonzrYvTSwGbIIXLKiywIpsW1vh+viBrH6WMN26/a08tttRj4');
define('LOGGED_IN_SALT',   'W0GNi1ZR2kF2dJ4z9XEl+HHWpWoFgIW5fzGw0zJ6PzY9sh/bvgHQ+ui3WTNx');
define('NONCE_SALT',       'k/tKuR0unfy6DoxsCpI2L8O9tYkVI4H+brTkUK1g+XbgsehozHrk6GBrvNx/');
define('ILOCKER_TURNSTILE_SITE', '0x4AAAAAACN0Qo38uv3KmfBA');
define('ILOCKER_TURNSTILE_SECRET', '0x4AAAAAACN0QiAvL3pZ0xMnRa627M3VGnw');

/**#@-*/
/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'wor2249_';
/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define('WP_DEBUG', false);
define('WP_HOME', 'https://www.dev.ilocker.com.tn');
define('WP_SITEURL', 'https://www.dev.ilocker.com.tn');
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );
define('ILOCKER_RESET_FROM_EMAIL', 'no-reply@ilocker.com.tn');
define('ILOCKER_RESET_FROM_NAME', 'iLocker');
/* That's all, stop editing! Happy blogging. */
/** Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');
/* Fixes "Add media button not working", see http://www.carnfieldwebdesign.co.uk/blog/wordpress-fix-add-media-button-not-working/ */
define('CONCATENATE_SCRIPTS', false );
/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');