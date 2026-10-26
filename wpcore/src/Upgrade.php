<?php
/**
 * Upgrade routine.
 *
 * @package WPCore
 */

namespace WPCore;

use WPCore\Collections\Keys;
use WPCore\Collections\Repository;

/**
 * Updates stored data when the installed plugin version changes.
 */
final class Upgrade {

	/**
	 * Option that holds the plugin version the stored data was last updated for. Plugin 1.x does not write it.
	 */
	const VERSION_OPTION = 'wpcore_version';

	/**
	 * Transient in which plugin 1.x cached the API response.
	 */
	const LEGACY_TRANSIENT = 'wpcore_payload';

	/**
	 * User meta key in which TGM Plugin Activation stored a dismissed admin notice for plugin 1.x.
	 */
	const LEGACY_USER_META = 'tgmpa_dismissed_notice_wpcore';

	/**
	 * Runs the upgrade steps when the stored version differs from Plugin::VERSION.
	 */
	public static function maybe_run(): void {
		$stored_version = get_option( self::VERSION_OPTION, '' );

		if ( Plugin::VERSION === $stored_version ) {
			return;
		}

		if ( '' === $stored_version ) {
			self::from_1x();
		}

		update_option( self::VERSION_OPTION, Plugin::VERSION );
	}

	/**
	 * Deletes the 1.x cache and TGMPA notice state, and rewrites the saved keys in their cleaned form.
	 *
	 * A new install also runs this step. It finds nothing to delete.
	 */
	private static function from_1x(): void {
		delete_transient( self::LEGACY_TRANSIENT );
		delete_metadata( 'user', 0, self::LEGACY_USER_META, '', true );

		$stored_keys = get_option( Repository::OPTION, null );

		if ( null !== $stored_keys ) {
			update_option( Repository::OPTION, Keys::normalize( $stored_keys ) );
		}
	}
}
