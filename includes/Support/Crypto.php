<?php
/**
 * Small symmetric-encryption helper for secrets at rest.
 *
 * @package ComSign
 */

namespace ComSign\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated symmetric encryption for secrets at rest (billing tokens and
 * gateway credentials). The key is derived from the site's auth salt, which
 * lives in wp-config.php rather than the database, so a database dump alone
 * cannot reveal the plaintext.
 *
 * Ciphertext is authenticated so tampering/corruption is detected before
 * decryption:
 *   - "g1:" — AES-256-GCM (preferred; AEAD), base64( iv(12) . tag(16) . ct ).
 *   - "c1:" — AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC), base64( iv(16) .
 *             hmac(32) . ct ), verified with hash_equals() before decrypt.
 * Values written by older versions (raw base64 of iv . ct, AES-256-CBC, no MAC)
 * are still readable so stored tokens keep working across the upgrade.
 */
final class Crypto {

	private const GCM_PREFIX = 'g1:';
	private const CBC_PREFIX = 'c1:';

	/**
	 * Whether the OpenSSL primitives we need are available.
	 */
	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Whether authenticated AES-256-GCM is available on this build.
	 */
	private static function gcm_available(): bool {
		return self::available()
			&& function_exists( 'openssl_get_cipher_methods' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	/**
	 * Encrypt a string, returning an authenticated, prefixed token, or '' on
	 * failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! self::available() ) {
			return '';
		}

		if ( self::gcm_available() ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', self::enc_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false === $ct ) {
				return '';
			}
			return self::GCM_PREFIX . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Fallback: AES-256-CBC with an encrypt-then-MAC HMAC.
		$iv = random_bytes( 16 );
		$ct = openssl_encrypt( $plaintext, 'aes-256-cbc', self::enc_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $ct ) {
			return '';
		}
		$mac = hash_hmac( 'sha256', $iv . $ct, self::mac_key(), true );
		return self::CBC_PREFIX . base64_encode( $iv . $mac . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Reverse {@see encrypt()}. Returns '' on failure or when authentication
	 * fails (tampered/corrupted ciphertext).
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored || ! self::available() ) {
			return '';
		}

		if ( 0 === strpos( $stored, self::GCM_PREFIX ) ) {
			return self::decrypt_gcm( substr( $stored, strlen( self::GCM_PREFIX ) ) );
		}
		if ( 0 === strpos( $stored, self::CBC_PREFIX ) ) {
			return self::decrypt_cbc_hmac( substr( $stored, strlen( self::CBC_PREFIX ) ) );
		}

		// Legacy (pre-authentication) format: raw base64 of iv(16) . ct.
		return self::decrypt_legacy( $stored );
	}

	/**
	 * Decrypt an authenticated AES-256-GCM token.
	 */
	private static function decrypt_gcm( string $b64 ): string {
		$raw = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= 28 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ct  = substr( $raw, 28 );
		$pt  = openssl_decrypt( $ct, 'aes-256-gcm', self::enc_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $pt ? '' : $pt;
	}

	/**
	 * Decrypt an AES-256-CBC + HMAC token, verifying the MAC first.
	 */
	private static function decrypt_cbc_hmac( string $b64 ): string {
		$raw = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= 48 ) {
			return '';
		}
		$iv       = substr( $raw, 0, 16 );
		$mac      = substr( $raw, 16, 32 );
		$ct       = substr( $raw, 48 );
		$expected = hash_hmac( 'sha256', $iv . $ct, self::mac_key(), true );
		if ( ! hash_equals( $expected, $mac ) ) {
			return '';
		}
		$pt = openssl_decrypt( $ct, 'aes-256-cbc', self::enc_key(), OPENSSL_RAW_DATA, $iv );
		return false === $pt ? '' : $pt;
	}

	/**
	 * Decrypt a legacy (unauthenticated) AES-256-CBC token.
	 */
	private static function decrypt_legacy( string $stored ): string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}
		$iv = substr( $raw, 0, 16 );
		$ct = substr( $raw, 16 );
		$pt = openssl_decrypt( $ct, 'aes-256-cbc', self::enc_key(), OPENSSL_RAW_DATA, $iv );
		return false === $pt ? '' : $pt;
	}

	/**
	 * 256-bit encryption key derived from the site auth salt.
	 */
	private static function enc_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/**
	 * 256-bit MAC key, domain-separated from the encryption key.
	 */
	private static function mac_key(): string {
		return hash( 'sha256', 'comsign-mac|' . wp_salt( 'auth' ), true );
	}
}
