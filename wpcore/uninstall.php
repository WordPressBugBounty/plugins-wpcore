<?php
/**
 * Deletes the plugin's stored data when the plugin is deleted.
 *
 * @package WPCore
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Api/PluginIcons.php';
require_once __DIR__ . '/src/Collections/Keys.php';
require_once __DIR__ . '/src/Collections/Repository.php';
require_once __DIR__ . '/src/Upgrade.php';

/**
 * Deletes the plugin's options and transients on the current site.
 */
function wpcore_uninstall_site() {
	foreach ( WPCore\Collections\Keys::normalize( get_option( WPCore\Collections\Repository::OPTION, array() ) ) as $wpcore_key ) {
		delete_transient( WPCore\Collections\Repository::cache_name( $wpcore_key ) );
	}

	delete_option( WPCore\Collections\Repository::OPTION );
	delete_option( WPCore\Upgrade::VERSION_OPTION );
	delete_transient( WPCore\Upgrade::LEGACY_TRANSIENT );
	delete_transient( WPCore\Api\PluginIcons::CACHE );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $wpcore_site_id ) {
		switch_to_blog( $wpcore_site_id );
		wpcore_uninstall_site();
		restore_current_blog();
	}
} else {
	wpcore_uninstall_site();
}

delete_metadata( 'user', 0, WPCore\Upgrade::LEGACY_USER_META, '', true );
