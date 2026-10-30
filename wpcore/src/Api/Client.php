<?php
/**
 * WPCore API client.
 *
 * @package WPCore
 */

namespace WPCore\Api;

use WP_Error;
use WPCore\Collections\Keys;
use WPCore\Plugin;

/**
 * Reads collections from GET https://wpcore.com/api/{key1,key2,...}.
 */
final class Client {

	/**
	 * API base URL. The comma-separated keys are appended to it.
	 */
	const DEFAULT_URL = 'https://wpcore.com/api/';

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 10;

	/**
	 * Returns the API base URL, ending in a slash.
	 *
	 * The WPCORE_API_URL constant in wp-config.php replaces the default. The wpcore_api_url filter runs after it.
	 * Both point a test site at a local wpcore.com app.
	 */
	public static function base_url(): string {
		$default = defined( 'WPCORE_API_URL' ) ? (string) constant( 'WPCORE_API_URL' ) : self::DEFAULT_URL;

		/**
		 * Filters the WPCore API base URL. Collection keys and the site report path are appended to it.
		 *
		 * @param string $url API base URL.
		 */
		return trailingslashit( (string) apply_filters( 'wpcore_api_url', $default ) );
	}

	/**
	 * Requests collections by key.
	 *
	 * The result is keyed by the requested key. A requested key that matches no collection is not in the result.
	 * Keys match without letter case, as in the wpcore.com database.
	 *
	 * @param string[] $keys Collection keys. Invalid keys are not sent.
	 * @return array<string, Collection>|WP_Error
	 */
	public function get_collections( array $keys ) {
		$keys = Keys::normalize( $keys );

		if ( array() === $keys ) {
			return array();
		}

		$base_url = self::base_url();

		$response = wp_remote_get(
			$base_url . implode( ',', array_map( 'rawurlencode', $keys ) ),
			array(
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'WPCore/' . Plugin::VERSION,
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wpcore_api_request_failed',
				/* translators: %s: error message from the HTTP request. */
				sprintf( __( 'WPCore.com could not be reached: %s', 'wpcore' ), $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $status ) {
			return new WP_Error(
				'wpcore_api_rate_limited',
				__( 'WPCore.com received too many requests from this server. Try again in a minute.', 'wpcore' ),
				array( 'status' => $status )
			);
		}

		if ( 200 !== $status ) {
			return new WP_Error(
				'wpcore_api_http_error',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'WPCore.com returned HTTP status %d.', 'wpcore' ), $status ),
				array( 'status' => $status )
			);
		}

		$entries = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $entries ) ) {
			return new WP_Error(
				'wpcore_api_invalid_response',
				__( 'WPCore.com returned a response that is not valid JSON.', 'wpcore' )
			);
		}

		return $this->match_to_keys( $entries, $keys );
	}

	/**
	 * Returns the collections in the response keyed by the requested key they match.
	 *
	 * @param array<int|string, mixed> $entries Decoded API response.
	 * @param string[]                 $keys    Requested keys.
	 * @return array<string, Collection>
	 */
	private function match_to_keys( array $entries, array $keys ): array {
		$by_lowercase_key = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['success'] ) || ! isset( $entry['data'] ) ) {
				continue;
			}

			$collection = Collection::from_array( $entry['data'] );

			if ( null !== $collection ) {
				$by_lowercase_key[ strtolower( $collection->key() ) ] = $collection;
			}
		}

		$collections = array();

		foreach ( $keys as $key ) {
			if ( isset( $by_lowercase_key[ strtolower( $key ) ] ) ) {
				$collections[ $key ] = $by_lowercase_key[ strtolower( $key ) ];
			}
		}

		return $collections;
	}
}
