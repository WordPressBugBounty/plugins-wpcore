<?php
/**
 * Result of loading the saved collections.
 *
 * @package WPCore
 */

namespace WPCore\Collections;

use WP_Error;
use WPCore\Api\Collection;

/**
 * The collections found for the saved keys, the keys that matched no collection, and the API error, if any.
 */
final class Result {

	/**
	 * Found collections keyed by saved key, in saved key order.
	 *
	 * @var array<string, Collection>
	 */
	private $collections;

	/**
	 * Saved keys that matched no collection.
	 *
	 * @var string[]
	 */
	private $not_found_keys;

	/**
	 * API error for the keys that were not in the cache. Null when the request succeeded or was not needed.
	 *
	 * @var WP_Error|null
	 */
	private $error;

	/**
	 * Creates a result.
	 *
	 * @param array<string, Collection> $collections    Found collections keyed by saved key.
	 * @param string[]                  $not_found_keys Saved keys that matched no collection.
	 * @param WP_Error|null             $error          API error, if any.
	 */
	public function __construct( array $collections, array $not_found_keys, ?WP_Error $error = null ) {
		$this->collections    = $collections;
		$this->not_found_keys = $not_found_keys;
		$this->error          = $error;
	}

	/**
	 * Returns the found collections keyed by saved key, in saved key order.
	 *
	 * @return array<string, Collection>
	 */
	public function collections(): array {
		return $this->collections;
	}

	/**
	 * Returns the saved keys that matched no collection.
	 *
	 * @return string[]
	 */
	public function not_found_keys(): array {
		return $this->not_found_keys;
	}

	/**
	 * Returns the API error, or null. Keys that were not cached and not loaded because of the error are in neither list.
	 */
	public function error(): ?WP_Error {
		return $this->error;
	}
}
