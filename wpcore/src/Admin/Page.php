<?php
/**
 * Admin screen.
 *
 * @package WPCore
 */

namespace WPCore\Admin;

use WPCore\Plugin;

/**
 * Adds the WPCore menu page and loads the admin screen script on it.
 *
 * The page is in each site's dashboard, as in plugin 1.x. On multisite only super admins have install_plugins, so only they see it.
 */
final class Page {

	/**
	 * Menu page slug. Plugin 1.x used the same slug, so admin.php?page=wpcore still opens the page.
	 */
	const SLUG = 'wpcore';

	/**
	 * Script and style handle of the admin screen.
	 */
	const HANDLE = 'wpcore-admin';

	/**
	 * Menu position, between Plugins (65) and Users (70). Plugin 1.x used the same position.
	 */
	const MENU_POSITION = 69.324;

	/**
	 * Hook suffix that add_menu_page() returned.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Registers the admin hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( Plugin::file() ), array( self::class, 'add_action_link' ) );
	}

	/**
	 * Adds the WPCore menu page.
	 */
	public static function add_menu_page(): void {
		self::$hook_suffix = (string) add_menu_page(
			__( 'WPCore', 'wpcore' ),
			__( 'WPCore', 'wpcore' ),
			'install_plugins',
			self::SLUG,
			array( self::class, 'render' ),
			plugins_url( 'assets/img/icon.png', Plugin::file() ),
			self::MENU_POSITION
		);
	}

	/**
	 * Prints the element the admin screen script renders into.
	 */
	public static function render(): void {
		echo '<div class="wrap"><div id="wpcore-admin"></div>';

		if ( ! file_exists( self::asset_file() ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The WPCore admin screen files are missing. Reinstall the plugin.', 'wpcore' ) . '</p></div>';
		}

		echo '<noscript><div class="notice notice-error"><p>' . esc_html__( 'The WPCore screen requires JavaScript.', 'wpcore' ) . '</p></div></noscript></div>';
	}

	/**
	 * Loads the admin screen script and style on the WPCore page.
	 *
	 * @param string $hook_suffix Hook suffix of the current admin page.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( '' === self::$hook_suffix || $hook_suffix !== self::$hook_suffix || ! file_exists( self::asset_file() ) ) {
			return;
		}

		$asset = require self::asset_file();

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/index.js', Plugin::file() ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::HANDLE, 'wpcore' );

		wp_add_inline_script(
			self::HANDLE,
			'window.wpcoreAdmin = ' . wp_json_encode(
				array(
					'createUrl' => 'https://wpcore.com/collections/create',
				)
			) . ';',
			'before'
		);

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'build/index.css', Plugin::file() ),
			array( 'wp-components' ),
			$asset['version']
		);
	}

	/**
	 * Adds a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param array<string, string> $links Action links.
	 * @return array<string, string>
	 */
	public static function add_action_link( array $links ): array {
		return array_merge(
			array(
				'settings' => sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
					esc_html__( 'Settings', 'wpcore' )
				),
			),
			$links
		);
	}

	/**
	 * Returns the path of the asset file that the JavaScript build writes.
	 */
	private static function asset_file(): string {
		return dirname( Plugin::file() ) . '/build/index.asset.php';
	}
}
