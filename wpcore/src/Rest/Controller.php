<?php
/**
 * REST routes.
 *
 * @package WPCore
 */

namespace WPCore\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPCore\Api\Collection;
use WPCore\Api\CollectionPlugin;
use WPCore\Api\PluginIcons;
use WPCore\Collections\Repository;
use WPCore\Collections\Result;
use WPCore\Installer\Installer;
use WPCore\Installer\PluginStatus;
use WPCore\Sites\Connection;

/**
 * Registers the wpcore/v1 routes that the admin screen uses.
 *
 * Every route requires the install_plugins capability. The install route installs only plugins
 * listed in a collection of the current mode, so a request cannot supply its own download URL.
 * In site mode the collection key routes return 409, because the site page on WPCore.com assigns the collections.
 */
final class Controller {

	/**
	 * REST namespace.
	 */
	const ROUTE_NAMESPACE = 'wpcore/v1';

	/**
	 * Collection repository.
	 *
	 * @var Repository
	 */
	private $repository;

	/**
	 * Plugin installer.
	 *
	 * @var Installer
	 */
	private $installer;

	/**
	 * WordPress.org plugin icons.
	 *
	 * @var PluginIcons
	 */
	private $icons;

	/**
	 * Creates the controller.
	 *
	 * @param Repository  $repository Collection repository.
	 * @param Installer   $installer  Plugin installer.
	 * @param PluginIcons $icons      WordPress.org plugin icons.
	 */
	public function __construct( Repository $repository, Installer $installer, PluginIcons $icons ) {
		$this->repository = $repository;
		$this->installer  = $installer;
		$this->icons      = $icons;
	}

	/**
	 * Registers the routes. Runs on rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/collections',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_collections' ),
					'permission_callback' => array( $this, 'check_install_permission' ),
					'args'                => array(
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_collection' ),
					'permission_callback' => array( $this, 'check_install_permission' ),
					'args'                => array(
						'key' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/collections/(?P<key>[A-Za-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_collection' ),
				'permission_callback' => array( $this, 'check_install_permission' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/icons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_icons' ),
				'permission_callback' => array( $this, 'check_install_permission' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install' ),
				'permission_callback' => array( $this, 'check_install_request_permission' ),
				'args'                => array(
					'key'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'slug'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'activate' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'network'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
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
			__( 'You are not allowed to install plugins.', 'wpcore' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Allows an install request when the user can install plugins, and can activate them if the request activates.
	 *
	 * Network activation also requires the manage_network_plugins capability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_install_request_permission( WP_REST_Request $request ) {
		$allowed = $this->check_install_permission();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( $request['activate'] && ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to activate plugins.', 'wpcore' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $request['network'] && ( ! is_multisite() || ! current_user_can( 'manage_network_plugins' ) ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to activate plugins for the network.', 'wpcore' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Returns the collections of the current mode.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_collections( WP_REST_Request $request ): WP_REST_Response {
		return $this->collections_response( $this->repository->collections( (bool) $request['refresh'] ) );
	}

	/**
	 * Saves a collection key and returns the saved collections.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_collection( WP_REST_Request $request ) {
		if ( Repository::MODE_SITE === Repository::mode() ) {
			return self::site_mode_error();
		}

		$added = $this->repository->add_key( (string) $request['key'] );

		if ( is_wp_error( $added ) ) {
			return $added;
		}

		return $this->collections_response( $this->repository->collections() );
	}

	/**
	 * Removes a collection key and returns the saved collections.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_collection( WP_REST_Request $request ) {
		if ( Repository::MODE_SITE === Repository::mode() ) {
			return self::site_mode_error();
		}

		$this->repository->remove_key( (string) $request['key'] );

		return $this->collections_response( $this->repository->collections() );
	}

	/**
	 * Returns the WordPress.org icon URLs of the WordPress.org plugins in the collections of the current mode.
	 */
	public function get_icons(): WP_REST_Response {
		$slugs = array();

		foreach ( $this->repository->collections()->collections() as $collection ) {
			foreach ( $collection->plugins() as $plugin ) {
				if ( $plugin->is_from_wordpress_org() ) {
					$slugs[] = $plugin->slug();
				}
			}
		}

		return new WP_REST_Response( array( 'icons' => (object) $this->icons->for_slugs( $slugs ) ) );
	}

