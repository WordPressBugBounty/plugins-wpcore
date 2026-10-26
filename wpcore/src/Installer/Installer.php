<?php
/**
 * Plugin installer.
 *
 * @package WPCore
 */

namespace WPCore\Installer;

use Plugin_Upgrader;
use WP_Ajax_Upgrader_Skin;
use WP_Error;
use WPCore\Api\CollectionPlugin;

/**
 * Installs and activates one plugin from a collection with WordPress core's Plugin_Upgrader.
 */
final class Installer {

	/**
	 * Installs a plugin when it is not installed, and activates it when asked.
	 *
	 * A plugin that is already installed is not downloaded again, unless $overwrite is true.
	 *
	 * @param CollectionPlugin $plugin       Plugin from a collection.
	 * @param bool             $activate     True to activate the plugin after installing it.
	 * @param bool             $network_wide True to activate the plugin for the whole multisite network.
	 * @param bool             $overwrite    True to download an installed plugin again and replace its files.
	 * @return array{slug: string, plugin_file: string, installed: bool, activated: bool}|WP_Error
	 *         "installed" and "activated" are true when this call installed or activated the plugin.
	 */
	public function install( CollectionPlugin $plugin, bool $activate = false, bool $network_wide = false, bool $overwrite = false ) {
		$plugin_file = PluginStatus::find_plugin_file( $plugin->slug() );
		$installed   = false;

		if ( null === $plugin_file || $overwrite ) {
			$package = $this->package_url( $plugin );

			if ( is_wp_error( $package ) ) {
				return $package;
			}

			$plugin_file = $this->install_package( $package, null !== $plugin_file );

			if ( is_wp_error( $plugin_file ) ) {
				return $plugin_file;
			}

			$installed = true;
		}

		$activated = false;

		if ( $activate && ! $this->is_active( $plugin_file, $network_wide ) ) {
			$result = activate_plugin( $plugin_file, '', $network_wide );

			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'wpcore_activation_failed',
					/* translators: 1: plugin name, 2: error message. */
					sprintf( __( '%1$s was installed but could not be activated: %2$s', 'wpcore' ), $plugin->name(), $result->get_error_message() )
				);
			}

			$activated = true;
		}

		return array(
			'slug'        => $plugin->slug(),
			'plugin_file' => $plugin_file,
			'installed'   => $installed,
			'activated'   => $activated,
		);
	}

	/**
	 * Returns the ZIP URL of a plugin: the WordPress.org download link, or the source URL of a custom plugin.
	 *
	 * @param CollectionPlugin $plugin Plugin from a collection.
	 * @return string|WP_Error
	 */
	private function package_url( CollectionPlugin $plugin ) {
		if ( ! $plugin->is_from_wordpress_org() ) {
			$source = (string) $plugin->source();
			$scheme = wp_parse_url( $source, PHP_URL_SCHEME );
			$host   = wp_parse_url( $source, PHP_URL_HOST );

			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $host ) ) {
				return new WP_Error(
					'wpcore_invalid_source',
					/* translators: %s: plugin name. */
					sprintf( __( 'The download URL of %s is not an HTTP or HTTPS URL.', 'wpcore' ), $plugin->name() )
				);
			}

			return $source;
		}

		$this->load_admin_files();

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $plugin->slug(),
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
			return new WP_Error(
				'wpcore_not_on_wordpress_org',
				/* translators: %s: plugin slug. */
				sprintf( __( 'The plugin %s was not found in the WordPress.org plugin directory.', 'wpcore' ), $plugin->slug() )
			);
		}

		return $api->download_link;
	}

	/**
	 * Downloads and unpacks a plugin ZIP into the plugins folder.
	 *
	 * Output printed during the install is discarded, so it cannot reach a REST response.
	 * Plugin_Upgrader can leave an output buffer open when the install fails. Every buffer opened during the call is closed.
	 *
	 * @param string $package   ZIP URL.
	 * @param bool   $overwrite True to replace the files of an installed plugin.
	 * @return string|WP_Error The installed plugin file, relative to the plugins folder.
	 */
	private function install_package( string $package, bool $overwrite ) {
		$this->load_admin_files();

		$skin         = new WP_Ajax_Upgrader_Skin();
		$upgrader     = new Plugin_Upgrader( $skin );
		$buffer_level = ob_get_level();

		ob_start();
		$installed = $upgrader->install( $package, array( 'overwrite_package' => $overwrite ) );

		while ( ob_get_level() > $buffer_level ) {
			ob_end_clean();
		}

		if ( is_wp_error( $installed ) ) {
			return new WP_Error( 'wpcore_install_failed', $installed->get_error_message() );
		}

		if ( $skin->get_errors()->has_errors() ) {
			return new WP_Error( 'wpcore_install_failed', $skin->get_error_messages() );
		}

		if ( null === $installed ) {
			return new WP_Error(
				'wpcore_filesystem_unavailable',
				__( 'WordPress could not write to the plugins folder. Check the folder permissions or the FS_METHOD setting.', 'wpcore' )
			);
		}

		$plugin_file = $upgrader->plugin_info();

		if ( ! $installed || ! $plugin_file ) {
			return new WP_Error( 'wpcore_install_failed', __( 'The downloaded file does not contain a WordPress plugin.', 'wpcore' ) );
		}

		return $plugin_file;
	}

	/**
	 * Checks whether a plugin is active on this site, or on the network when network-wide activation is asked for.
	 *
	 * @param string $plugin_file  Plugin file relative to the plugins folder.
	 * @param bool   $network_wide True to check network activation.
	 */
	private function is_active( string $plugin_file, bool $network_wide ): bool {
		return $network_wide ? is_plugin_active_for_network( $plugin_file ) : is_plugin_active( $plugin_file );
	}

	/**
	 * Loads the WordPress admin files that define Plugin_Upgrader, plugins_api() and the filesystem functions.
	 */
	private function load_admin_files(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}
}
