<?php
/**
 * Plugin Name:       WPCore Plugin Manager
 * Plugin URI:        https://wpcore.com
 * Description:       See your WordPress sites on WPCore.com with their versions, plugins and themes, and install the plugins of the collections assigned to each site.
 * Version:           2.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            WPCore
 * Author URI:        https://profiles.wordpress.org/stueynet/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcore
 *
 * @package WPCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WPCore\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_path = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file          = __DIR__ . '/src/' . $relative_path . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

WPCore\Plugin::boot( __FILE__ );
