<?php
/**
 * Site report.
 *
 * @package WPCore
 */

namespace WPCore\Sites;

/**
 * Builds the report that WPCore.com shows on the site page.
 *
 * PLAN.md lists the report format. App\Modules\Sites\ReportData in stueynet/wpcore2026 validates it.
 */
final class Report {

	/**
	 * Longest name or version string that WPCore.com accepts.
	 */
	const MAX_LENGTH = 255;

	/**
	 * Values of WP_AUTO_UPDATE_CORE that allow major core updates.
	 */
	const MAJOR_CORE_VALUES = array( 'beta', 'rc', 'development', 'branch-development' );

	/**
	 * Returns the report.
	 *
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		self::load_admin_functions();

		$updater_disabled = ( new \WP_Automatic_Updater() )->is_disabled();

		return array(
			'wordpress'        => self::wordpress( $updater_disabled ),
			'server'           => self::server(),
			'plugins'          => self::plugins( $updater_disabled ),
			'must_use_plugins' => self::other_plugins( get_mu_plugins() ),
			'dropins'          => self::other_plugins( get_dropins() ),
			'themes'           => self::themes( $updater_disabled ),
		);
	}

	/**
	 * Loads the wp-admin files that define the plugin and update functions. REST and front-end requests do not load them.
	 */
	private static function load_admin_functions(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}
	}

	/**
	 * Returns the WordPress version, addresses and settings.
	 *
	 * @param bool $updater_disabled True when WP_Automatic_Updater::is_disabled() returns true.
	 * @return array<string, mixed>
	 */
	private static function wordpress( bool $updater_disabled ): array {
		$name  = trim( wp_strip_all_tags( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ) );
		$debug = self::constant( 'WP_DEBUG' );

		return array(
			'version'                    => (string) get_bloginfo( 'version' ),
			'multisite'                  => is_multisite(),
			'home_url'                   => home_url(),
			'admin_url'                  => admin_url(),
			'name'                       => '' === $name ? null : self::limit( $name ),
			'locale'                     => self::limit( (string) get_locale() ),
			'timezone'                   => self::limit( (string) wp_timezone_string() ),
			'environment_type'           => (string) wp_get_environment_type(),
			'update_version'             => self::core_update_version(),
			'auto_update'                => self::core_auto_update( $updater_disabled ),
			'https'                      => (bool) wp_is_using_https(),
			'search_engines_discouraged' => '0' === (string) get_option( 'blog_public', '1' ),
			'debug'                      => $debug,
			'debug_display'              => $debug && self::constant( 'WP_DEBUG_DISPLAY' ),
			'debug_log'                  => $debug && self::constant( 'WP_DEBUG_LOG' ),
			'file_mods_allowed'          => (bool) wp_is_file_mod_allowed( 'capability_update_core' ),
			'file_edit_allowed'          => ! self::constant( 'DISALLOW_FILE_EDIT' ) && (bool) wp_is_file_mod_allowed( 'capability_edit_themes' ),
			'cron_disabled'              => self::constant( 'DISABLE_WP_CRON' ),
			'last_post_modified'         => self::last_post_modified(),
		);
	}

	/**
	 * Returns the PHP, database and web server details.
	 *
	 * @return array<string, mixed>
	 */
	private static function server(): array {
		global $wpdb;

		// MariaDB servers that emulate MySQL 5.5 report "5.5.5-<version>-MariaDB". Site Health removes the same prefix.
		$database_info  = method_exists( $wpdb, 'db_server_info' ) ? (string) preg_replace( '/^5\.5\.5-/', '', (string) $wpdb->db_server_info() ) : '';
		$web_server     = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$memory_limit   = (string) ini_get( 'memory_limit' );
		$execution_time = (string) ini_get( 'max_execution_time' );

		return array(
			'php_version'        => PHP_VERSION,
			'database_server'    => '' === $database_info ? null : ( false !== stripos( $database_info, 'mariadb' ) ? 'MariaDB' : 'MySQL' ),
			'database_version'   => 1 === preg_match( '/\d+(\.\d+)*/', $database_info, $matches ) ? $matches[0] : null,
			'web_server'         => '' === $web_server ? null : self::limit( $web_server ),
			'memory_limit'       => '' === $memory_limit ? null : substr( $memory_limit, 0, 50 ),
			'max_execution_time' => '' === $execution_time ? null : (int) $execution_time,
			'max_upload_size'    => (int) wp_max_upload_size(),
			'object_cache'       => (bool) wp_using_ext_object_cache(),
		);
	}

	/**
	 * Returns every installed plugin with its status, auto-update setting and available update.
	 *
	 * @param bool $updater_disabled True when WP_Automatic_Updater::is_disabled() returns true.
	 * @return array<int, array<string, mixed>>
	 */
	private static function plugins( bool $updater_disabled ): array {
		$lists    = self::update_lists( 'update_plugins' );
		$settings = self::auto_update_settings( 'plugin', $updater_disabled );
		$plugins  = array();

		foreach ( get_plugins() as $file => $data ) {
			$file   = (string) $file;
			$slug   = self::plugin_slug( $file );
			$update = isset( $lists['response'][ $file ] ) ? (object) $lists['response'][ $file ] : null;
			$known  = null !== $update ? $update : ( isset( $lists['no_update'][ $file ] ) ? (object) $lists['no_update'][ $file ] : null );

			// The same properties as the item that WP_Plugins_List_Table passes to the auto_update_plugin filter.
			$item = (object) array_merge(
				array(
					'slug'        => $slug,
					'plugin'      => $file,
					'new_version' => '',
					'url'         => '',
					'package'     => '',
				),
				null === $known ? array() : (array) $known
			);

			$plugins[] = array(
				'file'                => self::limit( $file ),
				'slug'                => self::limit( $slug ),
				'name'                => self::limit( self::text( $data['Name'] ?? '', $file ) ),
				'version'             => self::nullable( $data['Version'] ?? '' ),
				'active'              => is_plugin_active( $file ),
				'network_active'      => is_multisite() && is_plugin_active_for_network( $file ),
				'wporg'               => null !== $known && 0 === strpos( (string) ( $known->id ?? '' ), 'w.org/plugins/' ),
				'auto_update'         => self::auto_update( 'plugin', $file, $item, $settings ),
				'update_version'      => null === $update ? null : self::nullable( $update->new_version ?? '' ),
				'update_requires_wp'  => null === $update ? null : self::nullable( $update->requires ?? '' ),
				'update_requires_php' => null === $update ? null : self::nullable( $update->requires_php ?? '' ),
				'update_tested'       => null === $update ? null : self::nullable( $update->tested ?? '' ),
			);
		}

		return $plugins;
	}

	/**
	 * Returns the slug of a plugin file. The slug is the plugin folder, or the file name without .php for a single-file plugin.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins folder.
	 */
	private static function plugin_slug( string $plugin_file ): string {
		$folder = dirname( $plugin_file );
		$slug   = '.' === $folder ? basename( $plugin_file, '.php' ) : $folder;

		return '' !== $slug ? $slug : $plugin_file;
	}

	/**
	 * Returns the file, name and version of each must-use plugin or drop-in.
	 *
	 * @param array<string, array<string, mixed>> $plugins Plugin data from get_mu_plugins() or get_dropins().
	 * @return array<int, array<string, mixed>>
	 */
	private static function other_plugins( array $plugins ): array {
		$list = array();

		foreach ( $plugins as $file => $data ) {
			$list[] = array(
				'file'    => self::limit( (string) $file ),
				'name'    => self::limit( self::text( $data['Name'] ?? '', (string) $file ) ),
				'version' => self::nullable( $data['Version'] ?? '' ),
			);
		}

		return $list;
	}

	/**
	 * Returns every installed theme with its status, auto-update setting and available update.
	 *
	 * @param bool $updater_disabled True when WP_Automatic_Updater::is_disabled() returns true.
	 * @return array<int, array<string, mixed>>
	 */
	private static function themes( bool $updater_disabled ): array {
		$lists    = self::update_lists( 'update_themes' );
		$settings = self::auto_update_settings( 'theme', $updater_disabled );
		$active   = get_stylesheet();
		$themes   = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;
			$template   = (string) $theme->get_template();
			$update     = isset( $lists['response'][ $stylesheet ] ) ? (object) $lists['response'][ $stylesheet ] : null;
			$known      = null !== $update ? $update : ( isset( $lists['no_update'][ $stylesheet ] ) ? (object) $lists['no_update'][ $stylesheet ] : null );

			// The same properties as the item that WP_MS_Themes_List_Table passes to the auto_update_theme filter.
			$item = (object) array_merge(
				array(
					'theme'        => $stylesheet,
					'new_version'  => '',
					'url'          => '',
					'package'      => '',
					'requires'     => '',
					'requires_php' => '',
				),
				null === $known ? array() : (array) $known
			);

			$themes[] = array(
				'slug'                => self::limit( $stylesheet ),
				'name'                => self::limit( self::text( (string) $theme->get( 'Name' ), $stylesheet ) ),
				'version'             => self::nullable( (string) $theme->get( 'Version' ) ),
				'active'              => $active === $stylesheet,
				'parent'              => '' === $template || $template === $stylesheet ? null : self::limit( $template ),
				'block_theme'         => (bool) $theme->is_block_theme(),
				'wporg'               => null !== $known && 0 === strpos( (string) ( $known->url ?? '' ), 'https://wordpress.org/themes/' ),
				'auto_update'         => self::auto_update( 'theme', $stylesheet, $item, $settings ),
				'update_version'      => null === $update ? null : self::nullable( $update->new_version ?? '' ),
				'update_requires_wp'  => null === $update ? null : self::nullable( $update->requires ?? '' ),
				'update_requires_php' => null === $update ? null : self::nullable( $update->requires_php ?? '' ),
			);
		}

		return $themes;
	}

	/**
	 * Returns the response and no_update lists of the update_plugins or update_themes site transient.
	 * WordPress.org lists each plugin and theme it hosts in one of the two lists.
	 *
	 * @param string $transient Transient name.
	 * @return array{response: array<string, mixed>, no_update: array<string, mixed>}
	 */
	private static function update_lists( string $transient ): array {
		$value = get_site_transient( $transient );
		$lists = array();

		foreach ( array( 'response', 'no_update' ) as $key ) {
			$lists[ $key ] = is_object( $value ) && isset( $value->$key ) && is_array( $value->$key ) ? $value->$key : array();
		}

		return $lists;
	}

	/**
	 * Returns the auto-update settings of all plugins or all themes.
	 *
	 * @param string $type             "plugin" or "theme".
	 * @param bool   $updater_disabled True when WP_Automatic_Updater::is_disabled() returns true.
	 * @return array{disabled: bool, enabled: bool, chosen: array<int, string>}
	 */
	private static function auto_update_settings( string $type, bool $updater_disabled ): array {
		return array(
			'disabled' => $updater_disabled,
			'enabled'  => (bool) wp_is_auto_update_enabled_for_type( $type ),
			'chosen'   => (array) get_site_option( "auto_update_{$type}s", array() ),
		);
	}

	/**
	 * Returns whether WordPress updates a plugin or theme automatically.
	 * Follows WP_Automatic_Updater::should_update() without its file system checks.
	 *
	 * @param string               $type     "plugin" or "theme".
	 * @param string               $id       Plugin file or theme stylesheet.
	 * @param object               $item     Update item passed to the auto_update_{$type} filter.
	 * @param array<string, mixed> $settings Settings from auto_update_settings().
	 */
	private static function auto_update( string $type, string $id, $item, array $settings ): bool {
		if ( $settings['disabled'] ) {
			return false;
		}

		$update = ! empty( $item->autoupdate ) || ( $settings['enabled'] && in_array( $id, $settings['chosen'], true ) );

		return (bool) wp_is_auto_update_forced_for_item( $type, $update, $item );
	}

	/**
	 * Returns the newest core version that the update_core site transient offers, or null.
	 *
	 * @return string|null
	 */
	private static function core_update_version() {
		$core = get_site_transient( 'update_core' );

		if ( ! is_object( $core ) || ! isset( $core->updates ) || ! is_array( $core->updates ) ) {
			return null;
		}

		foreach ( $core->updates as $offer ) {
			$offer = (object) $offer;

			if ( isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response ) {
				return self::nullable( $offer->current );
			}
		}

		return null;
	}

	/**
	 * Returns which core updates WordPress installs automatically: "major", "minor" or "none".
	 * Follows the settings that wp-admin/update-core.php shows.
	 *
	 * @param bool $updater_disabled True when WP_Automatic_Updater::is_disabled() returns true.
	 */
	private static function core_auto_update( bool $updater_disabled ): string {
		if ( $updater_disabled ) {
			return 'none';
		}

		$minor = 'enabled' === get_site_option( 'auto_update_core_minor', 'enabled' );
		$major = 'enabled' === get_site_option( 'auto_update_core_major', 'unset' );

		if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			if ( false === WP_AUTO_UPDATE_CORE ) {
				$minor = false;
				$major = false;
			} elseif ( true === WP_AUTO_UPDATE_CORE || in_array( WP_AUTO_UPDATE_CORE, self::MAJOR_CORE_VALUES, true ) ) {
				$minor = true;
				$major = true;
			} elseif ( 'minor' === WP_AUTO_UPDATE_CORE ) {
				$minor = true;
				$major = false;
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, documented in wp-admin/includes/class-core-upgrader.php.
		$minor = (bool) apply_filters( 'allow_minor_auto_core_updates', $minor );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, documented in wp-admin/includes/class-core-upgrader.php.
		$major = (bool) apply_filters( 'allow_major_auto_core_updates', $major );

		return $major ? 'major' : ( $minor ? 'minor' : 'none' );
	}

	/**
	 * Returns the time of the last change to a published post in UTC, in ISO 8601 format, or null.
	 *
	 * @return string|null
	 */
	private static function last_post_modified() {
		$modified = get_lastpostmodified( 'gmt' );
		$time     = is_string( $modified ) ? strtotime( $modified . ' UTC' ) : false;

		return false === $time || $time <= 0 ? null : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}

	/**
	 * Returns true when the constant is defined with a value that is not empty.
	 *
	 * @param string $name Constant name.
	 */
	private static function constant( string $name ): bool {
		return defined( $name ) && (bool) constant( $name );
	}

	/**
	 * Returns a name without HTML tags, or the fallback when the name is empty.
	 *
	 * @param mixed  $value    Name from the plugin or theme header.
	 * @param string $fallback File or folder name.
	 */
	private static function text( $value, string $fallback ): string {
		$text = trim( wp_strip_all_tags( (string) $value ) );

		return '' === $text ? $fallback : $text;
	}

	/**
	 * Returns null for an empty value, or the value cut to 100 characters, the longest version WPCore.com accepts.
	 *
	 * @param mixed $value Version string.
	 * @return string|null
	 */
	private static function nullable( $value ) {
		$value = trim( (string) $value );

		return '' === $value ? null : substr( $value, 0, 100 );
	}

	/**
	 * Cuts a string to the longest length WPCore.com accepts.
	 *
	 * @param string $value String.
	 */
	private static function limit( string $value ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_LENGTH ) : substr( $value, 0, self::MAX_LENGTH );
	}
}
