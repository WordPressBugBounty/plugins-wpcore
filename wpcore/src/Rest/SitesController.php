<?php
/**
 * REST routes for the WPCore.com Sites feature.
 *
 * @package WPCore
 */

namespace WPCore\Rest;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPCore\Collections\Repository;
use WPCore\Sites\Connection;
use WPCore\Sites\Pusher;
use WPCore\Sites\Report;
use WPCore\Sites\Signature;

/**
 * Registers the report route that WPCore.com pulls, and the connection routes that the admin screen uses.
 *
 * The report route has no user. Its permission check verifies the request signature with the site secret.
 * The connection routes require the install_plugins capability.
 */
final class SitesController {

	/**
	 * Report route, relative to the REST namespace.
	 */
	const REPORT_ROUTE = '/report';

	/**
	 * HTTP statuses of a first report that delete the saved key.
	 * WPCore.com returns 403 for a site over its owner's plan limit, 404 when no site that can report matches the key,
	 * and 409 when the site address on WPCore.com does not match the home URL of this site.
	 */
	const REJECTED_KEY_STATUSES = array( 403, 404, 409 );

	/**
	 * Body of the report response, kept so the response prints exactly the body that the signature covers.
	 *
	 * @var string|null
	 */
	private $report_body = null;

	/**
	 * Registers the routes and the filter that prints the signed report body. Runs on rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			Controller::ROUTE_NAMESPACE,
			self::REPORT_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => array( $this, 'check_signature' ),
			)
		);

		register_rest_route(
			Controller::ROUTE_NAMESPACE,
			'/site-connection',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_connection' ),
					'permission_callback' => array( $this, 'check_install_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_connection' ),
					'permission_callback' => array( $this, 'check_install_permission' ),
					'args'                => array(
						'key' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_connection' ),
					'permission_callback' => array( $this, 'check_install_permission' ),
				),
			)
		);

		register_rest_route(
			Controller::ROUTE_NAMESPACE,
			'/site-connection/report',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_report' ),
				'permission_callback' => array( $this, 'check_install_permission' ),
			)
		);

		add_filter( 'rest_pre_serve_request', array( $this, 'serve_report' ), 10, 3 );
	}

	/**
	 * Allows a report request whose signature matches the saved secret.
	 * Returns HTTP 409 with the code wpcore_not_connected when the site has no connection key saved.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_signature( WP_REST_Request $request ) {
		$connection = Connection::get();

		// WPCore.com marks the site disconnected when it receives this code.
		if ( null === $connection ) {
			return new WP_Error(
				'wpcore_not_connected',
				__( 'This site has no WPCore.com connection key saved.', 'wpcore' ),
				array( 'status' => 409 )
			);
		}

		if ( Signature::verify(
			$connection['secret'],
			$request->get_header( 'x_wpcore_timestamp' ),
			$request->get_header( 'x_wpcore_signature' ),
			(string) $request->get_body()
		) ) {
			return true;
		}

		return new WP_Error(
			'wpcore_invalid_signature',
			__( 'The request signature does not match the connection key saved on this site.', 'wpcore' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Returns the signed report.
	 */
	public function get_report(): WP_REST_Response {
		$connection        = Connection::get();
		$this->report_body = (string) wp_json_encode( Report::build() );

		$response = new WP_REST_Response( null, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		foreach ( Signature::headers( null === $connection ? '' : $connection['secret'], $this->report_body ) as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}

	/**
	 * Prints the signed body of a report response. Other responses are printed by the REST server.
	 *
	 * @param bool             $served  True when an earlier filter printed the response.
	 * @param WP_HTTP_Response $result  Response.
	 * @param WP_REST_Request  $request Request.
	 */
	public function serve_report( $served, $result, $request ): bool {
		if ( $served || null === $this->report_body || ! $request instanceof WP_REST_Request ) {
			return (bool) $served;
		}

		if ( Controller::ROUTE_NAMESPACE . self::REPORT_ROUTE !== ltrim( $request->get_route(), '/' ) || 200 !== $result->get_status() ) {
			return false;
		}

		echo $this->report_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode() that the signature covers.

		return true;
	}

	/**
	 * Allows the request when the current user can install plugins.
	 *
	 * @return true|WP_Error
	 */
	public function check_install_permission() {
		if ( current_user_can( 'install_plugins' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to change the WPCore.com connection.', 'wpcore' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Returns the connection status.
	 */
	public function get_connection(): WP_REST_Response {
		return new WP_REST_Response( self::status() );
	}

	/**
	 * Saves a connection key and sends the first report. A saved key switches the plugin to site mode.
	 *
	 * A first report rejected with a status in REJECTED_KEY_STATUSES deletes the key and returns 400 with the message from WPCore.com.
	 * Another failed first report keeps the key. The response holds the error in last_push_error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_connection( WP_REST_Request $request ) {
		$saved = Connection::save( (string) $request['key'] );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		Repository::clear_site_cache();

		$pushed = Pusher::push();

		if ( is_wp_error( $pushed ) ) {
			$data = $pushed->get_error_data();

			if ( is_array( $data ) && isset( $data['status'] ) && in_array( $data['status'], self::REJECTED_KEY_STATUSES, true ) ) {
				Connection::delete();

				return new WP_Error( 'wpcore_connection_key_rejected', $pushed->get_error_message(), array( 'status' => 400 ) );
			}
		}

		return new WP_REST_Response( self::status() );
	}

	/**
	 * Sends a report now.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_report() {
		if ( null === Connection::get() ) {
			return self::not_connected();
		}

		Pusher::push();

		return new WP_REST_Response( self::status() );
	}

	/**
	 * Tells WPCore.com that the site is disconnecting, then deletes the connection. The plugin returns to collections mode.
	 * The connection is deleted when WPCore.com cannot be reached. WPCore.com then marks the site disconnected at its next pull.
	 */
	public function delete_connection(): WP_REST_Response {
		Pusher::disconnect();
		Connection::delete();
		Repository::clear_site_cache();

		return new WP_REST_Response( self::status() );
	}

	/**
	 * Returns the connection status for the admin screen. The secret is not included.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$connection = Connection::get();

		// wp_is_using_https() checks that the home URL and the site URL both use https.
		$https = wp_is_using_https();

		if ( null === $connection ) {
			return array(
				'https'           => $https,
				'connected'       => false,
				'uuid'            => null,
				'last_push_at'    => null,
				'last_push_error' => null,
				'site_url'        => null,
			);
		}

		return array(
			'https'           => $https,
			'connected'       => true,
			'uuid'            => $connection['uuid'],
			'last_push_at'    => $connection['last_push_at'] > 0 ? gmdate( 'c', $connection['last_push_at'] ) : null,
			'last_push_error' => '' === $connection['last_push_error'] ? null : $connection['last_push_error'],
			'site_url'        => Connection::site_page_url( $connection['uuid'] ),
		);
	}

	/**
	 * Returns the error for a request that needs a saved connection.
	 */
	private static function not_connected(): WP_Error {
		return new WP_Error(
			'wpcore_site_not_connected',
			__( 'This site is not connected to WPCore.com.', 'wpcore' ),
			array( 'status' => 400 )
		);
	}
}
