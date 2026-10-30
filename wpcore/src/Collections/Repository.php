<?php
/**
 * Saved collection keys and the collection cache.
 *
 * @package WPCore
 */

namespace WPCore\Collections;

use WP_Error;
use WPCore\Api\Client;
use WPCore\Api\Collection;
use WPCore\Sites\Connection;
use WPCore\Sites\Pusher;

/**
 * Loads the collections of this site in one of two modes.
 *
 * Collections mode: the site is not connected to WPCore.com. The collections are the ones whose keys are saved in the
 * wpcore_keys option. Each collection is cached in its own transient.
 *
 * Site mode: the site is connected to WPCore.com. The collections are the ones assigned to the site on its site page.
 * Each sync with WPCore.com returns them, and Pusher saves them in a transient. The saved keys are kept but not used,
 * so disconnecting returns the site to collections mode with the same keys.
 */
final class Repository {

	/**
	 * Mode of a site that is not connected to WPCore.com.
	 */
	const MODE_COLLECTIONS = 'collections';

	/**
	 * Mode of a site that is connected to WPCore.com.
	 */
	const MODE_SITE = 'site';

	/**
	 * Option that holds the saved keys as an array of strings. Plugin 1.x uses the same option.
	 */
	const OPTION = 'wpcore_keys';

	/**
	 * Prefix of the cache transient names. The lowercase key is appended.
	 */
	const CACHE_PREFIX = 'wpcore_collection_';

	/**
	 * Cache lifetime of a found collection in seconds: 12 hours.
	 */
	const CACHE_TTL = 43200;

	/**
	 * Cache lifetime of a key that matched no collection in seconds: 1 hour.
	 */
	const NOT_FOUND_TTL = 3600;

	/**
	 * Transient that holds the collections assigned to the connected site, with the site UUID and the time they were read.
	 */
	const SITE_CACHE = 'wpcore_site_collections';

	/**
	 * Age in seconds after which the WPCore screen syncs again to read the assigned collections: 5 minutes.
	 * A change on the site page shows on the WPCore screen within this time, or at once with Refresh.
	 */
	const SITE_FRESH_FOR = 300;

	/**
	 * HTTP statuses of a sync after which the cached assigned collections are deleted.
	 * WPCore.com returns 401 for a wrong signature, 403 for a paused site, 404 when the site cannot sync,
	 * 409 when the home URL of this site does not match the site address on WPCore.com, and 422 when the home URL is not valid.
	 */
	const SITE_REJECTED_STATUSES = array( 401, 403, 404, 409, 422 );

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Creates the repository.
	 *
	 * @param Client $client API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Returns the cache transient name for a key.
	 *
	 * @param string $key Collection key.
	 */
	public static function cache_name( string $key ): string {
		return self::CACHE_PREFIX . strtolower( trim( $key ) );
	}

	/**
	 * Returns MODE_SITE when the site is connected to WPCore.com, or MODE_COLLECTIONS when it is not.
	 */
	public static function mode(): string {
		return null === Connection::get() ? self::MODE_COLLECTIONS : self::MODE_SITE;
	}

	/**
	 * Deletes the cached collections of the connected site. Called when the site connects or disconnects, and when WPCore.com rejects a sync.
	 */
	public static function clear_site_cache(): void {
		delete_transient( self::SITE_CACHE );
	}

	/**
	 * Saves the collections from a sync response as the cached collections of the connected site.
	 * The cache holds arrays, not objects, so a cached value never depends on a class definition.
	 *
	 * @param string                    $uuid        Site UUID.
	 * @param array<string, Collection> $collections Collections keyed by collection key.
	 */
	public static function save_site_collections( string $uuid, array $collections ): void {
		set_transient(
			self::SITE_CACHE,
			array(
				'uuid'        => $uuid,
				'read_at'     => time(),
				'collections' => array_values(
					array_map(
						static function ( Collection $collection ): array {
							return $collection->to_array();
						},
						$collections
					)
				),
			),
			self::CACHE_TTL
		);
	}

	/**
	 * Returns the saved keys.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return Keys::normalize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Saves a key after the saved keys. A key that is already saved is not added again.
	 *
	 * @param string $key Collection key.
	 * @return true|WP_Error
	 */
	public function add_key( string $key ) {
		$key = trim( $key );

		if ( ! Keys::is_valid( $key ) ) {
			return new WP_Error(
				'wpcore_invalid_key',
				__( 'A collection key contains only letters, digits, hyphens and underscores.', 'wpcore' ),
				array( 'status' => 400 )
			);
		}

		$keys = $this->keys();

		if ( ! in_array( strtolower( $key ), array_map( 'strtolower', $keys ), true ) ) {
			$keys[] = $key;
			update_option( self::OPTION, $keys );
		}

		return true;
	}

	/**
	 * Removes a saved key and its cached collection.
	 *
	 * @param string $key Collection key.
	 */
	public function remove_key( string $key ): void {
		$id   = strtolower( trim( $key ) );
		$keys = array();

		foreach ( $this->keys() as $saved ) {
			if ( strtolower( $saved ) !== $id ) {
				$keys[] = $saved;
			}
		}

		update_option( self::OPTION, $keys );
		delete_transient( self::cache_name( $key ) );
	}

	/**
	 * Deletes the cached collection of every saved key, and the cached collections of the connected site.
	 */
	public function clear_cache(): void {
		foreach ( $this->keys() as $key ) {
			delete_transient( self::cache_name( $key ) );
		}

		self::clear_site_cache();
	}

