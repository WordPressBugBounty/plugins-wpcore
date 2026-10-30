<?php
/**
 * Disconnect on plugin deactivation.
 *
 * @package WPCore
 */

namespace WPCore\Sites;

use WPCore\Collections\Repository;

/**
 * Disconnects the site when the plugin is deactivated, as the Disconnect button does.
 * WPCore.com marks the site disconnected and stops requesting reports from it. The key is deleted, so reactivating the plugin needs the key pasted again.
 */
final class Deactivation {

	/**
	 * Registers the deactivation hook.
	 *
	 * @param string $file Absolute path to wpcore.php.
	 */
	public static function register( string $file ): void {
		register_deactivation_hook( $file, array( self::class, 'deactivate' ) );
	}

	/**
	 * Disconnects the current site, or every site of the network when the plugin is network deactivated.
	 *
	 * @param bool $network_wide True when the plugin is network deactivated.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			self::disconnect();

			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::disconnect();
			restore_current_blog();
		}
	}

	/**
	 * Tells WPCore.com that the site is disconnecting, then deletes the connection and the cached assigned collections.
	 * The connection is deleted when WPCore.com cannot be reached. WPCore.com then marks the site disconnected at its next pull.
	 * The deactivation hook runs before deactivated_plugin, so Changes sends no report after the connection is deleted.
	 */
	private static function disconnect(): void {
		if ( null === Connection::get() ) {
			return;
		}

		Pusher::disconnect( Changes::TIMEOUT );
		Connection::delete();
		Repository::clear_site_cache();
	}
}
