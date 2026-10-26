<?php
/**
 * A plugin listed in a collection.
 *
 * @package WPCore
 */

namespace WPCore\Api;

/**
 * One entry in the plugins list of an API collection.
 *
 * A plugin from the WordPress.org directory has no source. A custom plugin has a source, the URL of its ZIP file.
 */
final class CollectionPlugin {

	/**
	 * Pattern that a valid plugin slug matches.
	 */
	const SLUG_PATTERN = '/^[a-z0-9_-]+$/';

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Plugin name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * ZIP file URL of a custom plugin. Null for a WordPress.org plugin.
	 *
	 * @var string|null
	 */
	private $source;

	/**
	 * Home page URL of a custom plugin, if wpcore.com has one.
	 *
	 * @var string|null
	 */
	private $external_url;

	/**
	 * Creates a plugin entry.
	 *
	 * @param string      $slug         Plugin slug.
	 * @param string      $name         Plugin name.
	 * @param string|null $source       ZIP file URL of a custom plugin.
	 * @param string|null $external_url Home page URL of a custom plugin.
	 */
	public function __construct( string $slug, string $name, ?string $source = null, ?string $external_url = null ) {
		$this->slug         = $slug;
		$this->name         = $name;
		$this->source       = $source;
		$this->external_url = $external_url;
	}

	/**
	 * Creates a plugin entry from one item of an API plugins list.
	 *
	 * @param mixed $data Item from data.plugins in the API response.
	 * @return self|null Null when the item has no valid slug.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['slug'] ) || ! is_string( $data['slug'] ) ) {
			return null;
		}

		$slug = strtolower( trim( $data['slug'] ) );

		if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
			return null;
		}

		return new self(
			$slug,
			self::string_or_null( $data, 'name' ) ?? $slug,
			self::string_or_null( $data, 'source' ),
			self::string_or_null( $data, 'external_url' )
		);
	}

	/**
	 * Returns the plugin slug.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Returns the plugin name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the ZIP file URL of a custom plugin, or null for a WordPress.org plugin.
	 */
	public function source(): ?string {
		return $this->source;
	}

	/**
	 * Returns the home page URL of a custom plugin, or null.
	 */
	public function external_url(): ?string {
		return $this->external_url;
	}

	/**
	 * Checks whether the plugin installs from the WordPress.org directory.
	 */
	public function is_from_wordpress_org(): bool {
		return null === $this->source;
	}

	/**
	 * Returns the entry in the API array format, for caching.
	 *
	 * @return array{slug: string, name: string, source: string|null, external_url: string|null}
	 */
	public function to_array(): array {
		return array(
			'slug'         => $this->slug,
			'name'         => $this->name,
			'source'       => $this->source,
			'external_url' => $this->external_url,
		);
	}

	/**
	 * Returns a trimmed, non-empty string field, or null.
	 *
	 * @param array<string, mixed> $data  API item.
	 * @param string               $field Field name.
	 */
	private static function string_or_null( array $data, string $field ): ?string {
		if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
			return null;
		}

		$value = trim( $data[ $field ] );

		return '' === $value ? null : $value;
	}
}