	/**
	 * Installs one plugin from a collection of the current mode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function install( WP_REST_Request $request ) {
		$plugin = $this->find_plugin( (string) $request['key'], (string) $request['slug'] );

		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		$outcome = $this->installer->install( $plugin, (bool) $request['activate'], (bool) $request['network'] );

		if ( is_wp_error( $outcome ) ) {
			$outcome->add_data( array( 'status' => 500 ) );
			return $outcome;
		}

		$outcome['status'] = PluginStatus::of( $plugin->slug() );

		return new WP_REST_Response( $outcome );
	}

	/**
	 * Finds a plugin in a collection of the current mode.
	 *
	 * @param string $key  Collection key.
	 * @param string $slug Plugin slug.
	 * @return CollectionPlugin|WP_Error
	 */
	private function find_plugin( string $key, string $slug ) {
		$collections = $this->repository->collections()->collections();
		$collection  = null;

		foreach ( $collections as $saved_key => $saved_collection ) {
			if ( strtolower( $saved_key ) === strtolower( $key ) ) {
				$collection = $saved_collection;
				break;
			}
		}

		if ( null === $collection ) {
			return new WP_Error(
				'wpcore_collection_not_found',
				__( 'No collection on this site has this key.', 'wpcore' ),
				array( 'status' => 404 )
			);
		}

		foreach ( $collection->plugins() as $plugin ) {
			if ( $plugin->slug() === $slug ) {
				return $plugin;
			}
		}

		return new WP_Error(
			'wpcore_plugin_not_in_collection',
			__( 'The collection does not contain this plugin.', 'wpcore' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Returns the error for a collection key route in site mode.
	 */
	private static function site_mode_error(): WP_Error {
		return new WP_Error(
			'wpcore_site_mode',
			__( 'This site is connected to WPCore.com. Assign collections to it on its site page on WPCore.com.', 'wpcore' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Builds the response body for a collections result.
	 *
	 * In site mode, site_url is the site page on WPCore.com and unused_keys holds the keys saved in collections mode.
	 *
	 * @param Result $result Collections result.
	 */
	private function collections_response( Result $result ): WP_REST_Response {
		$error      = $result->error();
		$connection = Connection::get();

		return new WP_REST_Response(
			array(
				'mode'           => null === $connection ? Repository::MODE_COLLECTIONS : Repository::MODE_SITE,
				'site_url'       => null === $connection ? null : Connection::site_page_url( $connection['uuid'] ),
				'unused_keys'    => null === $connection ? array() : $this->repository->keys(),
				'collections'    => array_values( array_map( array( $this, 'collection_data' ), $result->collections() ) ),
				'not_found_keys' => $result->not_found_keys(),
				'error'          => null === $error ? null : array(
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
				),
			)
		);
	}

	/**
	 * Returns the response data of one collection, with the installed status of each plugin.
	 *
	 * @param Collection $collection Collection.
	 * @return array<string, mixed>
	 */
	private function collection_data( Collection $collection ): array {
		return array(
			'key'     => $collection->key(),
			'name'    => $collection->name(),
			'url'     => $collection->url(),
			'plugins' => array_map(
				static function ( CollectionPlugin $plugin ): array {
					return array(
						'slug'         => $plugin->slug(),
						'name'         => $plugin->name(),
						'source_type'  => $plugin->is_from_wordpress_org() ? 'wordpress.org' : 'custom',
						'source'       => $plugin->source(),
						'external_url' => $plugin->external_url(),
						'status'       => PluginStatus::of( $plugin->slug() ),
					);
				},
				$collection->plugins()
			),
		);
	}
}
