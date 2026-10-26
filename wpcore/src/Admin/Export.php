<?php
/**
 * Export links to wpcore.com.
 *
 * @package WPCore
 */

namespace WPCore\Admin;

/**
 * Builds wpcore.com links that create a new collection from the plugins on this site.
 */
final class Export {

	/**
	 * The wpcore.com page that creates a collection from comma-separated plugin slugs.
	 */
	const CREATE_URL = 'https://wpcore.com/collections/create/';

	/**
	 * Returns the link for the plugins active on this site, including network-activated plugins.
	 */
	public static function active_plugins_url(): string {
		$plugin_files = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$plugin_files = array_merge( $plugin_files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return self::url( $plugin_files );
	}

	/**
	 * Returns the link for every installed plugin.
	 */
	public static function all_plugins_url(): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return self::url( array_keys( get_plugins() ) );
	}

	/**
	 * Returns the slugs of plugin files. The slug is the plugin folder, or the file name without .php for a single-file plugin.
	 *
	 * @param string[] $plugin_files Plugin files relative to the plugins folder.
	 * @return string[]
	 */
	public static function slugs( array $plugin_files ): array {
		$slugs = array();

		foreach ( $plugin_files as $plugin_file ) {
			$folder = dirname( (string) $plugin_file );
			$slug   = '.' === $folder ? basename( (string) $plugin_file, '.php' ) : $folder;

			if ( '' !== $slug ) {
				$slugs[ $slug ] = $slug;
			}
		}

		return array_values( $slugs );
	}

	/**
	 * Returns the create link for a list of plugin files.
	 *
	 * @param string[] $plugin_files Plugin files relative to the plugins folder.
	 */
	private static function url( array $plugin_files ): string {
		return self::CREATE_URL . implode( ',', array_map( 'rawurlencode', self::slugs( $plugin_files ) ) );
	}
}
