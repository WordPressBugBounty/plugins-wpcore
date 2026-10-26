<?php
/**
 * Collection key validation.
 *
 * @package WPCore
 */

namespace WPCore\Collections;

/**
 * Validates and cleans collection keys.
 *
 * The wpcore.com app creates keys with 20 random letters and digits. The pattern also allows
 * hyphens and underscores, and never commas, because the API separates keys with commas.
 */
final class Keys {

	/**
	 * Pattern that a valid key matches.
	 */
	const PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

	/**
	 * Checks that a value is a valid collection key.
	 *
	 * @param mixed $key Value to check.
	 */
	public static function is_valid( $key ): bool {
		return is_string( $key ) && 1 === preg_match( self::PATTERN, $key );
	}

	/**
	 * Returns the valid keys from a stored value, trimmed, without repeats, in their original order.
	 *
	 * Keys that differ only in letter case count as repeats. The wpcore.com database compares keys without case.
	 *
	 * @param mixed $value Stored value, such as the wpcore_keys option.
	 * @return string[]
	 */
	public static function normalize( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$keys = array();
		$seen = array();

		foreach ( $value as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$key = trim( $key );
			$id  = strtolower( $key );

			if ( ! self::is_valid( $key ) || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$keys[]      = $key;
		}

		return $keys;
	}
}
