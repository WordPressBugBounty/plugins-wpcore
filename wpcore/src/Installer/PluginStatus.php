<?php
/**
 * Installed state of a plugin.
 *
 * @package WPCore
 */

namespace WPCore\Installer;

/**
 * Finds a plugin on this site by slug and reports whether it is installed and active.
 */
final class PluginStatus {

	/**
	 * The plugin is not installed.
	 */
	const NOT_INSTALLED = 'not_installed';

	/**
	 * The plugin is installed and not active.
	 */
	const INACTIVE = 'inactive';

	/**
	 * The plugin is active on this site or on the network.
	 */
	const ACTIVE = 'active';

	/**
	 * Returns the plugin file, relative to the plugins folder, of the installed plugin with a slug.
	 *
	 * A plugin matches when its folder name is the slug, as for plugins installed from WordPress.org,
	 * or when it is a single file named <slug>.php in the plugins folder.
	 *
	 * @param string $slug Plugin slug.
	 */
	public static function find_plugin_file( string $slug ): ?string {
		self::load_plugin_functions();

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			$folder = dirname( $plugin_file );

			if ( $folder === $slug || ( '.' === $folder && basename( $plugin_file, '.php' ) === $slug ) ) {
				return $plugin_file;
			}
		}

		return null;
	}

	/**
	 * Returns NOT_INSTALLED, INACTIVE or ACTIVE for a plugin slug.
	 *
	 * @param string $slug Plugin slug.
	 */
	public static function of( string $slug ): string {
		$plugin_file = self::find_plugin_file( $slug );

		if ( null === $plugin_file ) {
			return self::NOT_INSTALLED;
		}

		return is_plugin_active( $plugin_file ) ? self::ACTIVE : self::INACTIVE;
	}

	/**
	 * Loads get_plugins() and is_plugin_active(), which WordPress loads only in the admin.
	 */
	private static function load_plugin_functions(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
