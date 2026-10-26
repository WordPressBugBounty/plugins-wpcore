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

/**
 * Stores collection keys in the wpcore_keys option and caches each collection in a transient.
 */
final class Repository {

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
	 * Deletes the cached collection of every saved key.
	 */
	public function clear_cache(): void {
		foreach ( $this->keys() as $key ) {
			delete_transient( self::cache_name( $key ) );
		}
	}

	/**
	 * Loads the collections of the saved keys.
	 *
	 * Cached collections are read from their transients. The other keys are requested from the API in one request.
	 * When the API request fails, the result holds the cached collections and the error.
	 *
	 * @param bool $refresh True to request every key. When the request fails, the cached values are used.
	 */
	public function collections( bool $refresh = false ): Result {
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
