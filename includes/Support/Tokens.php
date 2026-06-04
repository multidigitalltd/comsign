<?php
/**
 * Secure signer-token helpers.
 *
 * @package ComSign
 */

namespace ComSign\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and verifies the per-signer access tokens.
 *
 * The raw token is sent to the signer (in the email link) and never stored.
 * Only a SHA-256 hash is persisted, so a database leak does not expose live
 * signing links. Verification is constant-time.
 */
final class Tokens {

	/**
	 * Generate a new random raw token.
	 *
	 * @return string URL-safe, 64-hex-character token.
	 */
	public static function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Hash a raw token for storage / lookup.
	 *
	 * @param string $raw Raw token.
	 */
	public static function hash( string $raw ): string {
		return hash( 'sha256', $raw );
	}

	/**
	 * Constant-time comparison of a raw token against a stored hash.
	 *
	 * @param string $raw    Raw token from the request.
	 * @param string $stored Stored hash.
	 */
	public static function verify( string $raw, string $stored ): bool {
		return hash_equals( $stored, self::hash( $raw ) );
	}
}
