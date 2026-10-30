<?php
/**
 * Site report signatures.
 *
 * @package WPCore
 */

namespace WPCore\Sites;

/**
 * Signs and checks site report requests and responses.
 *
 * The signature is the hex HMAC-SHA256 of "<timestamp>.<body>" with the site secret as the key.
 * App\Modules\Sites\Signature in stueynet/wpcore2026 uses the same scheme.
 */
final class Signature {

	/**
	 * Header that holds the Unix timestamp of the signature.
	 */
	const TIMESTAMP_HEADER = 'X-WPCore-Timestamp';

	/**
	 * Header that holds the signature.
	 */
	const SIGNATURE_HEADER = 'X-WPCore-Signature';

	/**
	 * A signature older or newer than this many seconds is rejected.
	 */
	const TOLERANCE_SECONDS = 300;

	/**
	 * Returns the signature of a body.
	 *
	 * @param string $secret    Site secret.
	 * @param int    $timestamp Unix timestamp.
	 * @param string $body      Request or response body.
	 */
	public static function sign( string $secret, int $timestamp, string $body ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Returns the timestamp and signature headers for a body, signed now.
	 *
	 * @param string $secret Site secret.
	 * @param string $body   Request or response body.
	 * @return array<string, string>
	 */
	public static function headers( string $secret, string $body ): array {
		$timestamp = time();

		return array(
			self::TIMESTAMP_HEADER => (string) $timestamp,
			self::SIGNATURE_HEADER => self::sign( $secret, $timestamp, $body ),
		);
	}

	/**
	 * Returns true when the signature matches the body and the timestamp is within the tolerance.
	 *
	 * @param string      $secret    Site secret.
	 * @param string|null $timestamp Timestamp header value.
	 * @param string|null $signature Signature header value.
	 * @param string      $body      Request or response body.
	 */
	public static function verify( string $secret, $timestamp, $signature, string $body ): bool {
		if ( ! is_string( $timestamp ) || ! is_string( $signature ) || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::TOLERANCE_SECONDS ) {
			return false;
		}

		return hash_equals( self::sign( $secret, (int) $timestamp, $body ), $signature );
	}
}
