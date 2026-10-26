<?php
/**
 * Plugin entry point.
 *
 * @package WPCore
 */

namespace WPCore;

/**
 * Holds the plugin version and file path, and registers the plugin's hooks.
 */
final class Plugin {

	/**
	 * Plugin version. It must match the Version header in wpcore.php and the Stable tag in readme.txt.
	 */
	const VERSION = '2.0.1';

	/**
	 * Absolute path to wpcore.php.
	 *
	 * @var string
	 */
	private static $file = '';

	/**
	 * Stores the main plugin file path and registers the plugin's hooks.
	 *
	 * @param string $file Absolute path to wpcore.php.
	 */
	public static function boot( string $file ): void {
		self::$file = $file;

		add_action( 'init', array( Upgrade::class, 'maybe_run' ) );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		Admin\Page::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Command::register();
		}
	}

	/**
	 * Registers the wpcore/v1 REST routes. Runs on rest_api_init.
	 */
	public static function register_rest_routes(): void {
		$controller = new Rest\Controller(
			new Collections\Repository( new Api\Client() ),
			new Installer\Installer(),
			new Api\PluginIcons()
		);

		$controller->register_routes();
	}

	/**
	 * Returns the absolute path to wpcore.php.
	 */
	public static function file(): string {
		return self::$file;
	}
}
