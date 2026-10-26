<?php
/**
 * A collection returned by the API.
 *
 * @package WPCore
 */

namespace WPCore\Api;

use WPCore\Collections\Keys;

/**
 * A collection with its key, name and plugins.
 */
final class Collection {

	/**
	 * Collection page URL on wpcore.com, without the key.
	 */
	const PAGE_URL = 'https://wpcore.com/collections/';

	/**
	 * Collection key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Collection title.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Plugins in the collection.
	 *
	 * @var CollectionPlugin[]
	 */
	private $plugins;

	/**
	 * Creates a collection.
	 *
	 * @param string             $key     Collection key.
	 * @param string             $name    Collection title.
	 * @param CollectionPlugin[] $plugins Plugins in the collection.
	 */
	public function __construct( string $key, string $name, array $plugins ) {
		$this->key     = $key;
		$this->name    = $name;
		$this->plugins = $plugins;
	}

	/**
	 * Creates a collection from the data field of an API response entry.
	 *
	 * Plugin items without a valid slug are left out. A slug listed more than once is kept once.
	 *
	 * @param mixed $data The data field of an entry with success true.
	 * @return self|null Null when the data has no valid key.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['key'] ) || ! Keys::is_valid( $data['key'] ) ) {
			return null;
		}

		$plugins = array();

		if ( isset( $data['plugins'] ) && is_array( $data['plugins'] ) ) {
			foreach ( $data['plugins'] as $item ) {
				$plugin = CollectionPlugin::from_array( $item );

				if ( null !== $plugin && ! isset( $plugins[ $plugin->slug() ] ) ) {
					$plugins[ $plugin->slug() ] = $plugin;
				}
			}
		}

		$name = isset( $data['name'] ) && is_string( $data['name'] ) && '' !== trim( $data['name'] ) ? trim( $data['name'] ) : $data['key'];

		return new self( $data['key'], $name, array_values( $plugins ) );
	}

	/**
	 * Returns the collection key.
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Returns the collection title.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the plugins in the collection.
	 *
	 * @return CollectionPlugin[]
	 */
	public function plugins(): array {
		return $this->plugins;
	}

	/**
	 * Returns the collection page URL on wpcore.com.
	 */
	public function url(): string {
		return self::PAGE_URL . rawurlencode( $this->key );
	}

	/**
	 * Returns the collection in the API data format, for caching.
	 *
	 * @return array{key: string, name: string, plugins: array<int, array<string, string|null>>}
	 */
	public function to_array(): array {
		return array(
			'key'     => $this->key,
			'name'    => $this->name,
			'plugins' => array_map(
				static function ( CollectionPlugin $plugin ): array {
					return $plugin->to_array();
				},
				$this->plugins
			),
		);
	}
}
