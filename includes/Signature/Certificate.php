<?php
/**
 * PKI certificate management for PAdES signing.
 *
 * @package ComSign
 */

namespace ComSign\Signature;

defined( 'ABSPATH' ) || exit;

use ComSign\Support\Storage;

/**
 * Stores and reads the PKCS#12 certificate used for cryptographic (PAdES)
 * signing. The certificate lives in the protected uploads directory; its
 * password is kept in a dedicated option.
 */
final class Certificate {

	private const OPTION = 'comsign_pki';

	/**
	 * Absolute path to the stored PKCS#12 file.
	 */
	/** Maximum accepted PKCS#12 size, in bytes. */
	private const MAX_BYTES = 512 * 1024;

	/**
	 * Absolute path to the stored PKCS#12 file (randomised, not guessable).
	 *
	 * Falls back to the legacy fixed name so existing installs keep working.
	 */
	public static function path(): string {
		$opt = get_option( self::OPTION );
		if ( is_array( $opt ) && ! empty( $opt['path'] ) ) {
			return (string) $opt['path'];
		}
		return trailingslashit( Storage::base_dir() ) . 'pki-cert.p12';
	}

	/**
	 * Whether OpenSSL is available (required for PAdES).
	 */
	public static function openssl_available(): bool {
		return function_exists( 'openssl_pkcs12_read' ) && function_exists( 'openssl_encrypt' );
	}

	/**
	 * Whether a usable certificate is configured.
	 */
	public static function is_configured(): bool {
		$opt = get_option( self::OPTION );
		return self::openssl_available() && is_array( $opt ) && ! empty( $opt['password'] ) && is_file( self::path() );
	}

	/**
	 * Validate and store an uploaded PKCS#12 certificate.
	 *
	 * @param string $tmp_file Path to the uploaded temp file.
	 * @param string $password Certificate password.
	 *
	 * @throws \RuntimeException If OpenSSL is missing or the cert is invalid.
	 */
	public static function store( string $tmp_file, string $password ): void {
		if ( ! self::openssl_available() ) {
			throw new \RuntimeException( __( 'OpenSSL is not available on this server, so PKI signing cannot be enabled.', 'comsign' ) );
		}

		// Guard the size before reading the whole file into memory.
		if ( ! is_readable( $tmp_file ) || filesize( $tmp_file ) > self::MAX_BYTES ) {
			throw new \RuntimeException( __( 'The certificate file is missing or too large (max 512 KB).', 'comsign' ) );
		}

		$data  = file_get_contents( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$certs = array();
		if ( ! is_string( $data ) || '' === $data || ! openssl_pkcs12_read( $data, $certs, $password ) ) {
			throw new \RuntimeException( __( 'The certificate could not be read. Check the file and password.', 'comsign' ) );
		}

		// Remove any previously stored certificate file first.
		self::remove();

		Storage::ensure_protected_dir();
		$dest = trailingslashit( Storage::base_dir() ) . 'pki-' . wp_generate_password( 24, false ) . '.p12';
		if ( ! @move_uploaded_file( $tmp_file, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// Fallback for non-HTTP-upload contexts (e.g. tests).
			file_put_contents( $dest, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		@chmod( $dest, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		// Store the password encrypted (never plaintext) and keep the option out
		// of the autoloaded set so it is not loaded on every request. remove()
		// above deleted any prior option, so this creates it fresh with
		// autoload = 'no'.
		update_option(
			self::OPTION,
			array(
				'path'     => $dest,
				'password' => self::encrypt( $password ),
				'subject'  => self::subject_from( $certs ),
			),
			false
		);
	}

	/**
	 * Read the certificate + private key as PEM strings for signing.
	 *
	 * @return array{cert:string,pkey:string}|null
	 */
	public static function read(): ?array {
		if ( ! self::is_configured() ) {
			return null;
		}

		$opt      = get_option( self::OPTION );
		$password = self::decrypt( (string) $opt['password'] );
		$path     = self::path();
		$data     = is_readable( $path ) ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$certs    = array();
		if ( ! is_string( $data ) || '' === $data || ! openssl_pkcs12_read( $data, $certs, $password ) ) {
			return null;
		}

		return array(
			'cert' => (string) ( $certs['cert'] ?? '' ),
			'pkey' => (string) ( $certs['pkey'] ?? '' ),
		);
	}

	/**
	 * Human-readable subject of the stored certificate (for the settings UI).
	 */
	public static function subject(): string {
		$opt = get_option( self::OPTION );
		return is_array( $opt ) ? (string) ( $opt['subject'] ?? '' ) : '';
	}

	/**
	 * Remove the stored certificate and its option.
	 */
	public static function remove(): void {
		$file = self::path();
		if ( is_file( $file ) && Storage::is_within_base( $file ) ) {
			wp_delete_file( $file );
		}
		delete_option( self::OPTION );
	}

	/**
	 * Encrypt a secret with a key derived from the site's auth salt (which lives
	 * in wp-config.php, not the database), so a DB dump alone cannot reveal it.
	 *
	 * @param string $plaintext Secret.
	 */
	private static function encrypt( string $plaintext ): string {
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 16 );
		$ct  = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ct ) {
			return '';
		}
		return base64_encode( $iv . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Reverse {@see encrypt()}.
	 *
	 * @param string $stored Stored value.
	 */
	private static function decrypt( string $stored ): string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = substr( $raw, 0, 16 );
		$ct  = substr( $raw, 16 );
		$pt  = openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $pt ? '' : $pt;
	}

	/**
	 * Extract a readable subject string from a parsed PKCS#12.
	 *
	 * @param array $certs Result of openssl_pkcs12_read().
	 */
	private static function subject_from( array $certs ): string {
		if ( empty( $certs['cert'] ) ) {
			return '';
		}
		$parsed = openssl_x509_parse( $certs['cert'] );
		if ( ! is_array( $parsed ) ) {
			return '';
		}
		$cn = $parsed['subject']['CN'] ?? '';
		$o  = $parsed['subject']['O'] ?? '';
		return trim( $cn . ( $o ? ' (' . $o . ')' : '' ) );
	}
}
