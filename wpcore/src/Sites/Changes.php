<?php
/**
 * Site reports after plugin and theme changes.
 *
 * @package WPCore
 */

namespace WPCore\Sites;

/**
 * Sends the report after a plugin or theme change while the site is connected. WP-Cron is not used.
 *
 * A change marks a report as pending. The report is sent at the end of the request, at most once a minute.
 * A pending report that was not sent is sent at the end of a later admin request, including the admin heartbeat.
 */
final class Changes {

	/**
	 * Actions that change the plugins or themes in the report.
	 */
	const HOOKS = array( 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'upgrader_process_complete', 'switch_theme', 'deleted_theme' );

	/**
	 * Minimum seconds between two reports sent after changes. Bulk updates from the Plugins screen run one request per plugin.
	 */
	const INTERVAL = 60;

	/**
	 * Request timeout in seconds. The report is sent at the end of the request that made the change.
	 */
	const TIMEOUT = 5;

	/**
	 * True when this request added the shutdown action.
	 *
	 * @var bool
	 */
	private static $queued = false;

	/**
	 * Registers the change actions and the admin_init action that sends a pending report.
	 */
	public static function register(): void {
		foreach ( self::HOOKS as $hook ) {
			add_action( $hook, array( self::class, 'mark_pending' ) );
		}

		add_action( 'admin_init', array( self::class, 'queue_pending' ) );
	}

	/**
	 * Counts a change, which makes a report pending, and sends the report at the end of the request. Does nothing when the site is not connected.
	 */
	public static function mark_pending(): void {
		if ( null === Connection::get() ) {
			return;
		}

		Connection::add_change();
		self::queue();
	}

	/**
	 * Sends a pending report at the end of the request.
	 */
	public static function queue_pending(): void {
		$connection = Connection::get();

		if ( null !== $connection && $connection['pending'] ) {
			self::queue();
		}
	}

	/**
	 * Sends the pending report when the last report was sent more than INTERVAL seconds ago.
	 * WPCore.com rejects a report with HTTP 4xx when it cannot accept it, and the report is not sent again.
	 * A report that did not reach WPCore.com, or that WPCore.com rate limited, stays pending.
	 * A change counted while the report was in transit stays pending.
	 */
	public static function send_pending(): void {
		self::$queued = false;
		$connection   = Connection::get();

		if ( null === $connection || ! $connection['pending'] || time() - $connection['last_push_at'] < self::INTERVAL ) {
			return;
		}

		$result = Pusher::push( self::TIMEOUT );
		$data   = is_wp_error( $result ) ? $result->get_error_data() : array( 'status' => 200 );
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		if ( 0 !== $status && 429 !== $status && $status < 500 ) {
			Connection::acknowledge( $connection['changes'] );
		}
	}

	/**
	 * Adds the shutdown action once per request. It runs after WordPress flushes the output buffers at priority 1.
	 */
	private static function queue(): void {
		if ( self::$queued ) {
			return;
		}

		self::$queued = true;
		add_action( 'shutdown', array( self::class, 'send_pending' ), 100 );
	}
}
