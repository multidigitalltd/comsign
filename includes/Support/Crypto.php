<?php
/**
 * Small symmetric-encryption helper for secrets at rest.
 *
 * @package ComSign
 */

namespace ComSign\Support;

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-CBC encryption with a key derived from the site's auth salt (which
 * lives in wp-config.php, not the database). A database dump alone therefore
 * cannot reveal the plaintext. Used for billing tokens and gateway credentials.
 */
final class Crypto {

	/**
	 * Whether the OpenSSL primitives we need are available.
	 */
	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Encrypt a string, returning base64( iv . ciphertext ), or '' on failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! self::available() ) {
			return '';
		}
		$iv = random_bytes( 16 );
		$ct = openssl_encrypt( $plaintext, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $ct ) {
			return '';
		}
		return base64_encode( $iv . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Reverse {@see encrypt()}. Returns '' on failure.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || ! self::available() ) {
			return '';
		}
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}
		$iv = substr( $raw, 0, 16 );
		$ct = substr( $raw, 16 );
		$pt = openssl_decrypt( $ct, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $pt ? '' : $pt;
	}

	/**
	 * 256-bit key derived from the site auth salt.
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}
}
