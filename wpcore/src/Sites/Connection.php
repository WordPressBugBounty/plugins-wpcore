<?php
/**
 * Connection to the WPCore.com Sites feature.
 *
 * @package WPCore
 */

namespace WPCore\Sites;

use WP_Error;
use WPCore\Api\Client;

/**
 * Reads and writes the connection key that the user pastes from the site page on WPCore.com.
 *
 * The key is the site UUID and a secret joined by a colon. The option also holds the change counters and the result of the last push.
 * `changes` counts plugin and theme changes. `reported` is the value of `changes` when the last report that reached WPCore.com was built.
 * A report is pending while `changes` is greater than `reported`.
 * The secret never leaves the site. It signs the requests and responses.
 */
final class Connection {

	/**
	 * Option that holds the connection.
	 */
	const OPTION = 'wpcore_site_connection';

	/**
	 * Format of a connection key: a version 4 UUID, a colon and a secret of 20 to 128 letters and digits.
	 */
	const KEY_PATTERN = '/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}):([A-Za-z0-9]{20,128})$/';

	/**
	 * Returns the saved connection, or null when the site is not connected.
	 *
	 * @return array{uuid: string, secret: string, changes: int, reported: int, pending: bool, last_push_at: int, last_push_error: string}|null
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );

		if ( ! is_array( $saved ) || empty( $saved['uuid'] ) || empty( $saved['secret'] ) ) {
			return null;
		}

		$changes  = isset( $saved['changes'] ) ? (int) $saved['changes'] : 0;
		$reported = isset( $saved['reported'] ) ? (int) $saved['reported'] : 0;

		return array(
			'uuid'            => (string) $saved['uuid'],
			'secret'          => (string) $saved['secret'],
			'changes'         => $changes,
			'reported'        => $reported,
			'pending'         => $changes > $reported,
			'last_push_at'    => isset( $saved['last_push_at'] ) ? (int) $saved['last_push_at'] : 0,
			'last_push_error' => isset( $saved['last_push_error'] ) ? (string) $saved['last_push_error'] : '',
		);
	}

	/**
	 * Saves a connection key. Replaces an earlier connection and clears its push result.
	 *
	 * @param string $key Connection key from WPCore.com.
	 * @return true|WP_Error
	 */
	public static function save( string $key ) {
		if ( 1 !== preg_match( self::KEY_PATTERN, trim( $key ), $matches ) ) {
			return new WP_Error(
				'wpcore_invalid_connection_key',
				__( 'The connection key is not valid. Copy it from the site page on WPCore.com.', 'wpcore' ),
				array( 'status' => 400 )
			);
		}

		update_option(
			self::OPTION,
			array(
				'uuid'            => $matches[1],
				'secret'          => $matches[2],
				'changes'         => 0,
				'reported'        => 0,
				'last_push_at'    => 0,
				'last_push_error' => '',
			)
		);

		return true;
	}

	/**
	 * Counts a plugin or theme change. The change makes a report pending. Changes::send_pending() sends a pending report.
	 */
	public static function add_change(): void {
		$connection = self::get();

		if ( null !== $connection ) {
			self::update( array( 'changes' => $connection['changes'] + 1 ) );
		}
	}

	/**
	 * Marks the changes counted before a report was built as reported. A change counted after that stays pending.
	 *
	 * @param int $changes Value of `changes` when the report was built.
	 */
	public static function acknowledge( int $changes ): void {
		$connection = self::get();

		if ( null !== $connection && $changes > $connection['reported'] ) {
			self::update( array( 'reported' => $changes ) );
		}
	}

	/**
	 * Records the result of a push. A successful push acknowledges the changes counted before the report was built.
	 *
	 * @param string $error   Error message, or an empty string for a successful push.
	 * @param int    $changes Value of `changes` when the report was built.
	 */
	public static function record_push( string $error, int $changes ): void {
		self::update(
			array(
				'last_push_at'    => time(),
				'last_push_error' => $error,
			)
		);

		if ( '' === $error ) {
			self::acknowledge( $changes );
		}
	}

	/**
	 * Deletes the connection.
	 */
	public static function delete(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Returns the URL that the plugin sends the sync to, under Client::base_url().
	 * The sync sends the report and returns the collections assigned to the site.
	 *
	 * @param string $uuid Site UUID.
	 */
	public static function sync_url( string $uuid ): string {
		return Client::base_url() . 'sites/' . rawurlencode( $uuid ) . '/sync';
	}

	/**
	 * Returns the URL of the site page on WPCore.com, where the user assigns collections to the site.
	 * The page URL is the API base URL without "api/", followed by "sites/<uuid>".
	 *
	 * @param string $uuid Site UUID.
	 */
	public static function site_page_url( string $uuid ): string {
		return (string) preg_replace( '#api/$#', '', Client::base_url() ) . 'sites/' . rawurlencode( $uuid );
	}

	/**
	 * Returns the URL that the plugin sends the disconnect request to, under Client::base_url().
	 *
	 * @param string $uuid Site UUID.
	 */
	public static function disconnect_url( string $uuid ): string {
		return Client::base_url() . 'sites/' . rawurlencode( $uuid ) . '/disconnect';
	}

	/**
	 * Merges values into the saved connection. Does nothing when the site is not connected.
	 *
	 * @param array<string, mixed> $values Values to change.
	 */
	private static function update( array $values ): void {
		$connection = self::get();

		if ( null === $connection ) {
			return;
		}

		$saved = array_merge( $connection, $values );
		unset( $saved['pending'] );

		update_option( self::OPTION, $saved );
	}
}
