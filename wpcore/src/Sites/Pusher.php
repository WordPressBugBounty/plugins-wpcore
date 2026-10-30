<?php
/**
 * Sends site reports to WPCore.com.
 *
 * @package WPCore
 */

namespace WPCore\Sites;

use WP_Error;
use WPCore\Api\Collection;
use WPCore\Collections\Repository;
use WPCore\Plugin;

/**
 * Sends the sync to POST https://wpcore.com/api/sites/{uuid}/sync and records the result in the connection option.
 * The sync sends the report. The response holds the collections assigned to the site, which the sync saves in the site collection cache.
 * Sends the disconnect request to POST https://wpcore.com/api/sites/{uuid}/disconnect.
 */
final class Pusher {

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * Sends the sync now.
	 *
	 * A 200 response saves the assigned collections in the site collection cache and returns them. When WPCore.com rejects
	 * the report fields, the response still holds the collections, and its report_error is recorded as the push error.
	 * A status in Repository::SITE_REJECTED_STATUSES deletes the cache. An error has the HTTP status in its data,
	 * or no status when WPCore.com could not be reached.
	 *
	 * @param int $timeout Request timeout in seconds.
	 * @return array<string, Collection>|WP_Error Collections keyed by collection key, in the order they were assigned.
	 */
	public static function push( int $timeout = self::TIMEOUT ) {
		$connection = Connection::get();

		if ( null === $connection ) {
			return new WP_Error( 'wpcore_site_not_connected', __( 'This site is not connected to WPCore.com.', 'wpcore' ) );
		}

		$body     = (string) wp_json_encode( Report::build() );
		$response = wp_remote_post(
			Connection::sync_url( $connection['uuid'] ),
			array(
				'timeout'    => $timeout,
				'user-agent' => 'WPCore/' . Plugin::VERSION,
				'headers'    => array_merge(
					array(
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
					),
					Signature::headers( $connection['secret'], $body )
				),
				'body'       => $body,
			)
		);

		$result = self::result( $response );

		if ( is_wp_error( $result ) ) {
			Connection::record_push( $result->get_error_message(), $connection['changes'] );
			$data = $result->get_error_data();

			if ( is_array( $data ) && isset( $data['status'] ) && in_array( $data['status'], Repository::SITE_REJECTED_STATUSES, true ) ) {
				Repository::clear_site_cache();
			}

			return $result;
		}

		$sync = self::sync_data( (string) wp_remote_retrieve_body( $response ) );

		if ( is_wp_error( $sync ) ) {
			Connection::record_push( $sync->get_error_message(), $connection['changes'] );

			return $sync;
		}

		Connection::record_push( $sync['report_error'], $connection['changes'] );
		Repository::save_site_collections( $connection['uuid'], $sync['collections'] );

		return $sync['collections'];
	}

	/**
	 * Reads the body of a 200 sync response.
	 *
	 * A collection without a valid key is left out. Keys match without letter case, as in the wpcore.com database, so a repeated key is kept once.
	 *
	 * @param string $body Response body.
	 * @return array{collections: array<string, Collection>, report_error: string}|WP_Error
	 */
	private static function sync_data( string $body ) {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['success'] ) || ! isset( $data['collections'] ) || ! is_array( $data['collections'] ) ) {
			return new WP_Error(
				'wpcore_api_invalid_response',
				__( 'WPCore.com returned a response that is not valid JSON.', 'wpcore' ),
				array( 'status' => 200 )
			);
		}

		$collections = array();
		$seen        = array();

		foreach ( $data['collections'] as $entry ) {
			$collection = Collection::from_array( $entry );

			if ( null === $collection || isset( $seen[ strtolower( $collection->key() ) ] ) ) {
				continue;
			}

			$seen[ strtolower( $collection->key() ) ] = true;
			$collections[ $collection->key() ]        = $collection;
		}

		return array(
			'collections'  => $collections,
			'report_error' => isset( $data['report_error'] ) && is_string( $data['report_error'] ) ? sanitize_text_field( $data['report_error'] ) : '',
		);
	}

	/**
	 * Tells WPCore.com that the site is disconnecting. The body is empty and signed with the connection key.
	 * Call it before Connection::delete(), while the key is still saved.
	 *
	 * @param int $timeout Request timeout in seconds.
	 * @return true|WP_Error
	 */
	public static function disconnect( int $timeout = self::TIMEOUT ) {
		$connection = Connection::get();

		if ( null === $connection ) {
			return new WP_Error( 'wpcore_site_not_connected', __( 'This site is not connected to WPCore.com.', 'wpcore' ) );
		}

		$response = wp_remote_post(
			Connection::disconnect_url( $connection['uuid'] ),
			array(
				'timeout'    => $timeout,
				'user-agent' => 'WPCore/' . Plugin::VERSION,
				'headers'    => array_merge(
					array( 'Accept' => 'application/json' ),
					Signature::headers( $connection['secret'], '' )
				),
				'body'       => '',
			)
		);

		return self::result( $response );
	}

	/**
	 * Returns true for a 200 response, or an error with the message and the HTTP status from WPCore.com.
	 *
	 * @param array|WP_Error $response Response from wp_remote_post().
	 * @return true|WP_Error
	 */
	private static function result( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wpcore_report_request_failed',
				/* translators: %s: error message from the HTTP request. */
				sprintf( __( 'WPCore.com could not be reached: %s', 'wpcore' ), $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $status ) {
			return true;
		}

		$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message = is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '';

		if ( 429 === $status ) {
			$message = __( 'WPCore.com received too many requests from this site. Try again in a minute.', 'wpcore' );
		}

		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'WPCore.com returned HTTP status %d.', 'wpcore' ), $status );
		}

		return new WP_Error( 'wpcore_report_rejected', sanitize_text_field( $message ), array( 'status' => $status ) );
	}
}
