<?php
/**
 * WordPress.org plugin icons.
 *
 * @package WPCore
 */

namespace WPCore\Api;

use WPCore\Plugin;

/**
 * Reads plugin icon URLs from the WordPress.org plugin information API and caches them in one transient.
 */
final class PluginIcons {

	/**
	 * Plugin information API URL. One request returns several plugins when the slugs are comma-separated.
	 */
	const API_URL = 'https://api.wordpress.org/plugins/info/1.2/';

	/**
	 * Transient that holds icon URLs keyed by slug. A slug with no icon has an empty string.
	 */
	const CACHE = 'wpcore_plugin_icons';

	/**
	 * Cache lifetime in seconds: 7 days.
	 */
	const CACHE_TTL = 604800;

	/**
	 * Maximum number of slugs in one request.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 10;

	/**
	 * Returns the icon URL of each slug that has one. Requests only slugs that are not cached.
	 *
	 * A slug that WordPress.org does not list, or that has only a generated default icon, is not in the result.
	 * Slugs from a failed request are not cached, so the next call requests them again.
	 *
	 * @param string[] $slugs WordPress.org plugin slugs.
	 * @return array<string, string> Icon URLs keyed by slug.
	 */
	public function for_slugs( array $slugs ): array {
		$slugs   = array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
		$cached  = get_transient( self::CACHE );
		$cached  = is_array( $cached ) ? $cached : array();
		$missing = array_values( array_diff( $slugs, array_keys( $cached ) ) );

		if ( array() !== $missing ) {
			foreach ( array_chunk( $missing, self::BATCH_SIZE ) as $batch ) {
				$cached = array_merge( $cached, $this->request( $batch ) );
			}

			set_transient( self::CACHE, $cached, self::CACHE_TTL );
		}

		$icons = array();

		foreach ( $slugs as $slug ) {
			if ( isset( $cached[ $slug ] ) && '' !== $cached[ $slug ] ) {
				$icons[ $slug ] = $cached[ $slug ];
			}
		}

		return $icons;
	}

	/**
	 * Requests the icons of up to BATCH_SIZE slugs.
	 *
	 * @param string[] $slugs Plugin slugs.
	 * @return array<string, string> Icon URL, or an empty string for no icon, keyed by slug. Empty after a failed request.
	 */
	private function request( array $slugs ): array {
		$fields = array( 'icons' => 1 );

		foreach ( array( 'sections', 'description', 'short_description', 'contributors', 'versions', 'screenshots', 'banners', 'reviews', 'tags', 'ratings', 'compatibility', 'donate_link' ) as $field ) {
			$fields[ $field ] = 0;
		}

		$response = wp_remote_get(
			self::API_URL . '?' . http_build_query(
				array(
					'action'  => 'plugin_information',
					'request' => array(
						'slugs'  => implode( ',', $slugs ),
						'fields' => $fields,
					),
				)
			),
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'WPCore/' . Plugin::VERSION,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$entries = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		$icons = array();

		foreach ( $slugs as $slug ) {
			$icons[ $slug ] = isset( $entries[ $slug ] ) && is_array( $entries[ $slug ] ) ? $this->icon_url( $entries[ $slug ] ) : '';
		}

		return $icons;
	}

	/**
	 * Returns the best icon URL of a plugin: the SVG, then the 256 pixel PNG, then the 128 pixel PNG.
	 *
	 * @param array<string, mixed> $entry Plugin information.
	 * @return string HTTPS icon URL, or an empty string when the plugin has none.
	 */
	private function icon_url( array $entry ): string {
		if ( ! isset( $entry['icons'] ) || ! is_array( $entry['icons'] ) ) {
			return '';
		}

		foreach ( array( 'svg', '2x', '1x' ) as $size ) {
			if ( ! isset( $entry['icons'][ $size ] ) || ! is_string( $entry['icons'][ $size ] ) ) {
				continue;
			}

			$url = $entry['icons'][ $size ];

			if ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				return $url;
			}
		}

		return '';
	}
}