	/**
	 * Loads the collections of the current mode.
	 *
	 * @param bool $refresh True to request the collections from WPCore.com. When the request fails, the cached values are used.
	 */
	public function collections( bool $refresh = false ): Result {
		$connection = Connection::get();

		return null === $connection ? $this->key_collections( $refresh ) : $this->site_collections( $connection, $refresh );
	}

	/**
	 * Loads the collections assigned to the connected site.
	 *
	 * Collections read less than SITE_FRESH_FOR seconds ago come from the cache. Otherwise the site syncs with WPCore.com.
	 * When the sync fails, the cached collections are returned with the error.
	 * A status in SITE_REJECTED_STATUSES returns no collections. Pusher has deleted the cache.
	 *
	 * @param array{uuid: string, secret: string} $connection Saved connection.
	 * @param bool                                $refresh    True to sync even when the cache is fresh.
	 */
	private function site_collections( array $connection, bool $refresh ): Result {
		$cached = get_transient( self::SITE_CACHE );
		$cached = is_array( $cached ) && isset( $cached['uuid'], $cached['read_at'], $cached['collections'] ) && $cached['uuid'] === $connection['uuid'] && is_array( $cached['collections'] ) ? $cached : null;

		if ( ! $refresh && null !== $cached && time() - (int) $cached['read_at'] < self::SITE_FRESH_FOR ) {
			return new Result( self::from_cache( $cached['collections'] ), array() );
		}

		$synced = Pusher::push();

		if ( is_wp_error( $synced ) ) {
			$data = $synced->get_error_data();

			if ( is_array( $data ) && isset( $data['status'] ) && in_array( $data['status'], self::SITE_REJECTED_STATUSES, true ) ) {
				return new Result( array(), array(), $synced );
			}

			return new Result( null === $cached ? array() : self::from_cache( $cached['collections'] ), array(), $synced );
		}

		return new Result( $synced, array() );
	}

	/**
	 * Returns cached collections keyed by collection key.
	 *
	 * @param array<int, mixed> $items Collections in the format of Collection::to_array().
	 * @return array<string, Collection>
	 */
	private static function from_cache( array $items ): array {
		$collections = array();

		foreach ( $items as $item ) {
			$collection = Collection::from_array( $item );

			if ( null !== $collection ) {
				$collections[ $collection->key() ] = $collection;
			}
		}

		return $collections;
	}

	/**
	 * Loads the collections of the saved keys.
	 *
	 * Cached collections are read from their transients. The other keys are requested from the API in one request.
	 * When the API request fails, the result holds the cached collections and the error.
	 *
	 * @param bool $refresh True to request every key. When the request fails, the cached values are used.
	 */
	private function key_collections( bool $refresh ): Result {
		$keys      = $this->keys();
		$found     = array();
		$not_found = array();
		$uncached  = array();
		$error     = null;

		foreach ( $keys as $key ) {
			$cached = $refresh ? null : $this->read_cache( $key );

			if ( null === $cached ) {
				$uncached[] = $key;
			} elseif ( $cached instanceof Collection ) {
				$found[ $key ] = $cached;
			} else {
				$not_found[ $key ] = true;
			}
		}

		if ( array() !== $uncached ) {
			$fetched = $this->client->get_collections( $uncached );

			if ( is_wp_error( $fetched ) ) {
				$error = $fetched;

				if ( $refresh ) {
					foreach ( $uncached as $key ) {
						$cached = $this->read_cache( $key );

						if ( $cached instanceof Collection ) {
							$found[ $key ] = $cached;
						} elseif ( false === $cached ) {
							$not_found[ $key ] = true;
						}
					}
				}
			} else {
				foreach ( $uncached as $key ) {
					if ( isset( $fetched[ $key ] ) ) {
						$found[ $key ] = $fetched[ $key ];
						$this->write_cache( $key, $fetched[ $key ] );
					} else {
						$not_found[ $key ] = true;
						$this->write_cache( $key, null );
					}
				}
			}
		}

		$collections    = array();
		$not_found_keys = array();

		foreach ( $keys as $key ) {
			if ( isset( $found[ $key ] ) ) {
				$collections[ $key ] = $found[ $key ];
			} elseif ( isset( $not_found[ $key ] ) ) {
				$not_found_keys[] = $key;
			}
		}

		return new Result( $collections, $not_found_keys, $error );
	}

	/**
	 * Reads a cached collection.
	 *
	 * @param string $key Collection key.
	 * @return Collection|false|null The collection, false for a cached "not found", or null when nothing usable is cached.
	 */
	private function read_cache( string $key ) {
		$cached = get_transient( self::cache_name( $key ) );

		if ( ! is_array( $cached ) || ! isset( $cached['found'] ) ) {
			return null;
		}

		if ( ! $cached['found'] ) {
			return false;
		}

		return isset( $cached['collection'] ) ? Collection::from_array( $cached['collection'] ) : null;
	}

	/**
	 * Caches a collection, or a "not found" result when the collection is null.
	 *
	 * The cache holds arrays, not objects, so a cached value never depends on a class definition.
	 *
	 * @param string          $key        Collection key.
	 * @param Collection|null $collection Found collection, or null.
	 */
	private function write_cache( string $key, ?Collection $collection ): void {
		if ( null === $collection ) {
			set_transient( self::cache_name( $key ), array( 'found' => false ), self::NOT_FOUND_TTL );
			return;
		}

		set_transient(
			self::cache_name( $key ),
			array(
				'found'      => true,
				'collection' => $collection->to_array(),
			),
			self::CACHE_TTL
		);
	}
}
